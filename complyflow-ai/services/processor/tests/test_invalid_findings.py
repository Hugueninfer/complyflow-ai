import pytest

from app.schemas import FindingDraft, ProcessedDocumentDraft
from test_analyze_api import valid_response
from test_fake_provider import requirement
from test_pipeline import pdf_request


@pytest.mark.parametrize('change', [
    {'page_number': 99}, {'document_id': '30000000-0000-4000-8000-000000000099'},
    {'quote': 'Invented'}, {'start_offset': 1}, {'end_offset': 100},
])
def test_rejects_citation_not_exactly_grounded_in_real_page(change):
    from app.pipeline.analyze import AnalysisPipeline, InvalidCitation

    payload = valid_response()
    payload['findings'][0]['citations'][0].update(change)
    finding = FindingDraft.model_validate(payload['findings'][0])
    documents = [ProcessedDocumentDraft.model_validate(payload['processed_documents'][0])]
    with pytest.raises(InvalidCitation):
        AnalysisPipeline.from_settings().validate_finding(finding, documents)


def test_accepts_actual_page_slice():
    from app.pipeline.analyze import AnalysisPipeline

    payload = valid_response()
    finding = FindingDraft.model_validate(payload['findings'][0])
    documents = [ProcessedDocumentDraft.model_validate(payload['processed_documents'][0])]
    assert AnalysisPipeline.from_settings().validate_finding(finding, documents) == finding


@pytest.mark.parametrize('change', [
    {'requirement_id': '20000000-0000-4000-8000-000000000099'},
    {'status': 'approved'}, {'confidence': 2.0}, {'requires_human_review': False},
    {'citations': []}, {'unexpected': 'SECRET'},
])
def test_pipeline_revalidates_even_constructed_provider_models(change):
    from app.pipeline.analyze import AnalysisPipeline, InvalidFinding

    class InvalidProvider:
        def analyze(self, requirement, contexts, *, budget=None):
            from app.providers.fake import FakeAIProvider
            finding = FakeAIProvider().analyze(requirement, contexts)
            if 'unexpected' in change:
                return finding.model_dump() | change
            return finding.model_copy(update=change)

    with pytest.raises(InvalidFinding, match='^invalid_finding$'):
        AnalysisPipeline(provider=InvalidProvider()).run(pdf_request())


def test_missing_provider_output_requires_search_record():
    from app.pipeline.analyze import AnalysisPipeline, InvalidFinding

    class InvalidProvider:
        def analyze(self, requirement, contexts, *, budget=None):
            return FindingDraft.model_construct(
                requirement_id=requirement.requirement_id, status='missing',
                justification='Ausente', confidence=0.5, requires_human_review=True,
                citations=[], search_summary=None,
            )

    with pytest.raises(InvalidFinding):
        AnalysisPipeline(provider=InvalidProvider()).run(pdf_request())


def test_rejects_exact_quote_on_real_page_outside_retrieved_contexts():
    from app.pipeline.analyze import AnalysisPipeline, InvalidCitation

    class UnretrievedPageProvider:
        def analyze(self, requirement, contexts, *, budget=None):
            assert [item.page_number for item in contexts] == [1]
            payload = valid_response()['findings'][0]
            payload['citations'][0].update(
                page_number=2, quote='Segunda pagina', start_offset=0, end_offset=14,
            )
            return FindingDraft.model_validate(payload)

    request = pdf_request()
    request.requirements[0].criterion = 'Primeira'
    request.requirements[0].evaluation_text = 'Primeira'
    with pytest.raises(InvalidCitation, match='^invalid_citation$'):
        AnalysisPipeline(provider=UnretrievedPageProvider()).run(request)
