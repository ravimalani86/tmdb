"""
Daily cron sync: enqueue today's provider-catalog changes, then process queue
with all TMDB API keys in parallel.
"""

from __future__ import annotations

from datetime import datetime, timedelta
from zoneinfo import ZoneInfo

from sqlalchemy import func, select, text, update
from sqlalchemy.dialects.mysql import insert as mysql_insert
from sqlalchemy.orm import Session

import config
from models.movie import Movie
from models.people import Person
from models.sync_state import MediaSyncQueue
from models.tv import TvShow
from repositories.movie_repo import find_movie_by_tmdb_id
from repositories.tv_repo import find_tv_by_tmdb_id
from services.full_item_sync import (
    credit_person_tmdb_ids,
    sync_movie_full,
    sync_person_full,
    sync_tv_full,
)
from services.tmdb_client import TMDBClient
from utils.logger import get_logger
from utils.provider_config import load_provider_jobs
from utils.sync_workers import merge_count_stats, run_partitioned

logger = get_logger(__name__)

IST = ZoneInfo("Asia/Kolkata")
MAX_ATTEMPTS = 3


def sync_day_today() -> str:
    """Calendar day in IST (aaj)."""
    return datetime.now(IST).date().isoformat()


def _enqueue_row(
    session: Session,
    media_type: str,
    tmdb_id: int,
    sync_day: str,
    source: str,
) -> bool:
    """Insert pending row if not already queued for this day. Returns True if new."""
    stmt = (
        mysql_insert(MediaSyncQueue)
        .prefix_with("IGNORE")
        .values(
            media_type=media_type,
            tmdb_id=int(tmdb_id),
            sync_day=sync_day,
            source=source,
            status="pending",
            attempts=0,
        )
    )
    result = session.execute(stmt)
    return (result.rowcount or 0) == 1


def _allowed_provider_pairs(jobs: list[dict]) -> set[tuple[str, int]]:
    return {(j["region"].upper(), int(j["ids"])) for j in jobs}


def _matches_providers(client: TMDBClient, media_type: str, tmdb_id: int, pairs: set[tuple[str, int]]) -> bool:
    path = f"{'movie' if media_type == 'movie' else 'tv'}/{tmdb_id}/watch/providers"
    data = client.get(path) or {}
    results = data.get("results") or {}
    monetization = "flatrate"
    try:
        _, mon = load_provider_jobs()
        if mon:
            monetization = mon
    except Exception:
        pass

    for region, provider_id in pairs:
        block = results.get(region) or {}
        for bucket in (monetization, "flatrate", "ads", "free"):
            for p in block.get(bucket) or []:
                if int(p.get("provider_id") or 0) == provider_id:
                    return True
    return False


def _paginate_changes(client: TMDBClient, path: str, day: str) -> list[int]:
    ids: list[int] = []
    params = {"start_date": day, "end_date": day}
    for _page, data in client.paginate(path, params):
        for item in data.get("results") or []:
            tid = item.get("id")
            if tid:
                ids.append(int(tid))
    return ids


def _discover_ids_for_day(
    client: TMDBClient,
    media_type: str,
    day: str,
    jobs: list[dict],
    monetization: str,
) -> list[int]:
    """New releases / airing today on configured providers."""
    seen: set[int] = set()
    out: list[int] = []

    for job in jobs:
        region = job["region"]
        pid = job["ids"]
        if media_type == "movie":
            params = {
                "with_watch_providers": pid,
                "watch_region": region,
                "with_watch_monetization_types": monetization,
                "primary_release_date.gte": day,
                "primary_release_date.lte": day,
                "sort_by": "popularity.desc",
            }
            path = "discover/movie"
        else:
            # first air today
            params = {
                "with_watch_providers": pid,
                "watch_region": region,
                "with_watch_monetization_types": monetization,
                "first_air_date.gte": day,
                "first_air_date.lte": day,
                "sort_by": "popularity.desc",
            }
            path = "discover/tv"

        for _page, data in client.paginate(path, params):
            for item in data.get("results") or []:
                tid = int(item["id"])
                if tid not in seen:
                    seen.add(tid)
                    out.append(tid)

        if media_type == "tv":
            # Episodes airing today -> parent shows
            air_params = {
                "with_watch_providers": pid,
                "watch_region": region,
                "with_watch_monetization_types": monetization,
                "air_date.gte": day,
                "air_date.lte": day,
                "sort_by": "popularity.desc",
            }
            for _page, data in client.paginate("discover/tv", air_params):
                for item in data.get("results") or []:
                    tid = int(item["id"])
                    if tid not in seen:
                        seen.add(tid)
                        out.append(tid)

    return out


