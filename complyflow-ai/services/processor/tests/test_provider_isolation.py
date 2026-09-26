"""Real exec/IPC/DNS tests; no external network and no long-running resolver."""

import json
import os
from pathlib import Path
import sys
import struct
import signal
import subprocess
from threading import Event, Thread, enumerate as threads
from time import monotonic

import pytest

from app.execution import ActiveAnalyses, ExecutionBudget, ExecutionStopped
from app.providers.base import ProviderError
from app.providers.openai_compatible import OpenAICompatibleProvider
from test_fake_provider import context, requirement


@pytest.fixture
def child_probe(monkeypatch, tmp_path):
    from app.providers import process_isolation

    state_path = tmp_path / 'child.json'
    mode = ['dns']
    command = lambda: [sys.executable, str(Path(__file__).with_name('provider_child_probe.py')), mode[0], str(state_path)]
    monkeypatch.setattr(process_isolation, '_child_command', command)
    return mode, state_path


def provider():
    return OpenAICompatibleProvider(
        base_url='https://offline.invalid/v1', api_key='SECRET key', model='demo',
    )


def test_native_gemini_crosses_exec_boundary(child_probe):
    from app.providers.gemini import GeminiProvider

    mode, state_path = child_probe
    mode[0] = 'ok'
    finding = GeminiProvider(api_key='SECRET key', model='gemini-3.1-flash-lite').analyze(
        requirement(), [context()], budget=ExecutionBudget(5),
    )

    assert finding.status == 'missing'
    assert_reaped(wait_state(state_path))


def wait_state(path, timeout=4):
    deadline = monotonic() + timeout
    while not path.exists():
        events = path.with_suffix('.events')
        assert monotonic() < deadline, events.read_text() if events.exists() else 'child never entered exec'
        Event().wait(0.005)
    return json.loads(path.read_text())


def wait_shutdown(path):
    state = wait_state(path)
    deadline = monotonic() + 2
    while True:
        stages = {event['stage'] for event in json.loads(path.with_suffix('.events').read_text())}
        if {'descendant_sigterm_ignored', 'resolver_entered', 'request_scope_exited'} <= stages:
            return state
        assert monotonic() < deadline, stages
        Event().wait(0.005)


class AdvancingClock:
    """Retain real startup time, then consume remaining time after readiness."""

    offset = 0.0

    def __call__(self):
        return monotonic() + self.offset


def assert_reaped(state):
    for pid in [state['pid'], state.get('descendant')]:
        if pid:
            assert not Path(f'/proc/{pid}').exists(), f'PID {pid} is still live or zombie'


@pytest.mark.parametrize('mode', ['dns', 'dns_shutdown', 'crash_descendant'])
def test_probe_waits_for_installed_term_handler_and_descendant_requires_kill(monkeypatch, tmp_path, mode):
    from app.providers import process_isolation

    path = tmp_path / 'child.json'
    gate = tmp_path / 'handler_waiting'
    probe = Path(__file__).with_name('provider_child_probe.py')
    # Stop the real descendant exactly before signal.signal installs SIG_IGN.
    # This exec-only seam changes no production process or timeout behavior.
    delayed_install = f'''
import os, signal
from pathlib import Path
original_signal = signal.signal
def install(signum, handler):
    if signum == signal.SIGTERM:
        Path({str(gate)!r}).write_text(str(os.getpid()))
        os.kill(os.getpid(), signal.SIGSTOP)
    return original_signal(signum, handler)
signal.signal = install
'''
    wrapper = f'''
import runpy, subprocess, sys
original_popen = subprocess.Popen
def start(command, **kwargs):
    if command[1:2] == ['-c']:
        command = [*command[:2], {delayed_install!r} + command[2]]
    return original_popen(command, **kwargs)
subprocess.Popen = start
sys.argv = sys.argv[1:]
runpy.run_path(sys.argv[0], run_name='__main__')
'''
    monkeypatch.setattr(process_isolation, '_child_command', lambda: [
        sys.executable, '-c', wrapper, str(probe), mode, str(path),
    ])
    original_reap = process_isolation.reap_group
    exits = []

    def observe_reap(pgid):
        try:
            result = os.waitid(os.P_PGID, pgid, os.WEXITED | os.WNOWAIT)
            exits.append((result.si_pid, result.si_code, result.si_status))
        except ChildProcessError:
            pass
        finally:
            original_reap(pgid)

    monkeypatch.setattr(process_isolation, 'reap_group', observe_reap)
    budget = ExecutionBudget(5)
    errors = []

    def analyze():
        try:
            provider().analyze(requirement(), [context()], budget=budget)
        except Exception as error:
            errors.append(str(error))

    thread = Thread(target=analyze)
    thread.start()
    try:
        deadline = monotonic() + 4
        while not gate.exists():
            assert not path.exists() and not errors, 'probe advanced before descendant installed SIGTERM handler'
            assert monotonic() < deadline, 'descendant never reached signal installation'
            Event().wait(0.005)
        # Hold the installation gate: neither readiness nor crash may advance.
        deadline = monotonic() + 0.1
        while monotonic() < deadline:
            assert not path.exists() and not errors, 'probe advanced before descendant installed SIGTERM handler'
            events = json.loads(path.with_suffix('.events').read_text())
            assert 'descendant_sigterm_ignored' not in {event['stage'] for event in events}
            Event().wait(0.005)
        pid = int(gate.read_text())
        os.kill(pid, signal.SIGCONT)
        state = wait_state(path)
        assert state['descendant'] == pid
        if mode != 'crash_descendant':
            status = Path(f'/proc/{pid}/status').read_text().splitlines()
            ignored = int(next(line.split()[1] for line in status if line.startswith('SigIgn:')), 16)
            assert ignored & (1 << (signal.SIGTERM - 1)), 'readiness preceded actual SIG_IGN installation'
            if mode == 'dns_shutdown':
                wait_shutdown(path)
            budget.cancel()
        thread.join(2)
        assert not thread.is_alive()
        events = json.loads(path.with_suffix('.events').read_text())
        stages = [event['stage'] for event in events]
        ready = stages.index('descendant_sigterm_ignored')
        subsequent = 'crash_descendant_exit' if mode == 'crash_descendant' else 'resolver_entered'
        assert ready < stages.index(subsequent)
        assert events[ready]['pid'] == pid
        assert exits == [(pid, os.CLD_KILLED, signal.SIGKILL)], 'TERM-resistant descendant must require KILL and reap'
        assert errors == ['provider_unavailable' if mode == 'crash_descendant' else 'analysis_cancelled']
        assert_reaped(state)
    finally:
        budget.cancel()
        thread.join(2)


