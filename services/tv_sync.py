import time

from sqlalchemy.orm import Session

from datetime import datetime, timezone

from models.sync_state import TvSeasonSyncJob
from models.tv import TvEpisode, TvSeason, TvShow
from repositories.tv_repo import (
    upsert_tv_episode,
    upsert_tv_season,
    upsert_tv_show,
)
from services.movie_sync import (
    _sync_episode_credits,
    _sync_images,
    _sync_keywords,
    _sync_recommendations,
    _sync_similar,
    _sync_tv_aggregate_credits,
    _sync_videos,
    _sync_watch_providers,
)
from services.tmdb_client import TMDBClient
from utils.checkpoint import get_checkpoint, record_metrics, save_checkpoint
from utils.logger import get_logger
from utils.provider_config import load_provider_jobs
from utils.db_retry import run_with_deadlock_retry
from utils.sync_workers import merge_count_stats, run_partitioned

import config

logger = get_logger(__name__)

TV_LIST_ENDPOINTS = ["tv/popular", "tv/top_rated"]
TV_SEASONS_ENTITY = "tv_seasons"
# Continuous queue: when pending <= workers, enqueue workers * multiplier more.
_SEASON_QUEUE_MULTIPLIER = 2
_SEASON_QUEUE_IDLE_SLEEP = 0.3


def sync_tv_shows(session: Session, client: TMDBClient) -> dict:
    """All-in-one lite sync. For step-by-step use sync_tv_detail/credits/etc."""
    logger.info("TV all-in-one — running phased sync sequentially")
    stats = {}
    for name, fn in (
        ("detail", sync_tv_detail),
        ("credits", sync_tv_credits),
        ("providers", sync_tv_providers),
        ("similar", sync_tv_similar),
        ("seasons", sync_tv_seasons),
        ("videos", sync_tv_videos),
        ("images", sync_tv_images),
        ("keywords", sync_tv_keywords),
        ("recommendations", sync_tv_recommendations),
    ):
        logger.info("TV all-in-one — starting %s", name)
        stats[name] = fn(session, client)
    return stats


def sync_tv_detail(session: Session, client: TMDBClient) -> dict:
    """Phase 1: TV list + GET /tv/{id} only (poster, banner, rating, genre, language)."""
    jobs, monetization = load_provider_jobs()
    if jobs:
        return _sync_tv_detail_by_provider(session, client, jobs, monetization)
    return _sync_tv_detail_from_lists(session, client)


def _sync_tv_detail_from_lists(session: Session, client: TMDBClient) -> dict:
    start = time.time()
    stats = {"inserted": 0, "updated": 0, "skipped": 0, "failed": 0, "total": 0}
    checkpoint = get_checkpoint(session, "tv_detail")
    start_page = (checkpoint.last_processed_page + 1) if checkpoint else 1

    logger.info("=== Phase 1: TV DETAIL from lists (page %s) ===", start_page)
    seen_ids: set[int] = set()

    for endpoint in TV_LIST_ENDPOINTS:
        logger.info("Fetching %s...", endpoint)
        for page, data in client.paginate(endpoint, start_page=start_page):
            logger.info("Page %s fetched — %s results", page, len(data.get("results", [])))
            chunk_stats = _process_tv_chunk(session, client, data["results"], seen_ids)
            for k in ("inserted", "updated", "skipped", "failed", "total"):
                stats[k] += chunk_stats[k]
            save_checkpoint(session, "tv_detail", page, status="running")
            session.commit()
            logger.info(
                "Detail chunk page %s — inserted %s, updated %s, skipped %s",
                page, chunk_stats["inserted"], chunk_stats["updated"], chunk_stats["skipped"],
            )

    save_checkpoint(session, "tv_detail", 0, status="completed")
    elapsed = time.time() - start
    record_metrics(
        session, "tv_detail", stats["total"],
        stats["inserted"], stats["updated"], stats["skipped"],
        stats["failed"], elapsed,
    )
    session.commit()
    logger.info("Phase 1 DONE — %s TV shows processed", stats["total"])
    return stats


