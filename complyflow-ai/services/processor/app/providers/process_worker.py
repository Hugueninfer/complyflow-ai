"""One request per exec; parent can kill DNS/native threads and reap the group."""

import json
import math
import os
import resource
import struct
import sys
from time import monotonic

from app.processes import bind_to_parent
from app.providers.process_isolation import MAX_REQUEST_BYTES, MAX_RESPONSE_BYTES, PUBLIC_ERRORS


def _read_exact(stream, size):
    data = bytearray()
    while len(data) < size:
        part = stream.read(min(size - len(data), 8192))
        if not part:
            raise ValueError
        data.extend(part)
    return bytes(data)


def main() -> None:
    writer = os.dup(sys.stdout.fileno())
    # stdout is IPC only; stray prints and diagnostics never reach the caller.
    with open(os.devnull, 'wb') as sink:
        os.dup2(sink.fileno(), sys.stdout.fileno())
        os.dup2(sink.fileno(), sys.stderr.fileno())
    message = b'Eprovider_unavailable'
    try:
        resource.setrlimit(resource.RLIMIT_AS, (256 * 1024 * 1024,) * 2)
        resource.setrlimit(resource.RLIMIT_CPU, (20, 20))
        bind_to_parent(int(os.environ['CF_PROCESSOR_PARENT_PID']))
        size = struct.unpack('!I', _read_exact(sys.stdin.buffer, 4))[0]
        if not 1 <= size <= MAX_REQUEST_BYTES:
            raise ValueError
        request = json.loads(_read_exact(sys.stdin.buffer, size))
        remaining = request['deadline'] - monotonic()
        if not math.isfinite(remaining) or not 0 < remaining <= 40:
            raise ValueError
        import anyio
        from app.execution import ExecutionBudget
        from app.providers.openai_compatible import OpenAICompatibleProvider

        provider = OpenAICompatibleProvider(
            base_url=request['base_url'], api_key=request['api_key'], model=request['payload']['model'],
        )
        raw = anyio.run(provider._request, request['payload'], ExecutionBudget(request['deadline'] - monotonic()))
        message = b'O' + raw if len(raw) <= MAX_RESPONSE_BYTES else b'Einvalid_provider_response'
    except BaseException as error:
        candidate = str(error)
        message = b'E' + (candidate if candidate in PUBLIC_ERRORS else 'provider_unavailable').encode('ascii')
    try:
        framed = struct.pack('!I', len(message)) + message
        offset = 0
        while offset < len(framed):
            offset += os.write(writer, framed[offset:offset + 8192])
    except BaseException:
        pass
    finally:
        os.close(writer)


if __name__ == '__main__':
    main()
