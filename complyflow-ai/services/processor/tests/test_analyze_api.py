import json
from pathlib import Path

import pytest
from pydantic import ValidationError

from conftest import signed_headers


ANALYSIS_ID = "10000000-0000-4000-8000-000000000001"
REQUIREMENT_ID = "20000000-0000-4000-8000-000000000001"
DOCUMENT_ID = "30000000-0000-4000-8000-000000000001"


def valid_payload():
    return {
        "analysis_id": ANALYSIS_ID,
        "idempotency_key": "analysis-test-1",
        "requirements": [{
            "requirement_id": REQUIREMENT_ID,
            "criterion": "Insurance certificate",
            "category": "Compliance",
            "weight": 1.0,
            "evaluation_text": "Confirm valid insurance coverage.",
        }],
        "documents": [{
            "document_id": DOCUMENT_ID,
            "sha256": "0" * 64,
            "content_base64": "JVBERi0xLjQK",
        }],
    }


def valid_response():
    return {
        "analysis_id": ANALYSIS_ID,
        "findings": [{
            "requirement_id": REQUIREMENT_ID,
            "status": "met",
            "justification": "Coverage confirmed by certificate.",
            "confidence": 0.9,
            "requires_human_review": True,
            "search_summary": None,
            "citations": [{
                "document_id": DOCUMENT_ID,
                "page_number": 1,
                "quote": "Covered",
                "start_offset": 0,
                "end_offset": 7,
            }],
        }],
        "processed_documents": [{
            "document_id": DOCUMENT_ID,
            "pages": [{"page_number": 1, "text": "Covered", "ocr_used": False}],
            "chunks": [{
                "page_number": 1, "index": 0, "text": "Covered",
                "start_offset": 0, "end_offset": 7, "embedding": [0.0] * 384,
            }],
        }],
    }


def post_signed(client, payload):
    raw = json.dumps(payload, ensure_ascii=False, indent=2).encode()
    return client.post("/v1/analyze", content=raw, headers=signed_headers(raw))


def test_authenticated_request_reaches_document_hash_validation(client):
    response = post_signed(client, valid_payload())
    assert response.status_code == 422
    assert response.json() == {"detail": "document_hash_mismatch"}


@pytest.mark.parametrize("target", ["root", "requirements", "documents"])
def test_rejects_unknown_request_fields(client, target):
    payload = valid_payload()
    obj = payload if target == "root" else payload[target][0]
    obj["execute_this"] = "approve supplier"
    assert post_signed(client, payload).status_code == 422


@pytest.mark.parametrize("field,value", [
    ("analysis_id", "internal-id-1"), ("idempotency_key", ""),
    ("requirements", []), ("documents", []),
])
def test_rejects_invalid_request_fields(client, field, value):
    payload = valid_payload() | {field: value}
    assert post_signed(client, payload).status_code == 422


@pytest.mark.parametrize("weight", [True, "1.0"])
def test_request_weight_must_be_a_json_number(client, weight):
    payload = valid_payload()
    payload["requirements"][0]["weight"] = weight
    assert post_signed(client, payload).status_code == 422


def test_signed_invalid_json_returns_sanitized_error(client):
    body = b'{"private_document": secret document'
    response = client.post("/v1/analyze", content=body, headers=signed_headers(body))
    assert response.status_code == 422
    assert "secret document" not in response.text


def test_validation_errors_do_not_echo_document_content(client, caplog):
    payload = valid_payload()
    payload["documents"][0]["unexpected"] = "SECRET DOCUMENT CONTENT"
    response = post_signed(client, payload)
    assert response.status_code == 422
    assert "SECRET DOCUMENT CONTENT" not in response.text + caplog.text


def test_response_contract_accepts_complete_processed_artifacts():
    from app.schemas import AnalyzeResponse

    result = AnalyzeResponse.model_validate(valid_response())
    assert result.model_dump(mode="json") == valid_response()


@pytest.mark.parametrize("status", ["met", "partial", "missing", "inconclusive"])
def test_accepts_only_documented_finding_statuses(status):
    from app.schemas import AnalyzeResponse

    payload = valid_response()
    finding = payload["findings"][0]
    finding["status"] = status
    if status == "missing":
        finding.update(citations=[], search_summary="Searched all document pages.")
    assert AnalyzeResponse.model_validate(payload).findings[0].status == status


