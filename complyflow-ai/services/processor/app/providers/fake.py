"""Offline demonstration fixtures chosen by stable SHA-256, not a real assessment."""

import hashlib
import json

from app.providers.base import AIProvider, AnalysisContext
from app.schemas import CitationDraft, FindingDraft, FindingStatus, RequirementDraft


_FIXTURES = (
    (FindingStatus.MET, 0.86, 'A evidência ilustra o atendimento ao requisito.'),
    (FindingStatus.PARTIAL, 0.62, 'A evidência ilustra atendimento parcial; confira o escopo.'),
    (FindingStatus.INCONCLUSIVE, 0.35, 'A evidência exige esclarecimento para concluir a avaliação.'),
)


class FakeAIProvider(AIProvider):
    def analyze(self, requirement: RequirementDraft, contexts: list[AnalysisContext]) -> FindingDraft:
        common = dict(requirement_id=requirement.requirement_id, requires_human_review=True)
        if not contexts:
            return FindingDraft(
                **common, status=FindingStatus.MISSING, confidence=0.0, citations=[],
                justification='Demonstração: nenhuma evidência recuperada para este requisito.',
                search_summary='Busca híbrida textual e vetorial nos chunks dos documentos da análise; '
                               'nenhum contexto relevante recuperado.',
            )

        seed = json.dumps(
            dict(requirement=requirement.model_dump(mode='json'),
                 contexts=[item.model_dump(mode='json') for item in contexts]),
            sort_keys=True, ensure_ascii=False, separators=(',', ':'),
        ).encode()
        index = int.from_bytes(hashlib.sha256(seed).digest(), 'big') % len(_FIXTURES)
        status, confidence, explanation = _FIXTURES[index]
        if any(item.signals for item in contexts):
            status, confidence = FindingStatus.INCONCLUSIVE, 0.0
            explanation = 'Há instruções suspeitas no documento; a evidência requer inspeção humana.'
        evidence = contexts[0]
        return FindingDraft(
            **common, status=status, confidence=confidence, search_summary=None,
            justification='Demonstração fictícia: ' + explanation,
            citations=[CitationDraft(
                document_id=evidence.document_id, page_number=evidence.page_number,
                quote=evidence.text, start_offset=evidence.start_offset, end_offset=evidence.end_offset,
            )],
        )
