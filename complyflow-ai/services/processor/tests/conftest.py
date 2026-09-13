import hashlib
import hmac
import time
import uuid

import pytest
from fastapi.testclient import TestClient

from app.main import app


TEST_SECRET = "processor-contract-test-secret-not-for-production"


def signed_headers(body: bytes, *, timestamp=None, nonce=None, secret=TEST_SECRET):
    timestamp = str(int(time.time())) if timestamp is None else str(timestamp)
    nonce = str(uuid.uuid4()) if nonce is None else nonce
    message = f"{timestamp}\n{nonce}\n{hashlib.sha256(body).hexdigest()}".encode()
    signature = hmac.new(secret.encode(), message, hashlib.sha256).hexdigest()
    return {
        "Content-Type": "application/json",
        "X-CF-Timestamp": timestamp,
        "X-CF-Nonce": nonce,
        "X-CF-Signature": signature,
    }


@pytest.fixture
def client(monkeypatch):
    monkeypatch.setenv("PROCESSOR_HMAC_SECRET", TEST_SECRET)
    with TestClient(app) as client:
        yield client
