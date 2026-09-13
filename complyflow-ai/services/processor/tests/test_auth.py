import time
import base64
import json
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor

import pytest

from conftest import signed_headers


def test_rejects_unsigned_analysis(client):
    assert client.post("/v1/analyze", json={}).status_code == 401


@pytest.mark.parametrize("missing", ["X-CF-Timestamp", "X-CF-Nonce", "X-CF-Signature"])
def test_rejects_missing_authentication_header(client, missing):
    headers = signed_headers(b"{}")
    headers.pop(missing)
    assert client.post("/v1/analyze", content=b"{}", headers=headers).status_code == 401


def test_rejects_tampered_signature(client):
    headers = signed_headers(b"{}")
    headers["X-CF-Signature"] = "0" * 64
    assert client.post("/v1/analyze", content=b"{}", headers=headers).status_code == 401


def test_signature_covers_raw_body_bytes(client):
    headers = signed_headers(b"{}")
    assert client.post("/v1/analyze", content=b"{ }", headers=headers).status_code == 401


@pytest.mark.parametrize("offset", [-61, 61])
def test_rejects_expired_or_future_timestamp(client, offset):
    headers = signed_headers(b"{}", timestamp=int(time.time()) + offset)
    assert client.post("/v1/analyze", content=b"{}", headers=headers).status_code == 401


@pytest.mark.parametrize("timestamp", ["nan", "1.0", "-1", "9" * 1000])
def test_rejects_malformed_timestamp(client, timestamp):
    headers = signed_headers(b"{}", timestamp=timestamp)
    assert client.post("/v1/analyze", content=b"{}", headers=headers).status_code == 401


def test_rejects_reused_nonce(client):
    headers = signed_headers(b"{}")
    first = client.post("/v1/analyze", content=b"{}", headers=headers)
    assert first.status_code == 422
    assert client.post("/v1/analyze", content=b"{}", headers=headers).status_code == 401


def test_invalid_signature_does_not_consume_nonce(client):
    headers = signed_headers(b"{}")
    invalid = headers | {"X-CF-Signature": "0" * 64}
    assert client.post("/v1/analyze", content=b"{}", headers=invalid).status_code == 401
    assert client.post("/v1/analyze", content=b"{}", headers=headers).status_code == 422


def test_authentication_precedes_json_parsing(client):
    assert client.post("/v1/analyze", content=b"not json").status_code == 401


def test_missing_secret_fails_closed(client, monkeypatch):
    monkeypatch.delenv("PROCESSOR_HMAC_SECRET")
    response = client.post("/v1/analyze", content=b"{}", headers=signed_headers(b"{}"))
    assert response.status_code == 503


@pytest.mark.parametrize("header,value", [
    ("X-CF-Nonce", ""),
    ("X-CF-Nonce", "x" * 129),
    ("X-CF-Signature", "é"),
    ("X-CF-Signature", "xyz"),
])
def test_rejects_malformed_headers(client, header, value):
    headers = signed_headers(b"{}")
    headers[header] = value
    # Latin-1 bytes permit exercising an untrusted, non-ASCII header.
    raw_headers = [(k.encode(), v.encode("latin-1")) for k, v in headers.items()]
    assert client.post("/v1/analyze", content=b"{}", headers=raw_headers).status_code == 401


@pytest.mark.parametrize("offset,expected", [(-60, 422), (60, 422), (-61, 401), (61, 401)])
def test_clock_window_has_inclusive_sixty_second_boundary(client, monkeypatch, offset, expected):
    from app.security import hmac_auth

    monkeypatch.setattr(hmac_auth, "wall_clock", lambda: 1_800_000_000)
    headers = signed_headers(b"{}", timestamp=1_800_000_000 + offset)
    assert client.post("/v1/analyze", content=b"{}", headers=headers).status_code == expected


