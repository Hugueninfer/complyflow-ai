from uuid import uuid4

import pytest

from app.pdf.extractor import ExtractedPage
from app.execution import ExecutionBudget, ExecutionStopped
from app.pipeline import analyze
from app.providers.fake import FakeAIProvider
from test_pipeline import pdf_request


class Clock:
    def __init__(self):
        self.now = 0.0

    def __call__(self):
        return self.now

    def advance(self, seconds):
        self.now += seconds


@pytest.fixture
def clock(monkeypatch):
    clock = Clock()
    monkeypatch.setattr(analyze, 'monotonic', clock, raising=False)
    monkeypatch.setenv('PROCESSOR_ANALYSIS_TIMEOUT_SECONDS', '5')
    return clock


def test_one_deadline_covers_all_documents_and_stops_before_next_pdf(monkeypatch, clock):
    request = pdf_request()
    request.documents = [request.documents[0].model_copy(update={'document_id': uuid4()}) for _ in range(3)]
    allowances = []

    def extracting(data, *, budget=None):
        allowances.append(budget.remaining(30) if budget else None)
        clock.advance(3)
        return [ExtractedPage(1, 'Primeira pagina')]

    monkeypatch.setattr(analyze, 'extract_pages', extracting)
    with pytest.raises(ExecutionStopped, match='^analysis_budget_exceeded$'):
        analyze.AnalysisPipeline(FakeAIProvider()).run(request)

    assert allowances == [5, 2]


def test_all_requirement_calls_spend_one_deadline(monkeypatch, clock):
    request = pdf_request()
    request.requirements = [request.requirements[0].model_copy(update={'requirement_id': uuid4()}) for _ in range(100)]
    monkeypatch.setattr(analyze, 'extract_pages', lambda data, **kwargs: [ExtractedPage(1, 'Primeira pagina')])
    allowances = []

    class CostlyProvider(FakeAIProvider):
        def analyze(self, requirement, contexts, *, budget=None):
            allowances.append(budget.remaining(20) if budget else None)
            clock.advance(3)
            return super().analyze(requirement, contexts)

    with pytest.raises(ExecutionStopped, match='^analysis_budget_exceeded$'):
        analyze.AnalysisPipeline(CostlyProvider()).run(request)

    assert allowances == [5, 2]


@pytest.mark.parametrize('phase', ['chunking', 'embedding', 'retrieval'])
def test_preparation_consumes_the_same_budget_before_provider(monkeypatch, clock, phase):
    monkeypatch.setattr(analyze, 'extract_pages', lambda data, **kwargs: [ExtractedPage(1, 'Primeira pagina')])
    target, name = (analyze.HybridRetriever, 'search') if phase == 'retrieval' else (
        analyze, 'chunk_pages' if phase == 'chunking' else 'embed_text',
    )
    original = getattr(target, name)

    def costly(*args, **kwargs):
        result = original(*args, **kwargs)
        clock.advance(6)
        return result

    monkeypatch.setattr(target, name, costly)
    with pytest.raises(ExecutionStopped, match='^analysis_budget_exceeded$'):
        analyze.AnalysisPipeline(FakeAIProvider()).run(pdf_request())


@pytest.mark.parametrize('value', ['0', '-1', '46', 'nan', 'inf', 'SECRET'])
def test_invalid_global_budget_fails_closed_without_processing(monkeypatch, value):
    monkeypatch.setenv('PROCESSOR_ANALYSIS_TIMEOUT_SECONDS', value)
    with pytest.raises(ExecutionStopped, match='^analysis_not_configured$'):
        analyze.AnalysisPipeline(FakeAIProvider()).run(pdf_request())


def test_cancellation_stops_the_pipeline_before_another_requirement(monkeypatch, clock):
    request = pdf_request()
    request.requirements.append(request.requirements[0].model_copy(update={'requirement_id': uuid4()}))
    budget = ExecutionBudget(5, clock=clock)
    calls = []
    monkeypatch.setattr(analyze, 'extract_pages', lambda data, **kwargs: [ExtractedPage(1, 'Primeira pagina')])

    class CancellingProvider(FakeAIProvider):
        def analyze(self, requirement, contexts, *, budget=None):
            calls.append(requirement.requirement_id)
            budget.cancel()
            return super().analyze(requirement, contexts)

    with pytest.raises(ExecutionStopped, match='^analysis_cancelled$'):
        analyze.AnalysisPipeline(CancellingProvider()).run(request, budget=budget)
    assert calls == [request.requirements[0].requirement_id]


@pytest.mark.parametrize('operation', ['chunking', 'embedding', 'indexing', 'search', 'guard'])
def test_cpu_operations_stop_inside_their_loops(operation):
    from app.pdf.chunker import chunk_pages
    from app.retrieval.hybrid import HybridRetriever, embed_text
    from app.security.document_guard import scan_untrusted_text
    from test_fake_provider import context

    # Every checkpoint advances deterministic wall time; no real waiting.
    class TickingClock:
        now = 0

        def __call__(self):
            self.now += 1
            return self.now

    budget = ExecutionBudget(3, clock=TickingClock())
    contexts = [context(index=index) for index in range(20)]
    embeddings = [[0.0] * 384 for _ in contexts]
    retriever = HybridRetriever(contexts, embeddings)
    operations = {
        'chunking': lambda: chunk_pages([ExtractedPage(1, 'a' * 20_000)], budget=budget),
        'embedding': lambda: embed_text('term ' * 200, budget=budget),
        'indexing': lambda: HybridRetriever(contexts, embeddings, budget=budget),
        'search': lambda: retriever.search('Primeira pagina', budget=budget),
        'guard': lambda: scan_untrusted_text('Primeira pagina', budget=budget),
    }
    with pytest.raises(ExecutionStopped, match='^analysis_budget_exceeded$'):
        operations[operation]()
