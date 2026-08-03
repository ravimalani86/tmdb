"""Retry helpers for transient MySQL lock conflicts (deadlock 1213, lock wait 1205)."""

from __future__ import annotations

import random
import time
from collections.abc import Callable
from typing import TypeVar

from sqlalchemy.exc import OperationalError

from utils.logger import get_logger

logger = get_logger(__name__)

# Parallel season workers collide on credits/people — need room to back off.
DEFAULT_DEADLOCK_RETRIES = 8
DEFAULT_DEADLOCK_BASE_SLEEP = 0.1
DEFAULT_DEADLOCK_MAX_SLEEP = 2.0

_RETRYABLE_MYSQL_CODES = {1205, 1213}

T = TypeVar("T")


def _mysql_errno(exc: BaseException) -> int | None:
    if isinstance(exc, OperationalError):
        orig = getattr(exc, "orig", None)
        if orig is not None and getattr(orig, "args", None):
            try:
                return int(orig.args[0])
            except (TypeError, ValueError):
                return None
    return None


def is_deadlock(exc: BaseException) -> bool:
    """True for deadlock (1213) or lock-wait timeout (1205)."""
    code = _mysql_errno(exc)
    if code in _RETRYABLE_MYSQL_CODES:
        return True
    msg = str(exc)
    return (
        ("1213" in msg and "Deadlock" in msg)
        or ("1205" in msg and "Lock wait timeout" in msg)
    )


def run_with_deadlock_retry(
    fn: Callable[[], T],
    *,
    rollback: Callable[[], None] | None = None,
    retries: int = DEFAULT_DEADLOCK_RETRIES,
    base_sleep: float = DEFAULT_DEADLOCK_BASE_SLEEP,
    max_sleep: float = DEFAULT_DEADLOCK_MAX_SLEEP,
    label: str = "db",
) -> T:
    last_exc: BaseException | None = None
    for attempt in range(1, retries + 1):
        try:
            return fn()
        except Exception as exc:
            last_exc = exc
            if rollback:
                rollback()
            if is_deadlock(exc) and attempt < retries:
                # Exponential backoff + jitter so workers don't stampede together.
                delay = min(max_sleep, base_sleep * (2 ** (attempt - 1)))
                delay += random.uniform(0, base_sleep)
                logger.warning(
                    "%s deadlock (attempt %s/%s) — retrying in %.2fs",
                    label,
                    attempt,
                    retries,
                    delay,
                )
                time.sleep(delay)
                continue
            raise
    assert last_exc is not None
    raise last_exc