@pytest.mark.parametrize("elapsed", [119.5, 120.0, 120.5])
def test_replay_from_earliest_valid_instant_is_rejected_at_expiry_boundary(client, monkeypatch, elapsed):
    from app.security import hmac_auth

    timestamp = 1_800_000_000
    clock = {"wall": timestamp - 60.0, "monotonic": 100.0}
    monkeypatch.setattr(hmac_auth, "wall_clock", lambda: clock["wall"])
    monkeypatch.setattr(hmac_auth, "monotonic", lambda: clock["monotonic"])
    monkeypatch.setattr(hmac_auth, "nonce_cache", hmac_auth.NonceCache())
    headers = signed_headers(b"{}", timestamp=timestamp)
    # 422 confirms authentication succeeded at the first acceptable instant.
    assert client.post("/v1/analyze", content=b"{}", headers=headers).status_code == 422

    clock.update(wall=timestamp - 60.0 + elapsed, monotonic=100.0 + elapsed)
    replay = client.post("/v1/analyze", content=b"{}", headers=headers)
    assert replay.status_code == 401
    assert replay.json() == {"detail": "invalid_authentication"}


@pytest.mark.parametrize("offset,expected", [
    (-60.5, 401), (-60.0, 422), (-59.5, 422),
    (59.5, 422), (60.0, 422), (60.5, 401),
])
def test_clock_window_preserves_subsecond_precision(client, monkeypatch, offset, expected):
    from app.security import hmac_auth

    timestamp = 1_800_000_000
    monkeypatch.setattr(hmac_auth, "wall_clock", lambda: timestamp + offset)
    headers = signed_headers(b"{}", timestamp=timestamp)
    assert client.post("/v1/analyze", content=b"{}", headers=headers).status_code == expected


def test_nonce_is_retained_for_120_seconds_then_cleaned():
    from app.security.hmac_auth import NonceCache

    cache = NonceCache(capacity=2)
    assert cache.claim("one", now=100) == "accepted"
    assert cache.claim("one", now=219.99) == "replay"
    assert cache.claim("one", now=220) == "replay"
    assert cache.claim("one", now=220.001) == "accepted"


def test_full_cache_fails_closed_without_evicting_live_nonce():
    from app.security.hmac_auth import NonceCache

    cache = NonceCache(capacity=2)
    assert cache.claim("one", now=100) == "accepted"
    assert cache.claim("two", now=101) == "accepted"
    assert cache.claim("three", now=102) == "full"
    assert cache.claim("one", now=103) == "replay"
    assert cache.claim("three", now=220) == "full"
    assert cache.claim("three", now=220.001) == "accepted"
    assert cache.claim("two", now=220.001) == "replay"


def test_concurrent_nonce_claim_is_atomic():
    from app.security.hmac_auth import NonceCache

    cache = NonceCache(capacity=10)
    with ThreadPoolExecutor(max_workers=8) as pool:
        results = list(pool.map(lambda _: cache.claim("same-nonce", now=100), range(16)))
    assert results.count("accepted") == 1
    assert results.count("replay") == 15


def test_duplicate_auth_header_is_rejected(client):
    headers = list(signed_headers(b"{}").items())
    headers.append(("X-CF-Nonce", "another-nonce"))
    assert client.post("/v1/analyze", content=b"{}", headers=headers).status_code == 401


def test_shared_signature_vector_matches_raw_bytes_and_authenticates(client, monkeypatch):
    from app.security import hmac_auth

    vector = json.loads((Path(__file__).parents[1] / "openapi/hmac-test-vector.json").read_text())
    body = base64.b64decode(vector["body_base64"])
    assert body == vector["body_utf8"].encode("utf-8")
    headers = signed_headers(
        body, timestamp=vector["timestamp"], nonce=vector["nonce"], secret=vector["test_secret"],
    )
    assert headers["X-CF-Signature"] == vector["expected_signature"]
    monkeypatch.setenv("PROCESSOR_HMAC_SECRET", vector["test_secret"])
    monkeypatch.setattr(hmac_auth, "wall_clock", lambda: int(vector["timestamp"]))
    response = client.post("/v1/analyze", content=body, headers=headers)
    assert response.status_code == 503
    assert response.json()["detail"] == "analysis_not_available"


def test_full_nonce_cache_returns_unavailable_and_preserves_replay_rejection(client, monkeypatch):
    from app.security import hmac_auth

    monkeypatch.setattr(hmac_auth, "nonce_cache", hmac_auth.NonceCache(capacity=1))
    first = signed_headers(b"{}")
    assert client.post("/v1/analyze", content=b"{}", headers=first).status_code == 422
    response = client.post("/v1/analyze", content=b"{}", headers=signed_headers(b"{}"))
    assert response.status_code == 503
    assert response.json() == {"detail": "authentication_unavailable"}
    assert client.post("/v1/analyze", content=b"{}", headers=first).status_code == 401
