"""Killable real-provider boundary: bounded JSON IPC, never pickle or a shell."""

import json
import os
from pathlib import Path
import select
import signal
import struct
import subprocess
import sys
from time import monotonic

from app.execution import ExecutionBudget
from app.processes import enable_child_reaping, reap_group, signal_group
from app.providers.base import ProviderError


MAX_REQUEST_BYTES = 256 * 1024
MAX_RESPONSE_BYTES = 64 * 1024
POLL_SECONDS = 0.025
TERMINATE_GRACE_SECONDS = 0.1
PUBLIC_ERRORS = frozenset({'provider_unavailable', 'invalid_provider_response', 'provider_not_configured'})


def _child_command() -> list[str]:
    return [sys.executable, '-m', 'app.providers.process_worker']


def _start_child():
    # exec, not multiprocessing fork: no Python code runs in a forked copy of
    # the multithreaded server. Secrets/payload travel only through the pipe.
    return subprocess.Popen(
        _child_command(), stdin=subprocess.PIPE, stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL, start_new_session=True, close_fds=True,
        cwd=Path(__file__).resolve().parents[2],
        env={
            'PATH': os.defpath, 'LANG': 'C.UTF-8', 'PYTHONUNBUFFERED': '1', 'PYTHONDONTWRITEBYTECODE': '1',
            'CF_PROCESSOR_PARENT_PID': str(os.getpid()),
        },
    )


def _stop_process(process) -> None:
    signal_group(process.pid, signal.SIGTERM)
    try:
        process.wait(timeout=TERMINATE_GRACE_SECONDS)
    except subprocess.TimeoutExpired:
        pass
    # Always kill the group, even if its leader already exited/crashed.
    signal_group(process.pid, signal.SIGKILL)
    process.wait()
    reap_group(process.pid)


def _remaining(deadline, budget):
    if budget:
        budget.checkpoint()
    remaining = deadline - monotonic()
    if remaining <= 0:
        raise ProviderError('provider_unavailable')
    return min(remaining, POLL_SECONDS)


def _write_request(fd, data, deadline, budget):
    offset = 0
    while offset < len(data):
        _, writable, _ = select.select([], [fd], [], _remaining(deadline, budget))
        if writable:
            try:
                offset += os.write(fd, data[offset:offset + 8192])
            except BlockingIOError:
                pass


def _read_exact(fd, size, deadline, budget):
    data = bytearray()
    while len(data) < size:
        readable, _, _ = select.select([fd], [], [], _remaining(deadline, budget))
        if readable:
            try:
                part = os.read(fd, min(8192, size - len(data)))
            except BlockingIOError:
                continue
            if not part:
                raise ProviderError('provider_unavailable')
            data.extend(part)
    return bytes(data)


def request_in_child(
    base_url, api_key, payload, *, timeout, budget: ExecutionBudget | None,
    provider_kind='openai-compatible', model=None,
):
    deadline = monotonic() + (budget.remaining(timeout) if budget else timeout)
    try:
        raw = json.dumps({
            'base_url': base_url, 'api_key': api_key, 'payload': payload, 'deadline': deadline,
            'provider_kind': provider_kind, 'model': model or payload.get('model'),
        }, ensure_ascii=False, separators=(',', ':'), allow_nan=False).encode()
    except (ValueError, TypeError, UnicodeError):
        raise ProviderError('invalid_provider_request') from None
    if len(raw) > MAX_REQUEST_BYTES:
        raise ProviderError('invalid_provider_request')
    _remaining(deadline, budget)
    process = None
    try:
        try:
            enable_child_reaping()
        except (OSError, AttributeError):
            raise ProviderError('provider_not_configured') from None
        process = _start_child()
        os.set_blocking(process.stdin.fileno(), False)
        os.set_blocking(process.stdout.fileno(), False)
        _write_request(process.stdin.fileno(), struct.pack('!I', len(raw)) + raw, deadline, budget)
        process.stdin.close()
        size = struct.unpack('!I', _read_exact(process.stdout.fileno(), 4, deadline, budget))[0]
        if not 1 <= size <= MAX_RESPONSE_BYTES + 1:
            raise ProviderError('invalid_provider_response')
        result = _read_exact(process.stdout.fileno(), size, deadline, budget)
        _remaining(deadline, budget)
        if result[:1] == b'O':
            return result[1:]
        code = result[1:].decode('ascii', errors='replace') if result[:1] == b'E' else ''
        raise ProviderError(code if code in PUBLIC_ERRORS else 'provider_unavailable')
    except ProviderError:
        raise
    except (OSError, ValueError, subprocess.SubprocessError):
        raise ProviderError('provider_unavailable') from None
    finally:
        if process is not None:
            try:
                # The caller owns its active-analysis lease until this returns.
                _stop_process(process)
            finally:
                process.stdin.close()
                process.stdout.close()
