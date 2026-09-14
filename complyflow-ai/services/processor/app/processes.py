"""Linux process-group cleanup, including adopted orphan descendants."""

import ctypes
import os
import signal


def enable_child_reaping() -> None:
    # The single processor process owns isolated children. Reap only their PGID,
    # never unrelated children. Without subreaping, container PID 1 may retain
    # zombies when a timed-out child dies before its own descendants.
    if ctypes.CDLL(None, use_errno=True).prctl(36, 1, 0, 0, 0) != 0:  # PR_SET_CHILD_SUBREAPER
        raise OSError('child_reaping_unavailable')


def bind_to_parent(expected_parent: int) -> None:
    # Native HTTP/DNS threads must die even when the server cannot run finally
    # (SIGKILL/crash). Check after prctl to close the parent-exited setup race.
    if ctypes.CDLL(None, use_errno=True).prctl(1, signal.SIGKILL, 0, 0, 0) != 0:  # PR_SET_PDEATHSIG
        raise OSError('parent_lifetime_unavailable')
    if expected_parent <= 0 or os.getppid() != expected_parent:
        raise OSError('parent_exited')


def signal_group(pgid: int, signum: int) -> None:
    try:
        os.killpg(pgid, signum)
    except ProcessLookupError:
        pass


def reap_group(pgid: int) -> None:
    while True:
        try:
            os.waitpid(-pgid, 0)
        except ChildProcessError:
            return
