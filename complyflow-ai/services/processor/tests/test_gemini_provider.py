import json

import anyio
import httpx

from app.providers.gemini import GeminiProvider
from app.schemas import FindingDraft
from test_analyze_api import valid_response
from test_fake_provider import context, requirement


class InProcessMockGeminiProvider(GeminiProvider):
    def __init__(self, *, transport, **kwargs):
        super().__init__(**kwargs)
        self._transport = transport

    def _execute_request(self, payload, budget):
        return anyio.run(self._request, payload, budget)


def test_gemini_uses_native_api_key_header_and_structured_output():
    expected = valid_response()['findings'][0]

    def response(request):
        body = json.loads(request.content)
        assert request.url == (
            'https://generativelanguage.googleapis.com/v1beta/'
            'models/gemini-3.5-flash-lite:generateContent'
        )
        assert request.headers['x-goog-api-key'] == 'test-only'
        assert 'authorization' not in request.headers
        assert body['systemInstruction']['parts'][0]['text']
        assert body['contents'][0]['role'] == 'user'
        config = body['generationConfig']
        assert config['responseMimeType'] == 'application/json'
        assert config['responseJsonSchema']['additionalProperties'] is False
        assert config['thinkingConfig'] == {'thinkingLevel': 'MINIMAL'}
        return httpx.Response(200, json={'candidates': [{
            'finishReason': 'STOP',
            'content': {'parts': [{'text': json.dumps(expected)}]},
        }]})

    provider = InProcessMockGeminiProvider(
        api_key='test-only', model='gemini-3.5-flash-lite',
        transport=httpx.MockTransport(response),
    )
    assert provider.analyze(requirement(), [context()]) == FindingDraft.model_validate(expected)
