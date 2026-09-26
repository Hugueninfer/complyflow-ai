"""Native Gemini GenerateContent adapter with a closed structured-output contract."""

import json
import re
from urllib.parse import quote

from pydantic import ValidationError

from app.execution import ExecutionBudget
from app.providers.base import AnalysisContext, ProviderError
from app.providers.openai_compatible import (
    PROVIDER_TIMEOUT_SECONDS,
    SYSTEM_INSTRUCTIONS,
    OpenAICompatibleProvider,
    _delimited_json,
)
from app.providers.process_isolation import request_in_child
from app.schemas import FindingDraft, RequirementDraft


GEMINI_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta'
_MODEL_NAME = re.compile(r'^[A-Za-z0-9._-]+$')


class GeminiProvider(OpenAICompatibleProvider):
    """Use Google's native REST API instead of its beta OpenAI translation layer."""

    def __init__(self, *, api_key: str, model: str):
        if not _MODEL_NAME.fullmatch(model.strip()):
            raise ProviderError('provider_not_configured')
        super().__init__(base_url=GEMINI_BASE_URL, api_key=api_key, model=model)
        self.url = f'{GEMINI_BASE_URL}/models/{quote(model, safe="")}:generateContent'
        self.reasoning_effort = 'minimal'

    def _headers(self) -> dict[str, str]:
        return {'x-goog-api-key': self.api_key, 'Accept-Encoding': 'identity'}

    def _execute_request(self, payload, budget):
        return request_in_child(
            self.base_url, self.api_key, payload,
            timeout=PROVIDER_TIMEOUT_SECONDS, budget=budget,
            provider_kind='gemini', model=self.model,
        )

    def analyze(
        self, requirement: RequirementDraft, contexts: list[AnalysisContext], *,
        budget: ExecutionBudget | None = None,
    ) -> FindingDraft:
        if budget:
            budget.checkpoint()
        user = (
            '<requirement>' + _delimited_json(requirement.model_dump(mode='json')) + '</requirement>\n'
            '<untrusted_document_contexts>'
            + _delimited_json([item.model_dump(mode='json') for item in contexts])
            + '</untrusted_document_contexts>'
        )
        payload = {
            'systemInstruction': {'parts': [{'text': SYSTEM_INSTRUCTIONS}]},
            'contents': [{'role': 'user', 'parts': [{'text': user}]}],
            'generationConfig': {
                'maxOutputTokens': 4000,
                'responseMimeType': 'application/json',
                'responseJsonSchema': FindingDraft.model_json_schema(),
                'thinkingConfig': {'thinkingLevel': 'MINIMAL'},
            },
        }
        raw = self._execute_request(payload, budget)
        try:
            result = json.loads(raw)
            candidates = result['candidates']
            if len(candidates) != 1 or candidates[0]['finishReason'] != 'STOP':
                raise ValueError
            parts = candidates[0]['content']['parts']
            if len(parts) != 1 or set(parts[0]) != {'text'}:
                raise ValueError
            finding = FindingDraft.model_validate_json(parts[0]['text'])
            if finding.requirement_id != requirement.requirement_id:
                raise ValueError
        except (ValueError, TypeError, KeyError, IndexError, AttributeError, ValidationError):
            raise ProviderError('invalid_provider_response') from None
        if budget:
            budget.checkpoint()
        return finding