def _sync_tv_detail_by_provider(
    session: Session,
    client: TMDBClient,
    jobs: list[dict],
    monetization: str,
) -> dict:
    """Phase 1 via discover API — TV one provider at a time."""
    start = time.time()
    stats = {"inserted": 0, "updated": 0, "skipped": 0, "failed": 0, "total": 0}
    seen_ids: set[int] = set()

    logger.info("=== Phase 1: TV DETAIL by PROVIDER (%s providers) ===", len(jobs))

    for job in jobs:
        region = job["region"]
        provider_ids = job["ids"]
        names = ", ".join(job.get("names", [])) or provider_ids
        entity = f"tv_detail_provider_{region}_{provider_ids}"
        checkpoint = get_checkpoint(session, entity)
        if checkpoint and checkpoint.status == "completed":
            logger.info(
                "Provider %s (%s) | region %s | already completed — skipping",
                names, provider_ids, region,
            )
            continue
        start_page = (checkpoint.last_processed_page + 1) if checkpoint else 1

        discover_params = {
            "with_watch_providers": provider_ids,
            "watch_region": region,
            "with_watch_monetization_types": monetization,
            "sort_by": "popularity.desc",
        }
        logger.info(
            "Provider %s (%s) | region %s | from page %s",
            names, provider_ids, region, start_page,
        )

        for page, data in client.paginate("discover/tv", discover_params, start_page=start_page):
            total_pages = data.get("total_pages", "?")
            total_results = data.get("total_results", "?")
            logger.info(
                "Discover TV [%s/%s] page %s/%s — %s results (provider total: %s)",
                names, region, page, total_pages, len(data.get("results", [])), total_results,
            )
            chunk_stats = _process_tv_chunk(session, client, data["results"], seen_ids)
            for k in ("inserted", "updated", "skipped", "failed", "total"):
                stats[k] += chunk_stats[k]
            save_checkpoint(session, entity, page, status="running")
            session.commit()
            logger.info(
                "[%s/%s] page %s — inserted %s, updated %s, skipped %s",
                names, region, page, chunk_stats["inserted"], chunk_stats["updated"], chunk_stats["skipped"],
            )

        save_checkpoint(session, entity, 0, status="completed")
        session.commit()
        logger.info("Provider %s (%s) complete", names, region)

    elapsed = time.time() - start
    record_metrics(
        session, "tv_detail_provider", stats["total"],
        stats["inserted"], stats["updated"], stats["skipped"],
        stats["failed"], elapsed,
    )
    session.commit()
    logger.info("Phase 1 (provider filter) DONE — %s TV shows processed", stats["total"])
    return stats


def sync_tv_credits(session: Session, client: TMDBClient) -> dict:
    """Phase 2: GET /tv/{id}/aggregate_credits (cast/crew + episode_count per role)."""
    return _sync_tv_phase(
        session, client,
        entity_type="tv_credits",
        phase_name="TV credits",
        sync_fn=lambda s, c, show: _sync_tv_aggregate_credits(s, c, show.id, show.tmdb_id),
    )


def sync_tv_providers(session: Session, client: TMDBClient) -> dict:
    """Phase 3: GET /tv/{id}/watch/providers for every TV show in DB."""
    return _sync_tv_phase(
        session, client,
        entity_type="tv_providers",
        phase_name="TV watch providers",
        sync_fn=lambda s, c, show: _sync_watch_providers(s, c, "tv", show.id, show.tmdb_id),
    )


def sync_tv_similar(session: Session, client: TMDBClient) -> dict:
    """Phase 4: GET /tv/{id}/similar for every TV show in DB."""
    return _sync_tv_phase(
        session, client,
        entity_type="tv_similar",
        phase_name="Similar TV shows",
        sync_fn=lambda s, c, show: _sync_similar(s, c, "tv", show.id, show.tmdb_id),
    )


def _tv_seasons_start_id(session: Session) -> int:
    """
    sync_checkpoint.tv_seasons.last_processed_id = next show id to START from (inclusive).

    Example: last_processed_id=11 → start at show 11; shows with id < 11 are done.
    """
    checkpoint = get_checkpoint(session, TV_SEASONS_ENTITY)
    return (checkpoint.last_processed_id or 0) if checkpoint else 0


