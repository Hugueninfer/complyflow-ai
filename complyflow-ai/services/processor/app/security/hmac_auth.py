"""HMAC over raw HTTP bytes, with process-local replay protection.

Run one processor worker. Multiple workers/replicas require a shared atomic
nonce store; a process-local cache cannot coordinate replay protection.
"""

import hashlib
import hmac
import os
import re
from collections import OrderedDict
from threading import Lock
from time import monotonic, time as wall_clock
from typing import Literal

from fastapi import HTTPException, Request


class NonceCache:
    """Bounded, atomic claims; live entries are never evicted for new nonces."""

    def __init__(self, capacity: int = 10_000):
        self.capacity = capacity
        self._entries: OrderedDict[str, float] = OrderedDict()
        self._lock = Lock()

    def claim(self, nonce: str, *, now: float | None = None) -> Literal["accepted", "replay", "full"]:
        with self._lock:
            # Read monotonic time inside the lock to preserve expiry order.
            now = monotonic() if now is None else now
            # Both edges of the signature's 60-second window are accepted.
            # Keep the nonce through the exact 120-second endpoint as well.
            while self._entries and next(iter(self._entries.values())) < now:
                self._entries.popitem(last=False)
            if nonce in self._entries:
                return "replay"
            if len(self._entries) >= self.capacity:
                return "full"
            self._entries[nonce] = now + 120
            return "accepted"


nonce_cache = NonceCache()


async def verify_signed_request(request: Request) -> None:
    names = ("X-CF-Timestamp", "X-CF-Nonce", "X-CF-Signature")
    values = [request.headers.getlist(name) for name in names]
    if any(len(value) != 1 for value in values):
        raise HTTPException(401, detail="invalid_authentication")
    timestamp, nonce, signature = [value[0] for value in values]
    if (
        not re.fullmatch(r"[0-9]{1,12}", timestamp)
        or not re.fullmatch(r"[A-Za-z0-9_-]{1,128}", nonce)
        or not re.fullmatch(r"[0-9a-f]{64}", signature)
        or abs(wall_clock() - int(timestamp)) > 60
    ):
        raise HTTPException(401, detail="invalid_authentication")

    secret = os.environ.get("PROCESSOR_HMAC_SECRET")
    if not secret:
        raise HTTPException(503, detail="authentication_unavailable")
    body_hash = hashlib.sha256(await request.body()).hexdigest()
    message = f"{timestamp}\n{nonce}\n{body_hash}".encode("ascii")
    expected = hmac.new(secret.encode("utf-8"), message, hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, signature):
        raise HTTPException(401, detail="invalid_authentication")

    result = nonce_cache.claim(nonce)
    if result == "replay":
        raise HTTPException(401, detail="invalid_authentication")
    if result == "full":
        raise HTTPException(503, detail="authentication_unavailable")