@pytest.mark.parametrize('stop', ['operation', 'budget', 'cancel'])
def test_native_dns_and_descendant_are_killed_reaped_and_do_not_leak_threads(monkeypatch, child_probe, stop, capfd):
    from app.providers import process_isolation

    mode, path = child_probe
    mode[0] = 'dns_shutdown'
    clock = AdvancingClock()
    monkeypatch.setattr(process_isolation, 'monotonic', clock)
    budget = ExecutionBudget(45 if stop == 'operation' else 5, clock=clock)
    before = {thread.ident for thread in threads()}
    errors = []

    def analyze():
        try:
            provider().analyze(requirement(), [context('SECRET document')], budget=budget)
        except Exception as error:
            errors.append(error)

    thread = Thread(target=analyze)
    thread.start()
    state = wait_shutdown(path)
    assert state['threads'] >= 2, 'must exercise real asyncio DNS executor shutdown'
    assert 'SECRET' not in Path(f"/proc/{state['pid']}/cmdline").read_bytes().decode()
    assert b'SECRET' not in Path(f"/proc/{state['pid']}/environ").read_bytes()
    if stop == 'cancel':
        budget.cancel()
    else:
        clock.offset += 41 if stop == 'operation' else 6
    stopped_at = monotonic()
    thread.join(2)
    assert not thread.is_alive(), 'provider thread survived deadline/cancellation'
    assert monotonic() - stopped_at < 2
    assert len(errors) == 1
    expected = {'operation': 'provider_unavailable', 'budget': 'analysis_budget_exceeded', 'cancel': 'analysis_cancelled'}[stop]
    assert str(errors[0]) == expected
    assert_reaped(state)
    assert {thread.ident for thread in threads()} == before
    captured = capfd.readouterr()
    assert 'SECRET' not in str(errors) + captured.out + captured.err


def test_lease_and_capacity_remain_held_until_reap_and_retry_then_succeeds(monkeypatch, child_probe):
    from app.providers import process_isolation

    mode, path = child_probe
    active = ActiveAnalyses()
    cleanup, release = Event(), Event()
    budget = ExecutionBudget(5)
    errors = []
    original = process_isolation._stop_process

    def held_cleanup(process):
        cleanup.set()
        assert release.wait(2)
        original(process)

    monkeypatch.setattr(process_isolation, '_stop_process', held_cleanup)

    def analyze():
        try:
            with active.lease('analysis-1', 'key-1'):
                provider().analyze(requirement(), [context()], budget=budget)
        except Exception as error:
            errors.append(error)

    thread = Thread(target=analyze)
    thread.start()
    state = wait_state(path)
    budget.cancel()
    try:
        assert cleanup.wait(1)
        for analysis_id, key, expected in [
            ('analysis-1', 'key-1', 'analysis_in_progress'),
            ('analysis-2', 'key-2', 'analysis_capacity_exceeded'),
        ]:
            with pytest.raises(ExecutionStopped, match=f'^{expected}$'):
                with active.lease(analysis_id, key):
                    pytest.fail('lease was released before the child was reaped')
        assert Path(f"/proc/{state['pid']}").exists()
    finally:
        release.set()
        thread.join(2)
    assert not thread.is_alive()
    assert [str(error) for error in errors] == ['analysis_cancelled']
    assert_reaped(state)
    mode[0] = 'ok'
    path.unlink()
    with active.lease('analysis-1', 'key-1'):
        assert provider().analyze(requirement(), [context()], budget=ExecutionBudget(5)).status == 'missing'
    assert_reaped(wait_state(path))