def _migrate_legacy_tv_seasons_checkpoint(session: Session) -> None:
    """
    Old mid-show checkpoint used last_processed_page=season on entity tv_seasons.
    Move season progress into tv_season_sync_jobs; keep last_processed_id as start cursor.
    """
    checkpoint = get_checkpoint(session, TV_SEASONS_ENTITY)
    if not checkpoint:
        return
    if checkpoint.last_processed_page == -1:
        return
    if checkpoint.last_processed_id is None:
        return

    show_id = checkpoint.last_processed_id
    season = checkpoint.last_processed_season
    if season is None and checkpoint.last_processed_page is not None:
        season = checkpoint.last_processed_page

    show = session.query(TvShow).filter(TvShow.id == show_id).first()
    if show:
        job = (
            session.query(TvSeasonSyncJob)
            .filter(TvSeasonSyncJob.tv_show_id == show_id)
            .first()
        )
        if job is None:
            job = TvSeasonSyncJob(
                tv_show_id=show.id,
                tmdb_id=show.tmdb_id,
                status="pending",
                last_processed_season=season if season is not None and season >= 0 else None,
            )
            session.add(job)
        elif job.status != "completed":
            if season is not None and season >= 0:
                if (
                    job.last_processed_season is None
                    or season > job.last_processed_season
                ):
                    job.last_processed_season = season
            job.status = "pending"

    # Keep start cursor on this show (id=11 means resume/start at 11).
    save_checkpoint(
        session,
        TV_SEASONS_ENTITY,
        -1,
        last_id=show_id,
        last_season=None,
        status="running",
    )
    session.commit()
    logger.info(
        "Migrated legacy tv_seasons checkpoint -> start_id=%s, job season=%s",
        show_id,
        season,
    )


def _reset_retryable_season_jobs(session: Session) -> int:
    """Crash + retry: running/failed -> pending once per process run."""
    now = datetime.now(timezone.utc)
    updated = (
        session.query(TvSeasonSyncJob)
        .filter(TvSeasonSyncJob.status.in_(("running", "failed")))
        .update(
            {
                TvSeasonSyncJob.status: "pending",
                TvSeasonSyncJob.updated_at: now,
            },
            synchronize_session=False,
        )
    )
    if updated:
        session.commit()
        logger.info("Reset %s tv_season_sync_jobs (running/failed) -> pending", updated)
    return updated


def _ensure_season_job(session: Session, show: TvShow) -> TvSeasonSyncJob:
    job = (
        session.query(TvSeasonSyncJob)
        .filter(TvSeasonSyncJob.tv_show_id == show.id)
        .first()
    )
    if job is None:
        job = TvSeasonSyncJob(
            tv_show_id=show.id,
            tmdb_id=show.tmdb_id,
            status="pending",
            last_processed_season=None,
        )
        session.add(job)
        session.flush()
    return job


def _count_season_jobs(session: Session, status: str) -> int:
    return (
        session.query(TvSeasonSyncJob)
        .filter(TvSeasonSyncJob.status == status)
        .count()
    )


def _enqueue_next_season_jobs(session: Session, limit: int) -> int:
    """Create pending job rows for the next shows that have no job yet."""
    if limit <= 0:
        return 0

    start_id = _tv_seasons_start_id(session)
    q = (
        session.query(TvShow)
        .outerjoin(TvSeasonSyncJob, TvSeasonSyncJob.tv_show_id == TvShow.id)
        .filter(TvSeasonSyncJob.id.is_(None))
        .order_by(TvShow.id)
    )
    if start_id > 0:
        q = q.filter(TvShow.id >= start_id)

    added = 0
    for show in q.limit(limit):
        session.add(
            TvSeasonSyncJob(
                tv_show_id=show.id,
                tmdb_id=show.tmdb_id,
                status="pending",
                last_processed_season=None,
            )
        )
        added += 1

    if added:
        session.commit()
        logger.info("TV seasons queue — enqueued %s pending shows", added)
    else:
        session.rollback()
    return added