def enqueue_daily_changes(
    session: Session,
    client: TMDBClient,
    sync_day: str | None = None,
) -> dict:
    """
    Fill media_sync_queue for IST calendar day (providers_config catalog):

    Movies/TV updates: TMDB /changes ∩ IDs already in local DB
      (DB is already provider-scoped from Phase-1 discover)
    Movies/TV new: discover release/air date = today + with_watch_providers
    People: /person/changes ∩ people already in local DB
    """
    day = sync_day or sync_day_today()
    jobs, monetization = load_provider_jobs()
    if not jobs:
        raise RuntimeError("providers_config required (SYNC_PROVIDERS_FILE)")

    stats = {
        "sync_day": day,
        "movie_enqueued": 0,
        "tv_enqueued": 0,
        "person_enqueued": 0,
        "movie_skipped": 0,
        "tv_skipped": 0,
        "person_skipped": 0,
    }

    logger.info("Enqueue daily changes for %s (%s provider jobs)", day, len(jobs))

    movie_ids_in_db = {
        int(r[0]) for r in session.query(Movie.tmdb_id).filter(Movie.is_active == True).all()  # noqa: E712
    }
    tv_ids_in_db = {
        int(r[0]) for r in session.query(TvShow.tmdb_id).filter(TvShow.is_active == True).all()  # noqa: E712
    }
    logger.info(
        "Local catalog sizes — movies=%s tv=%s",
        len(movie_ids_in_db),
        len(tv_ids_in_db),
    )

    # --- Movies: changes ∩ DB ---
    movie_change_ids = _paginate_changes(client, "movie/changes", day)
    logger.info("movie/changes -> %s ids", len(movie_change_ids))
    for i, tid in enumerate(movie_change_ids, start=1):
        if tid not in movie_ids_in_db:
            stats["movie_skipped"] += 1
            continue
        if _enqueue_row(session, "movie", tid, day, "changes"):
            stats["movie_enqueued"] += 1
        else:
            stats["movie_skipped"] += 1
        if i % 50 == 0:
            session.commit()
    session.commit()

    # --- Movies: discover release today on providers ---
    for tid in _discover_ids_for_day(client, "movie", day, jobs, monetization or "flatrate"):
        if _enqueue_row(session, "movie", tid, day, "discover"):
            stats["movie_enqueued"] += 1
            movie_ids_in_db.add(tid)
        else:
            stats["movie_skipped"] += 1
    session.commit()

    # --- TV: changes ∩ DB ---
    tv_change_ids = _paginate_changes(client, "tv/changes", day)
    logger.info("tv/changes -> %s ids", len(tv_change_ids))
    for i, tid in enumerate(tv_change_ids, start=1):
        if tid not in tv_ids_in_db:
            stats["tv_skipped"] += 1
            continue
        if _enqueue_row(session, "tv", tid, day, "changes"):
            stats["tv_enqueued"] += 1
        else:
            stats["tv_skipped"] += 1
        if i % 50 == 0:
            session.commit()
    session.commit()

    # --- TV: discover ---
    for tid in _discover_ids_for_day(client, "tv", day, jobs, monetization or "flatrate"):
        if _enqueue_row(session, "tv", tid, day, "discover"):
            stats["tv_enqueued"] += 1
            tv_ids_in_db.add(tid)
        else:
            stats["tv_skipped"] += 1
    session.commit()

    # --- People: changes ∩ DB (batched; people table is large) ---
    person_change_ids = _paginate_changes(client, "person/changes", day)
    logger.info("person/changes -> %s ids", len(person_change_ids))
    for i in range(0, len(person_change_ids), 500):
        chunk = person_change_ids[i : i + 500]
        existing = {
            int(r[0])
            for r in session.query(Person.tmdb_id).filter(Person.tmdb_id.in_(chunk)).all()
        }
        for tid in chunk:
            if tid not in existing:
                stats["person_skipped"] += 1
                continue
            if _enqueue_row(session, "person", tid, day, "changes"):
                stats["person_enqueued"] += 1
            else:
                stats["person_skipped"] += 1
        session.commit()

    logger.info("Enqueue done: %s", stats)
    return stats