@pytest.mark.parametrize('mode,expected', [
    ('crash', 'provider_unavailable'), ('crash_descendant', 'provider_unavailable'),
    ('oversize', 'invalid_provider_response'), ('partial', 'analysis_budget_exceeded'),
    ('error_secret', 'provider_unavailable'), ('malformed', 'invalid_provider_response'),
    ('http_oversize', 'invalid_provider_response'), ('exception', 'provider_unavailable'),
])
def test_child_crashes_and_untrusted_frames_are_bounded_sanitized_and_reaped(child_probe, mode, expected, capfd):
    modes, path = child_probe
    modes[0] = mode
    with pytest.raises((ProviderError, ExecutionStopped), match=f'^{expected}$'):
        provider().analyze(requirement(), [context()], budget=ExecutionBudget(2 if mode == 'partial' else 5))
    assert_reaped(wait_state(path))
    captured = capfd.readouterr()
    assert 'SECRET' not in captured.out + captured.err


def test_request_byte_limit_rejects_before_starting_child_and_recovers(child_probe):
    mode, path = child_probe
    mode[0] = 'ok'
    with pytest.raises(ProviderError, match='^invalid_provider_request$'):
        provider().analyze(requirement(), [context('SECRET' * 50_000)], budget=ExecutionBudget(5))
    assert not path.exists()
    assert provider().analyze(requirement(), [context()], budget=ExecutionBudget(5)).status == 'missing'
    assert_reaped(wait_state(path))


def test_successful_maximum_frame_is_accepted_and_no_descriptors_accumulate(child_probe):
    mode, path = child_probe
    mode[0] = 'max_response'
    before = len(os.listdir('/proc/self/fd'))
    for _ in range(3):
        assert provider().analyze(requirement(), [context()], budget=ExecutionBudget(5)).status == 'missing'
        assert_reaped(wait_state(path))
    assert len(os.listdir('/proc/self/fd')) == before


def test_fake_does_not_start_an_isolation_process(monkeypatch):
    from app.providers import process_isolation
    from app.providers.fake import FakeAIProvider

    monkeypatch.setattr(process_isolation, '_start_child', lambda: pytest.fail('fake started a child'))
    assert FakeAIProvider().analyze(requirement(), [context()]).requires_human_review


def test_unencodable_payload_is_sanitized_before_process_start(child_probe, capfd):
    _, path = child_probe
    invalid = OpenAICompatibleProvider(base_url='https://offline.invalid', api_key='SECRET\ud800', model='demo')
    with pytest.raises(ProviderError, match='^invalid_provider_request$') as error:
        invalid.analyze(requirement(), [context()], budget=ExecutionBudget(5))
    assert not path.exists()
    captured = capfd.readouterr()
    assert 'SECRET' not in str(error.value) + captured.out + captured.err


@pytest.mark.parametrize('data', [struct.pack('!I', 262_145), struct.pack('!I', 5) + b'{bad', struct.pack('!I', 2) + b'{}'])
def test_production_child_rejects_oversized_partial_and_invalid_request_frame(data):
    from app.providers import process_isolation

    process = process_isolation._start_child()
    try:
        output, _ = process.communicate(data, timeout=5)
        expected = b'Eprovider_unavailable'
        assert output == struct.pack('!I', len(expected)) + expected
    finally:
        process_isolation._stop_process(process)
        process.stdin.close()
        process.stdout.close()


def test_exec_uses_no_shell_fork_callback_secrets_or_bytecode_writes(monkeypatch):
    from app.providers import process_isolation

    monkeypatch.setenv('AI_API_KEY', 'SECRET key')
    monkeypatch.setenv('PROCESSOR_HMAC_SECRET', 'SECRET hmac')
    monkeypatch.setenv('PYTHONPATH', 'SECRET untrusted-import-path')
    launches = []
    monkeypatch.setattr(process_isolation.subprocess, 'Popen', lambda *args, **kwargs: launches.append((args, kwargs)))
    process_isolation._start_child()
    args, kwargs = launches[0]
    assert args == ([sys.executable, '-m', 'app.providers.process_worker'],)
    assert kwargs['start_new_session'] is True and kwargs['close_fds'] is True
    assert not kwargs.get('shell') and not kwargs.get('preexec_fn')
    assert kwargs['env']['PYTHONDONTWRITEBYTECODE'] == '1'
    assert 'SECRET' not in str(launches)