def _refill_season_queue_if_needed(session: Session, workers: int) -> int:
    """If pending <= workers, enqueue workers * multiplier more shows."""
    pending = _count_season_jobs(session, "pending")
    if pending > workers:
        session.rollback()
        return 0
    batch = max(workers, workers * _SEASON_QUEUE_MULTIPLIER)
    return _enqueue_next_season_jobs(session, batch)


def _claim_next_pending_season_job(session: Session) -> TvSeasonSyncJob | None:
    """
    Take the next pending job (ordered by tv_show_id).
    Caller must hold the process queue lock (MariaDB may lack SKIP LOCKED).
    """
    try:
        session.rollback()
        job = (
            session.query(TvSeasonSyncJob)
            .filter(TvSeasonSyncJob.status == "pending")
            .order_by(TvSeasonSyncJob.tv_show_id)
            .first()
        )
        if not job:
            session.rollback()
            return None

        job_id = job.id
        now = datetime.now(timezone.utc)
        # Drop identity-map row before UPDATE to avoid ORM lock weirdness
        session.expire(job)
        updated = (
            session.query(TvSeasonSyncJob)
            .filter(
                TvSeasonSyncJob.id == job_id,
                TvSeasonSyncJob.status == "pending",
            )
            .update(
                {
                    TvSeasonSyncJob.status: "running",
                    TvSeasonSyncJob.error_message: None,
                    TvSeasonSyncJob.updated_at: now,
                },
                synchronize_session=False,
            )
        )
        session.commit()
        if not updated:
            return None

        return (
            session.query(TvSeasonSyncJob)
            .filter(TvSeasonSyncJob.id == job_id)
            .first()
        )
    except Exception:
        session.rollback()
        raise


def _advance_tv_seasons_start_id(session: Session) -> int:
    """
    Advance last_processed_id (= next start id) only through contiguous completed
    jobs. Never skips holes.

    last_processed_id=11 means start at 11 (ids < 11 done).
    After 11+12 complete and 13 pending → last_processed_id becomes 13.
    """
    start_id = _tv_seasons_start_id(session)
    q = session.query(TvShow.id).order_by(TvShow.id)
    if start_id > 0:
        q = q.filter(TvShow.id >= start_id)
    shows = q.all()
    if not shows:
        return start_id

    show_ids = [row[0] for row in shows]
    jobs = {
        j.tv_show_id: j
        for j in session.query(TvSeasonSyncJob)
        .filter(TvSeasonSyncJob.tv_show_id.in_(show_ids))
        .all()
    }

    new_start = start_id if start_id > 0 else show_ids[0]
    for i, sid in enumerate(show_ids):
        job = jobs.get(sid)
        if job is None or job.status != "completed":
            new_start = sid
            break
        # Contiguous complete → move start cursor to next show (or past end)
        new_start = show_ids[i + 1] if i + 1 < len(show_ids) else sid + 1

    if new_start != start_id:
        save_checkpoint(
            session,
            TV_SEASONS_ENTITY,
            -1,
            last_id=new_start,
            last_season=None,
            status="running",
        )
        session.commit()
        logger.info(
            "TV seasons start_id advanced %s -> %s (ids < %s treated completed)",
            start_id,
            new_start,
            new_start,
        )
    return new_start


def _split_season_key_pools(keys: list[int], workers: int) -> list[list[int]]:
    """Contiguous split: [0,1,2,3] + 2 workers → [[0,1], [2,3]]."""
    if not keys:
        return [[0]]
    workers = max(1, min(workers, len(keys)))
    base, rem = divmod(len(keys), workers)
    pools: list[list[int]] = []
    idx = 0
    for w in range(workers):
        size = base + (1 if w < rem else 0)
        chunk = keys[idx : idx + size]
        idx += size
        if chunk:
            pools.append(chunk)
    return pools or [keys]


