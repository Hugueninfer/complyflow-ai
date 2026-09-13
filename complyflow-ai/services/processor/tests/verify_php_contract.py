"""Cross-language check: consume Laravel's actual outgoing bytes/headers on stdin."""

import base64
import hashlib
import json
import os
from pathlib import Path
import sys
from unittest.mock import patch

from fastapi.testclient import TestClient

from app.main import app
from app.schemas import AnalyzeResponse
from app.security import hmac_auth


wire = json.load(sys.stdin)
fixture = json.loads((Path(__file__).parents[1] / 'openapi/hmac-test-vector.json').read_text())
body = base64.b64decode(wire['body_base64'], validate=True)
assert body == base64.b64decode(fixture['body_base64'], validate=True)
assert hashlib.sha256(body).hexdigest() == fixture['body_sha256']
headers = {name.lower(): values for name, values in wire['headers'].items()}
for name, expected in [('x-cf-timestamp', fixture['timestamp']), ('x-cf-nonce', fixture['nonce']), ('x-cf-signature', fixture['expected_signature'])]:
    assert headers[name] == [expected]

os.environ['PROCESSOR_HMAC_SECRET'] = fixture['test_secret']
received = []


def accepted_payload(_pipeline, request):
    received.append(request.model_dump(mode='json'))
    return AnalyzeResponse(analysis_id=request.analysis_id, findings=[], processed_documents=[])


with patch.object(hmac_auth, 'wall_clock', return_value=int(fixture['timestamp'])), patch.object(hmac_auth, 'nonce_cache', hmac_auth.NonceCache()), patch('app.pipeline.analyze.AnalysisPipeline.run', accepted_payload):
    client = TestClient(app)
    request_headers = [(name, value) for name, values in wire['headers'].items() for value in values]
    response = client.post('/v1/analyze', content=body, headers=request_headers)
    assert response.status_code == 200, response.text
    assert received == [json.loads(fixture['body_utf8'])]
    assert client.post('/v1/analyze', content=body, headers=request_headers).status_code == 401

print('PASS: PHP HTTP body/headers/signature accepted by Python HMAC + closed schema; replay rejected.')