def test_child_that_never_reads_input_cannot_extend_the_deadline(monkeypatch, child_probe):
    from app.providers import process_isolation

    mode, _ = child_probe
    mode[0] = 'partial'
    started_children = []
    original_start = process_isolation._start_child

    def observe_start():
        process = original_start()
        started_children.append(process.pid)
        return process

    monkeypatch.setattr(process_isolation, '_start_child', observe_start)
    started = monotonic()
    with pytest.raises(ExecutionStopped, match='^analysis_budget_exceeded$'):
        provider().analyze(requirement(), [context('a' * 100_000)], budget=ExecutionBudget(0.5))
    assert monotonic() - started < 1
    assert len(started_children) == 1
    assert not Path(f'/proc/{started_children[0]}').exists(), 'child was not reaped after the deadline'


def test_native_http_worker_cannot_outlive_abrupt_server_process_death(tmp_path):
    from app.processes import enable_child_reaping, reap_group, signal_group

    enable_child_reaping()
    path = tmp_path / 'child.json'
    parent = subprocess.Popen([
        sys.executable, str(Path(__file__).with_name('provider_parent_probe.py')), str(path),
    ], stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)
    state = None
    try:
        state = wait_state(path, timeout=10)
        parent.kill()
        parent.wait(timeout=1)
        deadline = monotonic() + 0.5
        while True:
            pid, status = os.waitpid(state['pid'], os.WNOHANG)
            if pid:
                assert os.WIFSIGNALED(status) and os.WTERMSIG(status) == signal.SIGKILL
                break
            assert monotonic() < deadline, 'native HTTP/DNS worker survived its killed server'
            Event().wait(0.005)
        assert_reaped(state)
    finally:
        signal_group(parent.pid, signal.SIGKILL)
        parent.wait()
        if state:
            signal_group(state['pid'], signal.SIGKILL)
            reap_group(state['pid'])
        elif path.with_suffix('.events').exists():
            pid = json.loads(path.with_suffix('.events').read_text())[0]['pid']
            signal_group(pid, signal.SIGKILL)
            reap_group(pid)
        reap_group(parent.pid)


def test_asgi_budget_stops_real_child_then_same_analysis_can_retry(monkeypatch, child_probe):
    import anyio
    import httpx
    from app.api import analyze as api
    from app.main import app
    from app.pdf.extractor import ExtractedPage
    from app.pipeline import analyze as pipeline
    from app.providers import process_isolation
    from conftest import TEST_SECRET, signed_headers
    from test_pipeline import pdf_request

    mode, path = child_probe
    mode[0] = 'dns_shutdown'
    clock = AdvancingClock()
    monkeypatch.setattr(api, 'monotonic', clock)
    monkeypatch.setattr(process_isolation, 'monotonic', clock)
    monkeypatch.setenv('PROCESSOR_ANALYSIS_TIMEOUT_SECONDS', '5')
    monkeypatch.setenv('PROCESSOR_HMAC_SECRET', TEST_SECRET)
    monkeypatch.setattr(pipeline, 'extract_pages', lambda data, **kwargs: [ExtractedPage(1, 'Primeira pagina')])
    monkeypatch.setattr(pipeline.AnalysisPipeline, 'from_settings', lambda: pipeline.AnalysisPipeline(provider()))
    body = pdf_request().model_dump_json().encode()

    async def scenario():
        async with httpx.AsyncClient(transport=httpx.ASGITransport(app), base_url='http://test') as client:
            responses = []

            async def request():
                responses.append(await client.post('/v1/analyze', content=body, headers=signed_headers(body)))

            async with anyio.create_task_group() as group:
                group.start_soon(request)
                state = await anyio.to_thread.run_sync(wait_shutdown, path)
                clock.offset += 6
            response = responses[0]
            assert response.status_code == 503
            assert response.json() == {'detail': 'analysis_budget_exceeded'}
            with anyio.fail_after(2):
                while api.active_analyses._active:
                    await anyio.sleep(0.005)
            assert_reaped(state)
            mode[0] = 'ok'
            clock.offset = 0
            response = await client.post('/v1/analyze', content=body, headers=signed_headers(body))
            assert response.status_code == 200
            assert len(response.json()['findings']) == 1
            assert_reaped(wait_state(path))

    anyio.run(scenario)
