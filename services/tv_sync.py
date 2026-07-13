import time

from sqlalchemy.orm import Session

from models.tv import TvSeason, TvShow
from repositories.tv_repo import (
    upsert_tv_episode,
    upsert_tv_season,
    upsert_tv_show,
)
from services.movie_sync import (
    _sync_credits,
    _sync_images,
    _sync_keywords,
    _sync_recommendations,
    _sync_similar,
    _sync_videos,
    _sync_watch_providers,
)
from services.tmdb_client import TMDBClient
from utils.checkpoint import get_checkpoint, record_metrics, save_checkpoint
from utils.logger import get_logger
from utils.provider_config import load_provider_jobs

logger = get_logger(__name__)

TV_LIST_ENDPOINTS = ["tv/popular", "tv/top_rated"]


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
    """Phase 1 via discover API — TV on selected provider(s) per region."""
    start = time.time()
    stats = {"inserted": 0, "updated": 0, "skipped": 0, "failed": 0, "total": 0}
    seen_ids: set[int] = set()

    logger.info("=== Phase 1: TV DETAIL by PROVIDER (%s regions) ===", len(jobs))

    for job in jobs:
        region = job["region"]
        provider_ids = job["ids"]
        names = ", ".join(job.get("names", [])) or provider_ids
        entity = f"tv_detail_provider_{region}"
        checkpoint = get_checkpoint(session, entity)
        start_page = (checkpoint.last_processed_page + 1) if checkpoint else 1

        discover_params = {
            "with_watch_providers": provider_ids,
            "watch_region": region,
            "with_watch_monetization_types": monetization,
            "sort_by": "popularity.desc",
        }
        logger.info(
            "Region %s | providers: %s | IDs: %s | from page %s",
            region, names, provider_ids, start_page,
        )

        for page, data in client.paginate("discover/tv", discover_params, start_page=start_page):
            total_pages = data.get("total_pages", "?")
            total_results = data.get("total_results", "?")
            logger.info(
                "Discover TV [%s] page %s/%s — %s results (region total: %s)",
                region, page, total_pages, len(data.get("results", [])), total_results,
            )
            chunk_stats = _process_tv_chunk(session, client, data["results"], seen_ids)
            for k in ("inserted", "updated", "skipped", "failed", "total"):
                stats[k] += chunk_stats[k]
            save_checkpoint(session, entity, page, status="running")
            session.commit()
            logger.info(
                "[%s] page %s — inserted %s, updated %s, skipped %s",
                region, page, chunk_stats["inserted"], chunk_stats["updated"], chunk_stats["skipped"],
            )

        save_checkpoint(session, entity, 0, status="completed")
        session.commit()
        logger.info("Region %s complete", region)

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
    """Phase 2: GET /tv/{id}/credits for every TV show in DB."""
    return _sync_tv_phase(
        session, client,
        entity_type="tv_credits",
        phase_name="TV credits",
        sync_fn=lambda s, c, show: _sync_credits(s, c, "tv", show.id, show.tmdb_id),
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


def sync_tv_seasons(session: Session, client: TMDBClient) -> dict:
    """Phase 5: seasons + episodes with per-season resume support."""
    start = time.time()
    stats = {"processed": 0, "failed": 0}
    checkpoint = get_checkpoint(session, "tv_seasons")
    resume_show_id = (checkpoint.last_processed_id or 0) if checkpoint else 0
    resume_after_season = (
        checkpoint.last_processed_season
        if checkpoint and checkpoint.last_processed_season is not None and checkpoint.last_processed_season >= 0
        else None
    )
    show_completed = bool(checkpoint and checkpoint.last_processed_page == -1)

    shows = (
        session.query(TvShow)
        .filter(TvShow.id >= resume_show_id)
        .order_by(TvShow.id)
        .all()
    )
    if show_completed:
        shows = [show for show in shows if show.id > resume_show_id]

    total = len(shows)
    logger.info(
        "=== TV seasons — %s shows (resume after show id %s, season %s) ===",
        total,
        resume_show_id,
        resume_after_season if resume_after_season is not None else "start",
    )

    for idx, show in enumerate(shows, 1):
        try:
            _sync_tv_seasons_for_show(
                session,
                client,
                show,
                show.tmdb_id,
                resume_after_season=(
                    resume_after_season
                    if show.id == resume_show_id and checkpoint and checkpoint.last_processed_page != -1
                    else None
                ),
            )
            session.commit()
            stats["processed"] += 1
            save_checkpoint(
                session,
                "tv_seasons",
                -1,
                last_id=show.id,
                last_season=resume_after_season,
                status="completed",
            )
            session.commit()
            if idx % 10 == 0 or idx == total:
                logger.info("TV seasons — %s/%s done (show id %s)", idx, total, show.tmdb_id)
        except Exception as exc:
            stats["failed"] += 1
            session.rollback()
            logger.error("TV seasons failed for TV %s: %s", show.tmdb_id, exc)

    elapsed = time.time() - start
    record_metrics(
        session,
        "tv_seasons",
        stats["processed"],
        stats["processed"],
        0,
        0,
        stats["failed"],
        elapsed,
    )
    session.commit()
    logger.info(
        "Phase DONE — TV seasons: processed %s, failed %s",
        stats["processed"],
        stats["failed"],
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

    for item in items:
        tmdb_id = item["id"]
        if tmdb_id in seen_ids:
            continue
        seen_ids.add(tmdb_id)
        stats["total"] += 1
        try:
            detail = client.get(f"tv/{tmdb_id}")
            if not detail:
                stats["failed"] += 1
                continue
            action = upsert_tv_show(session, detail)
            stats[action] += 1
            session.commit()
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
) -> None:
    detail = client.get(f"tv/{tmdb_id}")
    if not detail:
        return

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
            continue

        if not season_data:
            continue

        try:
            upsert_tv_season(session, show, season_data)
            session.flush()
        except Exception as exc:
            logger.warning("TV %s season %s upsert failed: %s", tmdb_id, sn, exc)
            session.rollback()
            continue

        season = (
            session.query(TvSeason)
            .filter(TvSeason.tmdb_id == season_data["id"])
            .first()
        )
        if not season:
            continue

        for ep in season_data.get("episodes", []):
            ep_num = ep.get("episode_number", 0)
            try:
                ep_detail = client.get(f"tv/{tmdb_id}/season/{sn}/episode/{ep_num}")
            except Exception as exc:
                logger.warning("TV %s season %s episode %s fetch failed: %s", tmdb_id, sn, ep_num, exc)
                continue

            if ep_detail:
                try:
                    upsert_tv_episode(session, show, season, ep_detail)
                    session.flush()
                except Exception as exc:
                    logger.warning("TV %s season %s episode %s upsert failed: %s", tmdb_id, sn, ep_num, exc)
                    session.rollback()
                    continue
            else:
                try:
                    upsert_tv_episode(session, show, season, ep)
                    session.flush()
                except Exception as exc:
                    logger.warning("TV %s season %s episode %s fallback upsert failed: %s", tmdb_id, sn, ep_num, exc)
                    session.rollback()
                    continue

        try:
            save_checkpoint(
                session,
                "tv_seasons",
                sn,
                last_id=show.id,
                last_season=sn,
                status="running",
            )
            session.commit()
        except Exception as exc:
            logger.warning("TV %s season commit failed: %s", tmdb_id, exc)
            session.rollback()
