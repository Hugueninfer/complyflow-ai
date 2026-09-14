import json
from threading import Event, Lock
from time import monotonic
from uuid import uuid4

import anyio
import httpx
import pytest

from app.api import analyze as api
from app.main import app
from app.pdf.extractor import ExtractedPage
from app.pipeline.analyze import AnalysisPipeline
from app.providers.fake import FakeAIProvider
from conftest import TEST_SECRET, signed_headers
from test_pipeline import pdf_request


@pytest.fixture(autouse=True)
def offline_processor(monkeypatch):
    from app.pipeline import analyze

    monkeypatch.setenv('PROCESSOR_HMAC_SECRET', TEST_SECRET)
    monkeypatch.setenv('PROCESSOR_ANALYSIS_TIMEOUT_SECONDS', '5')
    monkeypatch.setattr(analyze, 'extract_pages', lambda data, **kwargs: [ExtractedPage(1, 'Primeira pagina')])


async def post(client, payload):
    body = json.dumps(payload).encode()
    return await client.post('/v1/analyze', content=body, headers=signed_headers(body))


@pytest.mark.parametrize('collision', ['analysis', 'key', 'both', 'capacity'])
@pytest.mark.parametrize('outcome', ['success', 'exception'])
def test_active_execution_rejects_overlap_then_releases_on_exit(monkeypatch, collision, outcome):
    entered, release = Event(), Event()
    lock = Lock()
    calls = []

    class ControlledProvider(FakeAIProvider):
        def analyze(self, requirement, contexts, *, budget=None):
            with lock:
                calls.append(requirement.requirement_id)
                first = len(calls) == 1
            if first:
                entered.set()
                assert release.wait(2), 'test did not release the first execution'
                if outcome == 'exception':
                    raise RuntimeError('SECRET document and provider details')
            return super().analyze(requirement, contexts)

    provider = ControlledProvider()
    monkeypatch.setattr(AnalysisPipeline, 'from_settings', lambda: AnalysisPipeline(provider))
    payload = pdf_request().model_dump(mode='json')
    duplicate = payload.copy()
    if collision in {'key', 'capacity'}:
        duplicate['analysis_id'] = str(uuid4())
    if collision in {'analysis', 'capacity'}:
        duplicate['idempotency_key'] = 'another-business-key'

    async def scenario():
        async with httpx.AsyncClient(transport=httpx.ASGITransport(app), base_url='http://test') as client:
            responses = []

            async def first_request():
                responses.append(await post(client, payload))

            async with anyio.create_task_group() as group:
                group.start_soon(first_request)
                assert await anyio.to_thread.run_sync(entered.wait, 1)
                try:
                    rejected = await post(client, duplicate)
                finally:
                    release.set()
            assert rejected.status_code == 503
            expected = 'analysis_capacity_exceeded' if collision == 'capacity' else 'analysis_in_progress'
            assert rejected.json() == {'detail': expected}
            assert len(calls) == 1
            assert responses[0].status_code == (200 if outcome == 'success' else 503)
            assert 'SECRET' not in responses[0].text
            retry = await post(client, payload)
            assert retry.status_code == 200
            assert len(calls) == 2

    anyio.run(scenario)