def _claim_pending(session: Session, limit: int, worker_id: int) -> list[MediaSyncQueue]:
    """Claim pending rows (MySQL SKIP LOCKED when available)."""
    try:
        rows = session.execute(
            text(
                """
                SELECT id FROM media_sync_queue
                WHERE status = 'pending' AND attempts < :max_attempts
                ORDER BY id ASC
                LIMIT :lim
                FOR UPDATE SKIP LOCKED
                """
            ),
            {"max_attempts": MAX_ATTEMPTS, "lim": limit},
        ).fetchall()
    except Exception:
        session.rollback()
        rows = (
            session.query(MediaSyncQueue.id)
            .filter(
                MediaSyncQueue.status == "pending",
                MediaSyncQueue.attempts < MAX_ATTEMPTS,
            )
            .order_by(MediaSyncQueue.id.asc())
            .limit(limit)
            .with_for_update()
            .all()
        )

    ids = [int(r[0]) for r in rows]
    if not ids:
        return []

    now = datetime.utcnow()
    session.execute(
        update(MediaSyncQueue)
        .where(MediaSyncQueue.id.in_(ids))
        .values(
            status="running",
            worker_id=worker_id,
            started_at=now,
            attempts=MediaSyncQueue.attempts + 1,
        )
    )
    session.commit()
    return (
        session.query(MediaSyncQueue)
        .filter(MediaSyncQueue.id.in_(ids))
        .order_by(MediaSyncQueue.id.asc())
        .all()
    )


def _mark_done(session: Session, row_id: int) -> None:
    session.execute(
        update(MediaSyncQueue)
        .where(MediaSyncQueue.id == row_id)
        .values(status="done", finished_at=datetime.utcnow(), last_error=None)
    )
    session.commit()


def _mark_failed(session: Session, row_id: int, error: str, attempts: int) -> None:
    status = "failed" if attempts >= MAX_ATTEMPTS else "pending"
    session.execute(
        update(MediaSyncQueue)
        .where(MediaSyncQueue.id == row_id)
        .values(
            status=status,
            finished_at=datetime.utcnow() if status == "failed" else None,
            last_error=(error or "")[:2000],
            worker_id=None,
        )
    )
    session.commit()


def _enqueue_people_from_media(
    session: Session,
    sync_day: str,
    media_type: str,
    media_local_id: int,
) -> int:
    n = 0
    for pid in credit_person_tmdb_ids(session, media_type, media_local_id):
        if _enqueue_row(session, "person", pid, sync_day, "credits"):
            n += 1
    if n:
        session.commit()
    return n


def _process_one(session: Session, client: TMDBClient, row: MediaSyncQueue) -> dict:
    stats = {"done": 0, "failed": 0, "people_enqueued": 0}
    try:
        if row.media_type == "movie":
            sync_movie_full(session, client, row.tmdb_id)
            movie = find_movie_by_tmdb_id(session, row.tmdb_id)
            if movie:
                stats["people_enqueued"] += _enqueue_people_from_media(
                    session, row.sync_day, "movie", movie.id
                )
        elif row.media_type == "tv":
            sync_tv_full(session, client, row.tmdb_id)
            show = find_tv_by_tmdb_id(session, row.tmdb_id)
            if show:
                stats["people_enqueued"] += _enqueue_people_from_media(
                    session, row.sync_day, "tv", show.id
                )
        elif row.media_type == "person":
            sync_person_full(session, client, row.tmdb_id)
        else:
            raise RuntimeError(f"Unknown media_type {row.media_type}")

        _mark_done(session, row.id)
        stats["done"] = 1
    except Exception as exc:
        logger.error(
            "Queue #%s %s/%s failed: %s",
            row.id,
            row.media_type,
            row.tmdb_id,
            exc,
        )
        session.rollback()
        _mark_failed(session, row.id, str(exc), row.attempts)
        stats["failed"] = 1
    return stats