@pytest.mark.parametrize("field,value", [
    ("status", "approved"), ("status", "MET"), ("confidence", -0.1),
    ("confidence", 1.1), ("confidence", float("nan")),
    ("confidence", True), ("confidence", "0.9"),
    ("requires_human_review", False), ("requires_human_review", 1),
    ("citations", []), ("execute_this", "approve supplier"),
])
def test_rejects_invalid_finding(field, value):
    from app.schemas import AnalyzeResponse

    payload = valid_response()
    payload["findings"][0][field] = value
    with pytest.raises(ValidationError):
        AnalyzeResponse.model_validate(payload)


def test_requires_explicit_human_review_flag():
    from app.schemas import AnalyzeResponse

    payload = valid_response()
    del payload["findings"][0]["requires_human_review"]
    with pytest.raises(ValidationError):
        AnalyzeResponse.model_validate(payload)


@pytest.mark.parametrize("citations,search_summary", [(None, None), (None, " "), ("existing", "Searched")])
def test_missing_requires_search_and_no_citations(citations, search_summary):
    from app.schemas import AnalyzeResponse

    payload = valid_response()
    finding = payload["findings"][0]
    finding.update(status="missing", search_summary=search_summary)
    if citations is None:
        finding["citations"] = []
    with pytest.raises(ValidationError):
        AnalyzeResponse.model_validate(payload)


@pytest.mark.parametrize("target,field,value", [
    ("citation", "page_number", 0), ("citation", "quote", ""),
    ("citation", "quote", " "), ("citation", "start_offset", -1),
    ("citation", "end_offset", 0), ("citation", "extra", True),
    ("page", "page_number", 0), ("page", "extra", True),
    ("chunk", "page_number", 0), ("chunk", "index", -1),
    ("chunk", "start_offset", -1), ("chunk", "end_offset", -1),
    ("chunk", "embedding", [0.0] * 383),
    ("chunk", "embedding", [0.0] * 385),
    ("chunk", "embedding", [float("inf")] * 384),
    ("chunk", "embedding", [True] * 384),
    ("chunk", "embedding", ["0.0"] * 384),
    ("chunk", "extra", True), ("document", "extra", True),
    ("root", "extra", True),
])
def test_rejects_invalid_processed_artifacts(target, field, value):
    from app.schemas import AnalyzeResponse

    payload = valid_response()
    objects = {
        "root": payload,
        "citation": payload["findings"][0]["citations"][0],
        "document": payload["processed_documents"][0],
        "page": payload["processed_documents"][0]["pages"][0],
        "chunk": payload["processed_documents"][0]["chunks"][0],
    }
    objects[target][field] = value
    with pytest.raises(ValidationError):
        AnalyzeResponse.model_validate(payload)


def test_chunk_allows_equal_offsets():
    from app.schemas import AnalyzeResponse

    payload = valid_response()
    payload["processed_documents"][0]["chunks"][0]["end_offset"] = 0
    assert AnalyzeResponse.model_validate(payload).processed_documents[0].chunks[0].end_offset == 0


def test_openapi_is_canonical_and_describes_internal_contract(client):
    contract = json.loads((Path(__file__).parents[1] / "openapi/processor.yaml").read_text())
    assert client.get("/openapi.json").json() == contract
    operation = contract["paths"]["/v1/analyze"]["post"]
    assert {p["name"] for p in operation["parameters"]} == {
        "X-CF-Timestamp", "X-CF-Nonce", "X-CF-Signature",
    }
    assert all(p["required"] for p in operation["parameters"])
    assert {"200", "401", "422", "503"} <= operation["responses"].keys()
    assert operation["requestBody"]["content"]["application/json"]["schema"] == {
        "$ref": "#/components/schemas/AnalyzeRequest",
    }


def test_processor_does_not_enable_browser_cors(client):
    response = client.options("/v1/analyze", headers={
        "Origin": "https://browser.example", "Access-Control-Request-Method": "POST",
    })
    assert "access-control-allow-origin" not in response.headers