def sync_tv_seasons(session: Session, client: TMDBClient) -> dict:
    """
    Phase 5: seasons + episodes — continuous pending queue.

    Default: 2 workers with key pools from SYNC_SEASON_KEY_INDEXES
    (e.g. worker0 → 0,1 ; worker1 → 2,3). Each pool round-robins + rotates on 429.
    Set SYNC_SEASON_WORKERS=1 to go back to single writer.
    """
    import threading
    from concurrent.futures import ThreadPoolExecutor, as_completed

    from db import get_session

    start = time.time()
    stats = {"processed": 0, "failed": 0}
    stats_lock = threading.Lock()
    queue_lock = threading.Lock()

    season_keys = list(config.SYNC_SEASON_KEY_INDEXES)
    workers = max(1, min(int(config.SYNC_SEASON_WORKERS), len(season_keys)))
    key_pools = _split_season_key_pools(season_keys, workers)

    _migrate_legacy_tv_seasons_checkpoint(session)
    _reset_retryable_season_jobs(session)
    start_id = _advance_tv_seasons_start_id(session)

    incomplete_q = (
        session.query(TvShow.id)
        .outerjoin(TvSeasonSyncJob, TvSeasonSyncJob.tv_show_id == TvShow.id)
        .filter(
            (TvSeasonSyncJob.id.is_(None))
            | (TvSeasonSyncJob.status != "completed")
        )
    )
    if start_id > 0:
        incomplete_q = incomplete_q.filter(TvShow.id >= start_id)
    incomplete = incomplete_q.count()
    logger.info(
        "=== TV seasons — %s workers | start_id=%s | incomplete=%s | key pools=%s ===",
        len(key_pools),
        start_id,
        incomplete,
        key_pools,
    )

    if incomplete == 0:
        elapsed = time.time() - start
        save_checkpoint(
            session,
            TV_SEASONS_ENTITY,
            -1,
            last_id=start_id,
            last_season=None,
            status="completed",
        )
        record_metrics(session, TV_SEASONS_ENTITY, 0, 0, 0, 0, 0, elapsed)
        session.commit()
        return stats

    with queue_lock:
        _refill_season_queue_if_needed(session, len(key_pools))
    session.commit()
    session.expire_all()

    def _worker_loop(wid: int, worker_client: TMDBClient) -> dict:
        worker_session = get_session()
        local = {"processed": 0, "failed": 0}
        n_workers = len(key_pools)
        try:
            while True:
                job = None
                with queue_lock:
                    _refill_season_queue_if_needed(worker_session, n_workers)
                    job = _claim_next_pending_season_job(worker_session)
                    if job is None:
                        pending = _count_season_jobs(worker_session, "pending")
                        running = _count_season_jobs(worker_session, "running")
                        if pending == 0 and running == 0:
                            batch = max(n_workers, n_workers * _SEASON_QUEUE_MULTIPLIER)
                            if _enqueue_next_season_jobs(worker_session, batch) == 0:
                                break
                            job = _claim_next_pending_season_job(worker_session)

                if job is None:
                    time.sleep(_SEASON_QUEUE_IDLE_SLEEP)
                    continue

                show = (
                    worker_session.query(TvShow)
                    .filter(TvShow.id == job.tv_show_id)
                    .first()
                )
                if not show:
                    job.status = "failed"
                    job.error_message = "tv_show row missing"
                    job.updated_at = datetime.now(timezone.utc)
                    worker_session.commit()
                    local["failed"] += 1
                    continue

                resume_after = job.last_processed_season
                job_id = job.id
                show_id = show.id
                tmdb_id = show.tmdb_id

                try:

                    def _on_season_done(sn: int, _job_id=job_id) -> None:
                        j = (
                            worker_session.query(TvSeasonSyncJob)
                            .filter(TvSeasonSyncJob.id == _job_id)
                            .first()
                        )
                        if j:
                            j.last_processed_season = sn
                            j.status = "running"
                            j.updated_at = datetime.now(timezone.utc)
                            worker_session.commit()

                    _sync_tv_seasons_for_show(
                        worker_session,
                        worker_client,
                        show,
                        tmdb_id,
                        resume_after_season=resume_after,
                        on_season_done=_on_season_done,
                    )
                    j = (
                        worker_session.query(TvSeasonSyncJob)
                        .filter(TvSeasonSyncJob.id == job_id)
                        .first()
                    )
                    if j:
                        j.status = "completed"
                        j.error_message = None
                        j.updated_at = datetime.now(timezone.utc)
                    worker_session.commit()
                    local["processed"] += 1
                    logger.info(
                        "TV seasons worker %s — completed show id=%s tmdb=%s",
                        wid,
                        show_id,
                        tmdb_id,
                    )
                except Exception as exc:
                    worker_session.rollback()
                    j = (
                        worker_session.query(TvSeasonSyncJob)
                        .filter(TvSeasonSyncJob.id == job_id)
                        .first()
                    )
                    if j:
                        j.status = "failed"
                        j.error_message = str(exc)[:2000]
                        j.updated_at = datetime.now(timezone.utc)
                        worker_session.commit()
                    local["failed"] += 1
                    logger.error(
                        "TV seasons failed for show id=%s tmdb=%s: %s",
                        show_id,
                        tmdb_id,
                        exc,
                    )

                with queue_lock:
                    _advance_tv_seasons_start_id(worker_session)
                    _refill_season_queue_if_needed(worker_session, n_workers)
        finally:
            worker_session.close()
        return local

    clients = [
        TMDBClient(
            credential_indexes=pool,
            rotate_on_limit=True,
            round_robin=True,
        )
        for pool in key_pools
    ]
    try:
        logger.info(
            "TV seasons — starting %s workers with pools %s",
            len(clients),
            key_pools,
        )
        with ThreadPoolExecutor(max_workers=len(clients)) as pool:
            futures = {
                pool.submit(_worker_loop, i, clients[i]): i
                for i in range(len(clients))
            }
            for fut in as_completed(futures):
                wid = futures[fut]
                try:
                    local = fut.result()
                    with stats_lock:
                        stats["processed"] += local.get("processed", 0)
                        stats["failed"] += local.get("failed", 0)
                except Exception as exc:
                    logger.error("TV seasons worker %s crashed: %s", wid, exc)
                    with stats_lock:
                        stats["failed"] += 1
    finally:
        for c in clients:
            c.close()

    start_id = _advance_tv_seasons_start_id(session)
    still_q = (
        session.query(TvShow.id)
        .outerjoin(TvSeasonSyncJob, TvSeasonSyncJob.tv_show_id == TvShow.id)
        .filter(
            (TvSeasonSyncJob.id.is_(None))
            | (TvSeasonSyncJob.status != "completed")
        )
    )
    if start_id > 0:
        still_q = still_q.filter(TvShow.id >= start_id)
    still_incomplete = still_q.count()
    final_status = "completed" if still_incomplete == 0 else "running"
    save_checkpoint(
        session,
        TV_SEASONS_ENTITY,
        -1,
        last_id=start_id,
        last_season=None,
        status=final_status,
    )

    elapsed = time.time() - start
    record_metrics(
        session,
        TV_SEASONS_ENTITY,
        stats["processed"],
        stats["processed"],
        0,
        0,
        stats["failed"],
        elapsed,
    )
    session.commit()
    logger.info(
        "Phase DONE — TV seasons: processed %s, failed %s, start_id=%s, status=%s",
        stats["processed"],
        stats["failed"],
        start_id,
        final_status,
    )
    return stats