def process_queue(
    session: Session,
    client: TMDBClient | None = None,
    batch_size: int = 40,
    max_batches: int = 500,
) -> dict:
    """
    Process pending queue using all API keys (SYNC_WORKERS).
    Claims batches repeatedly until empty or max_batches.
    """
    del client  # workers create their own clients
    total = {"done": 0, "failed": 0, "people_enqueued": 0, "batches": 0}

    for _ in range(max_batches):
        # Peek pending count
        pending = (
            session.query(func.count(MediaSyncQueue.id))
            .filter(
                MediaSyncQueue.status == "pending",
                MediaSyncQueue.attempts < MAX_ATTEMPTS,
            )
            .scalar()
            or 0
        )
        if pending == 0:
            break

        # Claim a chunk without locking long — then partition across workers
        claimed = _claim_pending(session, batch_size, worker_id=-1)
        if not claimed:
            break

        total["batches"] += 1
        items = [(r.id, r.media_type, r.tmdb_id, r.sync_day, r.attempts) for r in claimed]

        def worker_fn(subset: list, worker_client: TMDBClient, worker_id: int) -> dict:
            from db import get_session

            wsession = get_session()
            local = {"done": 0, "failed": 0, "people_enqueued": 0}
            try:
                for row_id, media_type, tmdb_id, sync_day, attempts in subset:
                    row = MediaSyncQueue(
                        id=row_id,
                        media_type=media_type,
                        tmdb_id=tmdb_id,
                        sync_day=sync_day,
                        attempts=attempts,
                        status="running",
                    )
                    # refresh attempts from DB
                    db_row = wsession.get(MediaSyncQueue, row_id)
                    if db_row is None:
                        continue
                    wsession.execute(
                        update(MediaSyncQueue)
                        .where(MediaSyncQueue.id == row_id)
                        .values(worker_id=worker_id)
                    )
                    wsession.commit()
                    part = _process_one(wsession, worker_client, db_row)
                    for k in local:
                        local[k] += part.get(k, 0)
            finally:
                wsession.close()
            return local

        results = run_partitioned(items, worker_fn, label="daily-queue")
        merged = merge_count_stats(results, ("done", "failed", "people_enqueued"))
        for k, v in merged.items():
            total[k] += v

        logger.info(
            "Batch done — pending left ~%s | totals %s",
            pending - len(claimed),
            total,
        )

    logger.info("Process queue finished: %s", total)
    return total


def queue_status(session: Session, sync_day: str | None = None) -> dict:
    day = sync_day or sync_day_today()
    rows = (
        session.query(MediaSyncQueue.status, func.count(MediaSyncQueue.id))
        .filter(MediaSyncQueue.sync_day == day)
        .group_by(MediaSyncQueue.status)
        .all()
    )
    by_status = {status: int(cnt) for status, cnt in rows}
    by_type = (
        session.query(MediaSyncQueue.media_type, func.count(MediaSyncQueue.id))
        .filter(MediaSyncQueue.sync_day == day)
        .group_by(MediaSyncQueue.media_type)
        .all()
    )
    return {
        "sync_day": day,
        "by_status": by_status,
        "by_type": {t: int(c) for t, c in by_type},
        "pending": by_status.get("pending", 0),
        "running": by_status.get("running", 0),
        "done": by_status.get("done", 0),
        "failed": by_status.get("failed", 0),
        "total": sum(by_status.values()),
        "workers": config.effective_workers(),
        "api_keys": len(config.TMDB_CREDENTIALS) or 1,
    }


def run_daily_sync(session: Session, client: TMDBClient) -> dict:
    enq = enqueue_daily_changes(session, client)
    proc = process_queue(session)
    status = queue_status(session, enq.get("sync_day"))
    return {"enqueue": enq, "process": proc, "status": status}
