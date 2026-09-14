"""A monotonic deadline shared by every operation of one analysis attempt."""

import hashlib
import math
import os
from contextlib import contextmanager
from threading import Event, Lock
from time import monotonic


class ExecutionStopped(RuntimeError):
    """Sanitized execution/configuration failure; never carries document content."""


def configured_seconds(name: str, default: float, maximum: float) -> float:
    try:
        value = float(os.getenv(name, str(default)))
    except ValueError:
        raise ExecutionStopped('analysis_not_configured') from None
    if not math.isfinite(value) or not 0 < value <= maximum:
        raise ExecutionStopped('analysis_not_configured')
    return value


class ExecutionBudget:
    def __init__(self, seconds: float, *, clock=monotonic):
        self.clock = clock
        self.deadline = clock() + seconds
        self._cancelled = Event()

    @classmethod
    def from_settings(cls, *, clock=monotonic):
        # Leave at least 15 s for transport, serialization and cleanup before
        # Laravel's 60 s HTTP timeout; deployments may only shorten this budget.
        return cls(configured_seconds('PROCESSOR_ANALYSIS_TIMEOUT_SECONDS', 45, 45), clock=clock)

    def remaining(self, maximum: float | None = None) -> float:
        remaining = self.deadline - self.clock()
        if remaining <= 0:
            raise ExecutionStopped('analysis_budget_exceeded')
        if self.cancelled:
            raise ExecutionStopped('analysis_cancelled')
        return remaining if maximum is None else min(remaining, maximum)

    def checkpoint(self) -> None:
        self.remaining()

    @property
    def cancelled(self) -> bool:
        return self._cancelled.is_set()

    def cancel(self) -> None:
        self._cancelled.set()


class ActiveAnalyses:
    """One active attempt fits the free runtime; no result cache or expiring lease.

    Only the worker that acquired the slot releases it, after all its cleanup.
    A cancelled HTTP waiter cannot release still-running work for a retry.
    Durable idempotency and terminal state remain Laravel's responsibility.
    """

    def __init__(self):
        self._active = {}
        self._lock = Lock()

    @contextmanager
    def lease(self, analysis_id, idempotency_key: str):
        key_hash = hashlib.sha256(idempotency_key.encode()).digest()
        with self._lock:
            if analysis_id in self._active or key_hash in self._active.values():
                raise ExecutionStopped('analysis_in_progress')
            if self._active:
                raise ExecutionStopped('analysis_capacity_exceeded')
            self._active[analysis_id] = key_hash
        try:
            yield
        finally:
            with self._lock:
                del self._active[analysis_id]