def sync_tv_videos(session: Session, client: TMDBClient) -> dict:
    """Phase 6: GET /tv/{id}/videos for every TV show in DB."""
    return _sync_tv_phase(
        session, client,
        entity_type="tv_videos",
        phase_name="TV videos",
        sync_fn=lambda s, c, show: _sync_videos(s, c, "tv", show.id, show.tmdb_id),
    )


def sync_tv_images(session: Session, client: TMDBClient) -> dict:
    """Phase 7: GET /tv/{id}/images for every TV show in DB."""
    return _sync_tv_phase(
        session, client,
        entity_type="tv_images",
        phase_name="TV images",
        sync_fn=lambda s, c, show: _sync_images(s, c, "tv", show.id, show.tmdb_id),
    )


def sync_tv_keywords(session: Session, client: TMDBClient) -> dict:
    """Phase 8: GET /tv/{id}/keywords for every TV show in DB."""
    return _sync_tv_phase(
        session, client,
        entity_type="tv_keywords",
        phase_name="TV keywords",
        sync_fn=lambda s, c, show: _sync_keywords(s, c, "tv", show.id, show.tmdb_id),
    )


def sync_tv_recommendations(session: Session, client: TMDBClient) -> dict:
    """Phase 9: GET /tv/{id}/recommendations for every TV show in DB."""
    return _sync_tv_phase(
        session, client,
        entity_type="tv_recommendations",
        phase_name="TV recommendations",
        sync_fn=lambda s, c, show: _sync_recommendations(
            s, c, "tv", show.id, show.tmdb_id
        ),
    )


