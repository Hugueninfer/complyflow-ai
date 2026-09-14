"""Trusted test-only exec entrypoint. Not shipped/imported by the application."""

import json
import os
from pathlib import Path
import socket
import struct
import subprocess
import sys
from threading import Event, active_count
from time import monotonic

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
sys.path.insert(0, str(Path.cwd()))

mode, state_path = sys.argv[1:]
http_budgets = []


def trace(stage):
    path = Path(state_path).with_suffix('.events')
    events = json.loads(path.read_text()) if path.exists() else []
    events.append({'stage': stage, 'time': monotonic(), 'pid': os.getpid()})
    temporary = path.with_suffix('.events.tmp')
    temporary.write_text(json.dumps(events))
    temporary.replace(path)


trace('exec_ready')


def record(descendant=None):
    path = Path(state_path)
    temporary = path.with_suffix('.tmp')
    temporary.write_text(json.dumps({'pid': os.getpid(), 'descendant': descendant, 'threads': active_count()}))
    temporary.replace(path)


def descendant():
    # Remains in the worker's process group; SIGKILL must catch TERM resistance.
    return subprocess.Popen([
        sys.executable, '-c', 'import signal; signal.signal(signal.SIGTERM, signal.SIG_IGN); signal.pause()',
    ], stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).pid


if mode in {'dns', 'dns_shutdown', 'dns_only'}:
    def blocked_dns(*args, **kwargs):
        record(None if mode == 'dns_only' else descendant())
        trace('resolver_entered')
        if mode == 'dns_shutdown':
            http_budgets[0].cancel()
        Event().wait()  # Native resolver executor deliberately never returns.

    socket.getaddrinfo = blocked_dns
elif mode == 'crash_descendant':
    record(descendant())
    os._exit(77)
else:
    record()

if mode == 'crash':
    print('SECRET child crash', file=sys.stderr)
    os._exit(77)
elif mode == 'oversize':
    os.write(1, struct.pack('!I', 65_538))
    Event().wait()
elif mode == 'partial':
    os.write(1, struct.pack('!I', 20) + b'O')
    Event().wait()
elif mode == 'error_secret':
    message = b'ESECRET remote error'
    os.write(1, struct.pack('!I', len(message)) + message)
    os._exit(0)
else:
    trace('http_import_start')
    import httpx
    trace('http_import_ready')
    trace('worker_import_start')
    from app.providers.process_worker import main
    trace('worker_import_ready')

    if mode == 'dns_shutdown':
        from app.providers import openai_compatible
        original_request = openai_compatible.OpenAICompatibleProvider._request
        # Trusted seam: the native resolver signals cancellation only after it
        # has started. asyncio.run must then wait for its blocked executor.
        # No startup timeout or production deadline is extended/reset here.

        async def observed_request(self, payload, budget):
            http_budgets.append(budget)
            try:
                return await original_request(self, payload, budget)
            finally:
                trace('request_scope_exited')

        openai_compatible.OpenAICompatibleProvider._request = observed_request
    elif mode not in {'dns', 'dns_only'}:
        original = httpx.AsyncClient

        def response(request):
            if mode == 'exception':
                raise RuntimeError('SECRET API key and document')
            if mode == 'malformed':
                return httpx.Response(200, content=b'SECRET invalid JSON')
            if mode == 'http_oversize':
                return httpx.Response(200, content=b'x' * 65_537)
            user = json.loads(request.content)['messages'][1]['content']
            requirement = json.loads(user.split('<requirement>')[1].split('</requirement>')[0])
            finding = {'requirement_id': requirement['requirement_id'], 'status': 'missing',
                       'confidence': 0.0, 'justification': 'Sem evidência.', 'requires_human_review': True,
                       'search_summary': 'Busca offline sem evidência.', 'citations': []}
            raw = json.dumps({'choices': [{'finish_reason': 'stop', 'message': {'content': json.dumps(finding)}}]}).encode()
            if mode == 'max_response':
                raw += b' ' * (65_536 - len(raw))
            return httpx.Response(200, content=raw)

        def client(*args, **kwargs):
            kwargs['transport'] = httpx.MockTransport(response)
            return original(*args, **kwargs)

        httpx.AsyncClient = client
    main()