def test_absolute_http_budget_includes_work_and_keeps_lease_until_worker_exits(monkeypatch):
    monkeypatch.setenv('PROCESSOR_ANALYSIS_TIMEOUT_SECONDS', '0.15')
    entered, release, stopped = Event(), Event(), Event()
    budgets = []

    class CleanupProvider(FakeAIProvider):
        def analyze(self, requirement, contexts, *, budget=None):
            budgets.append(budget)
            if len(budgets) == 1:
                entered.set()
                # Emulate a worker in cleanup: HTTP may finish, but its lease
                # must stay held until the worker has actually returned.
                assert release.wait(2)
            return super().analyze(requirement, contexts)

    class Pipeline(AnalysisPipeline):
        def run(self, *args, **kwargs):
            try:
                return super().run(*args, **kwargs)
            finally:
                stopped.set()

    monkeypatch.setattr(AnalysisPipeline, 'from_settings', lambda: Pipeline(CleanupProvider()))
    payload = pdf_request().model_dump(mode='json')

    async def scenario():
        async with httpx.AsyncClient(transport=httpx.ASGITransport(app), base_url='http://test') as client:
            try:
                started = monotonic()
                with anyio.fail_after(0.75):
                    response = await post(client, payload)
                assert monotonic() - started < 0.75
                assert entered.is_set()
                assert response.status_code == 503
                assert response.json() == {'detail': 'analysis_budget_exceeded'}
                assert budgets[0].cancelled
                duplicate = await post(client, payload)
                assert duplicate.status_code == 503
                assert duplicate.json() == {'detail': 'analysis_in_progress'}
                assert len(budgets) == 1
            finally:
                release.set()
                assert await anyio.to_thread.run_sync(stopped.wait, 1)
            with anyio.fail_after(1):
                while True:
                    retry = await post(client, payload)
                    if retry.status_code == 200:
                        break
                    assert retry.json() == {'detail': 'analysis_in_progress'}
                    await anyio.lowlevel.checkpoint()
            assert len(budgets) == 2

    anyio.run(scenario)


@pytest.mark.parametrize('stop', ['disconnect', 'cancel'])
def test_asgi_disconnect_or_task_cancellation_reaches_the_worker(monkeypatch, stop):
    entered, release, stopped = Event(), Event(), Event()
    budgets = []

    class WaitingProvider(FakeAIProvider):
        def analyze(self, requirement, contexts, *, budget=None):
            budgets.append(budget)
            entered.set()
            try:
                while not release.wait(0.005):
                    budget.checkpoint()
                return super().analyze(requirement, contexts)
            finally:
                stopped.set()

    monkeypatch.setattr(AnalysisPipeline, 'from_settings', lambda: AnalysisPipeline(WaitingProvider()))
    body = pdf_request().model_dump_json().encode()

    async def scenario():
        disconnect = anyio.Event()
        sent = []
        delivered_body = False
        scope = anyio.CancelScope()

        async def receive():
            nonlocal delivered_body
            if not delivered_body:
                delivered_body = True
                return {'type': 'http.request', 'body': body, 'more_body': False}
            await disconnect.wait()
            return {'type': 'http.disconnect'}

        async def send(message):
            sent.append(message)

        async def request():
            with scope:
                await app({
                    'type': 'http', 'asgi': {'version': '3.0'}, 'http_version': '1.1',
                    'method': 'POST', 'scheme': 'http', 'path': '/v1/analyze', 'query_string': b'',
                    'headers': [(name.lower().encode(), value.encode()) for name, value in signed_headers(body).items()],
                    'client': ('127.0.0.1', 1234), 'server': ('test', 80),
                }, receive, send)

        async with anyio.create_task_group() as group:
            group.start_soon(request)
            assert await anyio.to_thread.run_sync(entered.wait, 1)
            try:
                if stop == 'disconnect':
                    disconnect.set()
                else:
                    scope.cancel()
                assert await anyio.to_thread.run_sync(stopped.wait, 0.5)
                assert budgets[0].cancelled
            finally:
                release.set()
        if stop == 'disconnect':
            assert [message['status'] for message in sent if message['type'] == 'http.response.start'] == [503]
            assert b''.join(message.get('body', b'') for message in sent) == b'{"detail":"analysis_cancelled"}'

    anyio.run(scenario)


def test_budget_exhausted_during_preparation_has_retryable_sanitized_response(client, monkeypatch):
    from app.pipeline import analyze
    from test_execution_budget import Clock

    clock = Clock()
    monkeypatch.setattr(analyze, 'monotonic', clock)
    monkeypatch.setattr(api, 'monotonic', clock, raising=False)
    original = analyze.chunk_pages

    def chunk(*args, **kwargs):
        result = original(*args, **kwargs)
        clock.advance(6)
        return result

    monkeypatch.setattr(analyze, 'chunk_pages', chunk)
    from test_analyze_api import post_signed
    response = post_signed(client, pdf_request().model_dump(mode='json'))
    assert response.status_code == 503
    assert response.json() == {'detail': 'analysis_budget_exceeded'}