def _sync_tv_phase(
    session: Session,
    client: TMDBClient,
    entity_type: str,
    phase_name: str,
    sync_fn,
) -> dict:
    start = time.time()
    stats = {"processed": 0, "failed": 0}
    checkpoint = get_checkpoint(session, entity_type)
    last_id = (checkpoint.last_processed_id or 0) if checkpoint else 0

    shows = (
        session.query(TvShow)
        .filter(TvShow.id > last_id)
        .order_by(TvShow.id)
        .all()
    )
    total = len(shows)
    logger.info("=== %s — %s shows (resume after id %s) ===", phase_name, total, last_id)

    for idx, show in enumerate(shows, 1):
        try:
            sync_fn(session, client, show)
            session.commit()
            stats["processed"] += 1
            save_checkpoint(session, entity_type, 0, last_id=show.id, status="running")
            session.commit()
            if idx % 10 == 0 or idx == total:
                logger.info("%s — %s/%s done (show id %s)", phase_name, idx, total, show.tmdb_id)
        except Exception as exc:
            stats["failed"] += 1
            session.rollback()
            logger.error("%s failed for TV %s: %s", phase_name, show.tmdb_id, exc)

    save_checkpoint(session, entity_type, 0, last_id=None, status="completed")
    elapsed = time.time() - start
    record_metrics(
        session, entity_type, stats["processed"],
        stats["processed"], 0, 0, stats["failed"], elapsed,
    )
    session.commit()
    logger.info("Phase DONE — %s: processed %s, failed %s", phase_name, stats["processed"], stats["failed"])
    return stats


def _process_tv_chunk(
    session: Session,
    client: TMDBClient,
    items: list,
    seen_ids: set,
) -> dict:
    stats = {"inserted": 0, "updated": 0, "skipped": 0, "failed": 0, "total": 0}

    work_ids: list[int] = []
    for item in items:
        tmdb_id = item["id"]
        if tmdb_id in seen_ids:
            continue
        seen_ids.add(tmdb_id)
        work_ids.append(tmdb_id)

    if not work_ids:
        return stats

    if config.effective_workers(len(work_ids)) <= 1:
        return _process_tv_ids(session, client, work_ids)

    def _worker(subset: list, worker_client: TMDBClient, _wid: int) -> dict:
        from db import get_session

        worker_session = get_session()
        try:
            return _process_tv_ids(worker_session, worker_client, subset)
        finally:
            worker_session.close()

    results = run_partitioned(work_ids, _worker, label="TV detail")
    return merge_count_stats(
        results, ("inserted", "updated", "skipped", "failed", "total")
    )


def _process_tv_ids(
    session: Session,
    client: TMDBClient,
    tmdb_ids: list[int],
) -> dict:
    stats = {"inserted": 0, "updated": 0, "skipped": 0, "failed": 0, "total": 0}
    for tmdb_id in tmdb_ids:
        stats["total"] += 1
        try:
            detail = client.get(f"tv/{tmdb_id}")
            if not detail:
                stats["failed"] += 1
                continue

            def _write() -> str:
                action = upsert_tv_show(session, detail)
                session.commit()
                return action

            action = run_with_deadlock_retry(
                _write,
                rollback=session.rollback,
                label=f"TV {tmdb_id}",
            )
            stats[action] += 1
        except Exception as exc:
            stats["failed"] += 1
            session.rollback()
            logger.error("TV %s failed: %s", tmdb_id, exc)
    return stats


