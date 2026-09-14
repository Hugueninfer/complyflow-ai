"""Offline exec/DNS probe inside the live 512 MiB / 0.1 CPU production runtime."""

import json
import os
from pathlib import Path
import signal
import sys
from tempfile import TemporaryDirectory
from threading import Event, Thread, enumerate as threads
from time import monotonic

sys.path.insert(0, '/app/services/processor')

from app.execution import ActiveAnalyses, ExecutionBudget, ExecutionStopped
from app.providers import openai_compatible, process_isolation
from app.schemas import RequirementDraft


requirement = RequirementDraft.model_validate({
    'requirement_id': '20000000-0000-4000-8000-000000000001', 'criterion': 'Smoke offline',
    'category': 'Fictício', 'weight': 1.0, 'evaluation_text': 'Sem rede externa.',
})
provider = openai_compatible.OpenAICompatibleProvider(
    base_url='https://offline.invalid/v1', api_key='smoke-only-not-a-key', model='smoke',
)
active = ActiveAnalyses()
before = {thread.ident for thread in threads()}
assert openai_compatible.PROVIDER_TIMEOUT_SECONDS == 20
original_reap = process_isolation.reap_group
descendant_exits = []


def observe_reap(pgid):
    try:
        # WNOWAIT observes the real exit signal without consuming the status;
        # the production cleanup must still reap the adopted descendant.
        result = os.waitid(os.P_PGID, pgid, os.WEXITED | os.WNOWAIT)
        descendant_exits.append((result.si_pid, result.si_code, result.si_status))
    except ChildProcessError:
        pass
    finally:
        original_reap(pgid)


process_isolation.reap_group = observe_reap

with TemporaryDirectory(prefix='provider-isolation-smoke-') as directory:
    state_path = Path(directory) / 'child.json'
    mode = ['dns_shutdown']
    process_isolation._child_command = lambda: [
        sys.executable, '/opt/probes/provider_child_probe.py', mode[0], str(state_path),
    ]
    for stop in ['deadline', 'cancel']:
        budget = ExecutionBudget(45)  # Includes exec, imports, IPC and all I/O.
        errors = []
        started = monotonic()

        def analyze():
            try:
                with active.lease('analysis-smoke', 'key-smoke'):
                    provider.analyze(requirement, [], budget=budget)
            except Exception as error:
                errors.append(str(error))

        thread = Thread(target=analyze)
        thread.start()
        events_path = state_path.with_suffix('.events')
        try:
            # Readiness is separate from the stop assertion, never from the
            # real provider deadline (20 s) which has counted startup all along.
            while True:
                events = json.loads(events_path.read_text()) if events_path.exists() else []
                stages = {event['stage'] for event in events}
                if {'descendant_sigterm_ignored', 'request_scope_exited', 'resolver_entered'} <= stages:
                    break
                assert thread.is_alive() and monotonic() - started < 20, events
                Event().wait(0.02)
            state = json.loads(state_path.read_text())
            assert state['threads'] >= 2 and state['descendant'] > 0
            stages = [event['stage'] for event in events]
            ready_index = stages.index('descendant_sigterm_ignored')
            assert ready_index < stages.index('resolver_entered') < stages.index('request_scope_exited')
            assert events[ready_index]['pid'] == state['descendant']
            status = Path(f"/proc/{state['descendant']}/status").read_text().splitlines()
            ignored = int(next(line.split()[1] for line in status if line.startswith('SigIgn:')), 16)
            assert ignored & (1 << (signal.SIGTERM - 1)), 'readiness preceded actual SIG_IGN installation'
            try:
                with active.lease('analysis-smoke', 'key-smoke'):
                    raise AssertionError('lease released while executor shutdown is blocked')
            except ExecutionStopped as error:
                assert str(error) == 'analysis_in_progress'
            ready = monotonic()
            if stop == 'deadline':
                # Control seam only shortens the existing absolute budget; it
                # never resets/extends a real call's allowance after startup.
                budget.deadline = min(budget.deadline, ready + 0.3)
            else:
                budget.cancel()
            thread.join(2)
            assert not thread.is_alive() and monotonic() - ready < 2
            assert monotonic() - started < 45
            expected = 'analysis_budget_exceeded' if stop == 'deadline' else 'analysis_cancelled'
            assert errors == [expected]
            assert descendant_exits == [(state['descendant'], os.CLD_KILLED, signal.SIGKILL)]
            for pid in [state['pid'], state['descendant']]:
                assert not Path(f'/proc/{pid}').exists(), 'live or zombie process remained'
            print('PASS: provider startup stages (seconds):', {
                event['stage']: round(event['time'] - started, 3) for event in events
            }, 'stop:', stop, 'stop_seconds:', round(monotonic() - ready, 3), 'descendant: SIGKILL/reaped')
        finally:
            budget.cancel()
            thread.join(2)
        state_path.unlink()
        events_path.unlink()
        descendant_exits.clear()
    mode[0] = 'ok'
    with active.lease('analysis-smoke', 'key-smoke'):
        assert provider.analyze(requirement, [], budget=ExecutionBudget(45)).status == 'missing'
    assert not Path(f"/proc/{json.loads(state_path.read_text())['pid']}").exists()
    assert {thread.ident for thread in threads()} == before

print('PASS: native DNS executor shutdown blocked offline; group reaped, no thread leak, same analysis retries under 512 MiB/0.1 CPU')
