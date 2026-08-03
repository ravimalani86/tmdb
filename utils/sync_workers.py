"""Parallel helpers for multi-key TMDB sync (detail pages + seasons)."""

from __future__ import annotations

import threading
from concurrent.futures import ThreadPoolExecutor, as_completed
from typing import Any, Callable

import config
from db import get_session
from services.tmdb_client import TMDBClient, create_worker_clients
from utils.logger import get_logger

logger = get_logger(__name__)

checkpoint_lock = threading.Lock()


def partition(items: list, n: int) -> list[list]:
    if n <= 1 or len(items) <= 1:
        return [items]
    n = min(n, len(items))
    return [items[i::n] for i in range(n)]


def run_partitioned(
    items: list,
    worker_fn: Callable[[list, TMDBClient, int], dict],
    label: str = "work",
) -> list[dict]:
    """
    Split items across workers. Each worker gets its own TMDBClient (thread-safe).
    worker_fn(subset, client, worker_id) -> stats dict
    """
    if not items:
        return []

    n = config.effective_workers(len(items))
    if n <= 1:
        clients = create_worker_clients(1)
        try:
            return [worker_fn(items, clients[0], 0)]
        finally:
            clients[0].close()

    subsets = partition(items, n)
    clients = create_worker_clients(len(subsets))
    logger.info(
        "%s — %s items across %s workers (%s keys)",
        label,
        len(items),
        len(subsets),
        len(config.TMDB_CREDENTIALS) or 1,
    )

    results: list[dict] = []
    try:
        with ThreadPoolExecutor(max_workers=len(subsets)) as pool:
            futures = {
                pool.submit(worker_fn, subset, clients[i], i): i
                for i, subset in enumerate(subsets)
                if subset
            }
            for fut in as_completed(futures):
                wid = futures[fut]
                try:
                    results.append(fut.result())
                except Exception as exc:
                    logger.error("%s worker %s failed: %s", label, wid, exc)
                    results.append({"failed": len(subsets[wid]), "error": str(exc)})
    finally:
        for client in clients:
            client.close()

    return results


def merge_count_stats(results: list[dict], keys: tuple[str, ...]) -> dict:
    merged = {k: 0 for k in keys}
    for row in results:
        for k in keys:
            merged[k] += int(row.get(k, 0) or 0)
    return merged


def with_db_session(fn: Callable[..., Any]) -> Any:
    """Run fn(session, ...) with a fresh session (for worker threads)."""
    session = get_session()
    try:
        return fn(session)
    finally:
        session.close()