def _sync_tv_seasons_for_show(
    session,
    client,
    show: TvShow,
    tmdb_id: int,
    resume_after_season: int | None = None,
    on_season_done=None,
) -> None:
    detail = client.get(f"tv/{tmdb_id}")
    if not detail:
        return

    show_id = show.id

    for season_ref in detail.get("seasons", []):
        sn = season_ref.get("season_number", 0)
        if sn < 0:
            continue
        if resume_after_season is not None and sn <= resume_after_season:
            continue

        try:
            season_data = client.get(f"tv/{tmdb_id}/season/{sn}")
        except Exception as exc:
            logger.warning("TV %s season %s fetch failed: %s", tmdb_id, sn, exc)
            raise

        if not season_data:
            continue

        season_tmdb_id = season_data["id"]

        def _load_show() -> TvShow:
            row = session.query(TvShow).filter(TvShow.id == show_id).first()
            if not row:
                raise RuntimeError(f"TV show id={show_id} missing")
            return row

        def _ensure_season() -> TvSeason:
            # Re-query after any rollback — stale TvSeason instances raise
            # ObjectDeletedError if reused across the episode loop.
            upsert_tv_season(session, _load_show(), season_data)
            session.flush()
            season = (
                session.query(TvSeason)
                .filter(TvSeason.tmdb_id == season_tmdb_id)
                .first()
            )
            if not season:
                raise RuntimeError(
                    f"TV season tmdb_id={season_tmdb_id} missing after upsert"
                )
            return season

        try:
            run_with_deadlock_retry(
                _ensure_season,
                rollback=session.rollback,
                label=f"TV {tmdb_id} season {sn}",
            )
            session.commit()
        except Exception as exc:
            logger.warning("TV %s season %s upsert failed: %s", tmdb_id, sn, exc)
            session.rollback()
            raise

        # Season payload already includes episode list + guest_stars/crew —
        # skip per-episode API calls (major speedup).
        for ep in season_data.get("episodes", []):
            ep_num = ep.get("episode_number", 0)
            if not ep.get("id"):
                continue

            def _write_episode(ep_payload=ep) -> None:
                tv_show = _load_show()
                season = (
                    session.query(TvSeason)
                    .filter(TvSeason.tmdb_id == season_tmdb_id)
                    .first()
                )
                if not season:
                    season = _ensure_season()
                upsert_tv_episode(session, tv_show, season, ep_payload)
                session.flush()
                episode = (
                    session.query(TvEpisode)
                    .filter(TvEpisode.tmdb_id == ep_payload["id"])
                    .first()
                )
                if episode:
                    _sync_episode_credits(session, client, episode.id, ep_payload)
                    session.flush()

            try:
                run_with_deadlock_retry(
                    _write_episode,
                    rollback=session.rollback,
                    label=f"TV {tmdb_id} S{sn}E{ep_num}",
                )
                # Commit per episode so parallel workers don't hold credits/people
                # locks across an entire season (main deadlock source).
                session.commit()
            except Exception as exc:
                logger.warning(
                    "TV %s season %s episode %s upsert failed: %s",
                    tmdb_id, sn, ep_num, exc,
                )
                session.rollback()
                # Fail the job (not completed) so the next run resumes this
                # season via last_processed_season — avoids ObjectDeletedError
                # cascade from reusing a rolled-back TvSeason instance.
                raise RuntimeError(
                    f"TV {tmdb_id} season {sn} episode {ep_num} upsert failed: {exc}"
                ) from exc

        try:
            if on_season_done:
                on_season_done(sn)
        except Exception as exc:
            logger.warning("TV %s season %s progress update failed: %s", tmdb_id, sn, exc)
            session.rollback()
            raise
