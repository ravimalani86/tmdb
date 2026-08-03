import time
from datetime import datetime, timezone

from sqlalchemy.orm import Session

from models.movie import (
    Certification,
    Collection,
    Credit,
    ExternalId,
    Image,
    Keyword,
    MediaKeyword,
    Movie,
    Recommendation,
    SimilarMedia,
    Translation,
    Video,
    WatchProvider,
    MediaWatchProvider,
)
from repositories.movie_repo import find_movie_by_tmdb_id, upsert_movie
from services.tmdb_client import TMDBClient
from utils.checkpoint import get_checkpoint, record_metrics, save_checkpoint
from utils.hash_utils import compute_hash
from utils.logger import get_logger
from utils.provider_config import load_provider_jobs
from utils.db_retry import run_with_deadlock_retry
from utils.sync_workers import merge_count_stats, run_partitioned

import config

logger = get_logger(__name__)

MOVIE_LIST_ENDPOINTS = [
    "movie/popular",
    "movie/top_rated",
    "movie/upcoming",
    "movie/now_playing",
]


def sync_movies(session: Session, client: TMDBClient) -> dict:
    """All-in-one sync (lite or full per config). For step-by-step use sync_movies_detail/credits/etc."""
    start = time.time()
    stats = {"inserted": 0, "updated": 0, "skipped": 0, "failed": 0, "total": 0}
    checkpoint = get_checkpoint(session, "movies")
    start_page = (checkpoint.last_processed_page + 1) if checkpoint else 1

    logger.info("Syncing movies from page %s...", start_page)
    if config.SYNC_MOVIE_LITE:
        logger.info(
            "LITE mode ON — syncing: detail, cast, genres, providers, similar only"
        )
    seen_ids: set[int] = set()

    for endpoint in MOVIE_LIST_ENDPOINTS:
        logger.info("Fetching %s...", endpoint)
        for page, data in client.paginate(endpoint, start_page=start_page):
            logger.info("Page %s fetched — %s results", page, len(data.get("results", [])))
            chunk_stats = _process_movie_chunk(
                session, client, data["results"], seen_ids, detail_only=False
            )
            for k in ("inserted", "updated", "skipped", "failed", "total"):
                stats[k] += chunk_stats[k]
            save_checkpoint(session, "movies", page, status="running")
            session.commit()
            logger.info(
                "Chunk page %s completed — %s inserted, %s updated, %s skipped, time %.1fs",
                page,
                chunk_stats["inserted"],
                chunk_stats["updated"],
                chunk_stats["skipped"],
                chunk_stats["elapsed"],
            )

    save_checkpoint(session, "movies", 0, status="completed")
    elapsed = time.time() - start
    record_metrics(
        session, "movies", stats["total"],
        stats["inserted"], stats["updated"], stats["skipped"],
        stats["failed"], elapsed,
    )
    session.commit()
    logger.info(
        "Movies sync done — total %s, inserted %s, updated %s, skipped %s, failed %s",
        stats["total"], stats["inserted"], stats["updated"],
        stats["skipped"], stats["failed"],
    )
    return stats


def sync_movies_detail(session: Session, client: TMDBClient) -> dict:
    """Phase 1: movie list + GET /movie/{id} only (poster, banner, rating, genre, language)."""
    jobs, monetization = load_provider_jobs()
    if jobs:
        return _sync_movies_detail_by_provider(session, client, jobs, monetization)
    return _sync_movies_detail_from_lists(session, client)


def _sync_movies_detail_from_lists(session: Session, client: TMDBClient) -> dict:
    start = time.time()
    stats = {"inserted": 0, "updated": 0, "skipped": 0, "failed": 0, "total": 0}
    checkpoint = get_checkpoint(session, "movies_detail")
    start_page = (checkpoint.last_processed_page + 1) if checkpoint else 1

    logger.info("=== Phase 1: Movie DETAIL from lists (page %s) ===", start_page)
    seen_ids: set[int] = set()

    for endpoint in MOVIE_LIST_ENDPOINTS:
        logger.info("Fetching %s...", endpoint)
        for page, data in client.paginate(endpoint, start_page=start_page):
            logger.info("Page %s fetched — %s results", page, len(data.get("results", [])))
            chunk_stats = _process_movie_chunk(
                session, client, data["results"], seen_ids, detail_only=True
            )
            for k in ("inserted", "updated", "skipped", "failed", "total"):
                stats[k] += chunk_stats[k]
            save_checkpoint(session, "movies_detail", page, status="running")
            session.commit()
            logger.info(
                "Detail chunk page %s — inserted %s, updated %s, skipped %s",
                page, chunk_stats["inserted"], chunk_stats["updated"], chunk_stats["skipped"],
            )

    save_checkpoint(session, "movies_detail", 0, status="completed")
    elapsed = time.time() - start
    record_metrics(
        session, "movies_detail", stats["total"],
        stats["inserted"], stats["updated"], stats["skipped"],
        stats["failed"], elapsed,
    )
    session.commit()
    logger.info("Phase 1 DONE — %s movies processed", stats["total"])
    return stats


def _sync_movies_detail_by_provider(
    session: Session,
    client: TMDBClient,
    jobs: list[dict],
    monetization: str,
) -> dict:
    """Phase 1 via discover API — movies one provider at a time."""
    start = time.time()
    stats = {"inserted": 0, "updated": 0, "skipped": 0, "failed": 0, "total": 0}
    seen_ids: set[int] = set()

    logger.info("=== Phase 1: Movie DETAIL by PROVIDER (%s providers) ===", len(jobs))

    for job in jobs:
        region = job["region"]
        provider_ids = job["ids"]
        names = ", ".join(job.get("names", [])) or provider_ids
        entity = f"movies_detail_provider_{region}_{provider_ids}"
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

        for page, data in client.paginate("discover/movie", discover_params, start_page=start_page):
            total_pages = data.get("total_pages", "?")
            total_results = data.get("total_results", "?")
            logger.info(
                "Discover [%s/%s] page %s/%s — %s results (provider total: %s)",
                names, region, page, total_pages, len(data.get("results", [])), total_results,
            )
            chunk_stats = _process_movie_chunk(
                session, client, data["results"], seen_ids, detail_only=True
            )
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
        session, "movies_detail_provider", stats["total"],
        stats["inserted"], stats["updated"], stats["skipped"],
        stats["failed"], elapsed,
    )
    session.commit()
    logger.info("Phase 1 (provider filter) DONE — %s movies processed", stats["total"])
    return stats


def sync_movies_credits(session: Session, client: TMDBClient) -> dict:
    """Phase 2: GET /movie/{id}/credits for every movie in DB."""
    return _sync_movie_phase(
        session, client,
        entity_type="movies_credits",
        phase_name="Credits",
        sync_fn=lambda s, c, movie: _sync_credits(s, c, "movie", movie.id, movie.tmdb_id),
    )


def sync_movies_providers(session: Session, client: TMDBClient) -> dict:
    """Phase 3: GET /movie/{id}/watch/providers for every movie in DB."""
    return _sync_movie_phase(
        session, client,
        entity_type="movies_providers",
        phase_name="Watch providers",
        sync_fn=lambda s, c, movie: _sync_watch_providers(
            s, c, "movie", movie.id, movie.tmdb_id
        ),
    )


def sync_movies_similar(session: Session, client: TMDBClient) -> dict:
    """Phase 4: GET /movie/{id}/similar for every movie in DB."""
    return _sync_movie_phase(
        session, client,
        entity_type="movies_similar",
        phase_name="Similar movies",
        sync_fn=lambda s, c, movie: _sync_similar(s, c, "movie", movie.id, movie.tmdb_id),
    )


def sync_movies_videos(session: Session, client: TMDBClient) -> dict:
    """Phase 5: GET /movie/{id}/videos for every movie in DB."""
    return _sync_movie_phase(
        session, client,
        entity_type="movies_videos",
        phase_name="Videos",
        sync_fn=lambda s, c, movie: _sync_videos(s, c, "movie", movie.id, movie.tmdb_id),
    )


def sync_movies_images(session: Session, client: TMDBClient) -> dict:
    """Phase 6: GET /movie/{id}/images for every movie in DB."""
    return _sync_movie_phase(
        session, client,
        entity_type="movies_images",
        phase_name="Images",
        sync_fn=lambda s, c, movie: _sync_images(s, c, "movie", movie.id, movie.tmdb_id),
    )


def sync_movies_keywords(session: Session, client: TMDBClient) -> dict:
    """Phase 7: GET /movie/{id}/keywords for every movie in DB."""
    return _sync_movie_phase(
        session, client,
        entity_type="movies_keywords",
        phase_name="Keywords",
        sync_fn=lambda s, c, movie: _sync_keywords(s, c, "movie", movie.id, movie.tmdb_id),
    )


def sync_movies_recommendations(session: Session, client: TMDBClient) -> dict:
    """Phase 8: GET /movie/{id}/recommendations for every movie in DB."""
    return _sync_movie_phase(
        session, client,
        entity_type="movies_recommendations",
        phase_name="Recommendations",
        sync_fn=lambda s, c, movie: _sync_recommendations(
            s, c, "movie", movie.id, movie.tmdb_id
        ),
    )


def _sync_movie_phase(
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

    movies = (
        session.query(Movie)
        .filter(Movie.id > last_id)
        .order_by(Movie.id)
        .all()
    )
    total = len(movies)
    logger.info("=== %s — %s movies (resume after id %s) ===", phase_name, total, last_id)

    for idx, movie in enumerate(movies, 1):
        try:
            sync_fn(session, client, movie)
            session.commit()
            stats["processed"] += 1
            save_checkpoint(session, entity_type, 0, last_id=movie.id, status="running")
            session.commit()
            if idx % 10 == 0 or idx == total:
                logger.info("%s — %s/%s done (movie id %s)", phase_name, idx, total, movie.tmdb_id)
        except Exception as exc:
            stats["failed"] += 1
            session.rollback()
            logger.error("%s failed for movie %s: %s", phase_name, movie.tmdb_id, exc)

    save_checkpoint(session, entity_type, 0, last_id=None, status="completed")
    elapsed = time.time() - start
    record_metrics(
        session, entity_type, stats["processed"],
        stats["processed"], 0, 0, stats["failed"], elapsed,
    )
    session.commit()
    logger.info("Phase DONE — %s: processed %s, failed %s", phase_name, stats["processed"], stats["failed"])
    return stats


def _process_movie_chunk(
    session: Session,
    client: TMDBClient,
    items: list,
    seen_ids: set,
    detail_only: bool = False,
) -> dict:
    start = time.time()
    stats = {"inserted": 0, "updated": 0, "skipped": 0, "failed": 0, "total": 0, "elapsed": 0}

    work_ids: list[int] = []
    for item in items:
        tmdb_id = item["id"]
        if tmdb_id in seen_ids:
            continue
        seen_ids.add(tmdb_id)
        work_ids.append(tmdb_id)

    if not work_ids:
        stats["elapsed"] = time.time() - start
        return stats

    if config.effective_workers(len(work_ids)) <= 1:
        chunk = _process_movie_ids(session, client, work_ids, detail_only)
        for k in ("inserted", "updated", "skipped", "failed", "total"):
            stats[k] += chunk[k]
        stats["elapsed"] = time.time() - start
        return stats

    # Parallel page items — each worker uses its own DB session + API key
    def _worker(subset: list, worker_client: TMDBClient, _wid: int) -> dict:
        from db import get_session

        worker_session = get_session()
        try:
            return _process_movie_ids(
                worker_session, worker_client, subset, detail_only
            )
        finally:
            worker_session.close()

    results = run_partitioned(work_ids, _worker, label="Movie detail")
    merged = merge_count_stats(
        results, ("inserted", "updated", "skipped", "failed", "total")
    )
    stats.update(merged)
    stats["elapsed"] = time.time() - start
    return stats


def _process_movie_ids(
    session: Session,
    client: TMDBClient,
    tmdb_ids: list[int],
    detail_only: bool,
) -> dict:
    stats = {"inserted": 0, "updated": 0, "skipped": 0, "failed": 0, "total": 0}
    for tmdb_id in tmdb_ids:
        stats["total"] += 1
        try:
            detail = client.get(f"movie/{tmdb_id}")
            if not detail:
                stats["failed"] += 1
                continue

            def _write() -> str:
                action = upsert_movie(session, detail)
                session.commit()
                if not detail_only:
                    movie = find_movie_by_tmdb_id(session, tmdb_id)
                    if movie and action != "skipped":
                        _sync_movie_related(session, client, movie, tmdb_id)
                        session.commit()
                return action

            action = run_with_deadlock_retry(
                _write,
                rollback=session.rollback,
                label=f"Movie {tmdb_id}",
            )
            stats[action] += 1
        except Exception as exc:
            stats["failed"] += 1
            session.rollback()
            logger.error("Movie %s failed: %s", tmdb_id, exc)
    return stats


def _sync_movie_related(session: Session, client: TMDBClient, movie: Movie, tmdb_id: int):
    if config.SYNC_MOVIE_LITE:
        sync_steps = [
            ("credits", lambda: _sync_credits(session, client, "movie", movie.id, tmdb_id)),
            ("watch_providers", lambda: _sync_watch_providers(session, client, "movie", movie.id, tmdb_id)),
            ("similar", lambda: _sync_similar(session, client, "movie", movie.id, tmdb_id)),
        ]
    else:
        sync_steps = [
            ("credits", lambda: _sync_credits(session, client, "movie", movie.id, tmdb_id)),
            ("videos", lambda: _sync_videos(session, client, "movie", movie.id, tmdb_id)),
            ("images", lambda: _sync_images(session, client, "movie", movie.id, tmdb_id)),
            ("keywords", lambda: _sync_keywords(session, client, "movie", movie.id, tmdb_id)),
            ("recommendations", lambda: _sync_recommendations(session, client, "movie", movie.id, tmdb_id)),
            ("similar", lambda: _sync_similar(session, client, "movie", movie.id, tmdb_id)),
            ("external_ids", lambda: _sync_external_ids(session, client, "movie", movie.id, tmdb_id)),
            ("translations", lambda: _sync_translations(session, client, "movie", movie.id, tmdb_id)),
            ("watch_providers", lambda: _sync_watch_providers(session, client, "movie", movie.id, tmdb_id)),
            ("release_dates", lambda: _sync_release_dates(session, client, movie.id, tmdb_id)),
        ]
        if movie.collection_id:
            sync_steps.append(
                ("collection", lambda: _sync_collection(session, client, movie.collection_id))
            )
    for name, step in sync_steps:
        try:
            step()
            session.flush()
        except Exception as exc:
            logger.warning("Movie %s %s sync failed: %s", tmdb_id, name, exc)
            session.rollback()
            movie = find_movie_by_tmdb_id(session, tmdb_id)
            if not movie:
                raise


def _ensure_person(session, client, data, _cache: dict | None = None):
    from sqlalchemy.exc import IntegrityError

    from repositories.people_repo import find_person_by_tmdb_id, upsert_person

    tmdb_id = data.get("id")
    if not tmdb_id:
        return None
    if _cache is not None and tmdb_id in _cache:
        return _cache[tmdb_id]
    existing = find_person_by_tmdb_id(session, tmdb_id)
    if existing:
        if _cache is not None:
            _cache[tmdb_id] = existing
        return existing
    try:
        # Nested txn: concurrent workers inserting the same person must not
        # poison the outer episode/season transaction on duplicate key.
        with session.begin_nested():
            upsert_person(session, data)
            session.flush()
    except IntegrityError:
        pass
    person = find_person_by_tmdb_id(session, tmdb_id)
    if _cache is not None and person:
        _cache[tmdb_id] = person
    return person


def _sync_credits(session, client, media_type, media_id, tmdb_id):
    data = client.get(f"{media_type}/{tmdb_id}/credits")
    if not data:
        return
    now = datetime.now(timezone.utc)
    person_cache: dict = {}
    session.query(Credit).filter(
        Credit.media_type == media_type, Credit.media_id == media_id
    ).delete(synchronize_session=False)

    for idx, cast in enumerate(data.get("cast", [])):
        person = _ensure_person(session, client, cast, person_cache)
        if person:
            session.add(Credit(
                media_type=media_type, media_id=media_id, person_id=person.id,
                credit_type="cast", character=cast.get("character"),
                order_index=cast.get("order", idx),
                data_hash=compute_hash(cast), last_synced_at=now,
            ))

    for crew in data.get("crew", []):
        person = _ensure_person(session, client, crew, person_cache)
        if person:
            session.add(Credit(
                media_type=media_type, media_id=media_id, person_id=person.id,
                credit_type="crew", job=crew.get("job"), department=crew.get("department"),
                data_hash=compute_hash(crew), last_synced_at=now,
            ))


def _sync_tv_aggregate_credits(session, client, media_id: int, tmdb_id: int) -> None:
    """Persist TV cast/crew from aggregate_credits (includes per-role episode_count)."""
    data = client.get(f"tv/{tmdb_id}/aggregate_credits")
    if not data:
        return
    now = datetime.now(timezone.utc)
    person_cache: dict = {}
    session.query(Credit).filter(
        Credit.media_type == "tv", Credit.media_id == media_id
    ).delete(synchronize_session=False)

    for idx, cast in enumerate(data.get("cast", [])):
        person = _ensure_person(session, client, cast, person_cache)
        if not person:
            continue
        order = cast.get("order", idx)
        roles = cast.get("roles") or []
        if not roles:
            session.add(Credit(
                media_type="tv", media_id=media_id, person_id=person.id,
                credit_type="cast", character=None,
                episode_count=cast.get("total_episode_count"),
                order_index=order,
                data_hash=compute_hash(cast), last_synced_at=now,
            ))
            continue
        for role in roles:
            session.add(Credit(
                media_type="tv", media_id=media_id, person_id=person.id,
                credit_type="cast", character=role.get("character"),
                episode_count=role.get("episode_count"),
                order_index=order,
                data_hash=compute_hash(role), last_synced_at=now,
            ))

    for crew in data.get("crew", []):
        person = _ensure_person(session, client, crew, person_cache)
        if not person:
            continue
        jobs = crew.get("jobs") or []
        if not jobs:
            session.add(Credit(
                media_type="tv", media_id=media_id, person_id=person.id,
                credit_type="crew", job=None, department=crew.get("department"),
                episode_count=crew.get("total_episode_count"),
                data_hash=compute_hash(crew), last_synced_at=now,
            ))
            continue
        for job in jobs:
            session.add(Credit(
                media_type="tv", media_id=media_id, person_id=person.id,
                credit_type="crew", job=job.get("job"),
                department=crew.get("department"),
                episode_count=job.get("episode_count"),
                data_hash=compute_hash(job), last_synced_at=now,
            ))


def _sync_episode_credits(session, client, episode_id: int, episode_data: dict) -> None:
    """Persist guest_stars + crew from a TMDB episode payload (media_type=episode)."""
    now = datetime.now(timezone.utc)
    person_cache: dict = {}
    session.query(Credit).filter(
        Credit.media_type == "episode", Credit.media_id == episode_id
    ).delete(synchronize_session=False)

    # Stable person_id lock order across workers reduces MySQL deadlocks.
    guest_stars = sorted(
        episode_data.get("guest_stars", []),
        key=lambda c: (c.get("id") is None, c.get("id") or 0),
    )
    crew_list = sorted(
        episode_data.get("crew", []),
        key=lambda c: (c.get("id") is None, c.get("id") or 0),
    )

    for idx, cast in enumerate(guest_stars):
        person = _ensure_person(session, client, cast, person_cache)
        if person:
            session.add(Credit(
                media_type="episode", media_id=episode_id, person_id=person.id,
                credit_type="cast", character=cast.get("character"),
                order_index=cast.get("order", idx),
                data_hash=compute_hash(cast), last_synced_at=now,
            ))

    for crew in crew_list:
        person = _ensure_person(session, client, crew, person_cache)
        if person:
            session.add(Credit(
                media_type="episode", media_id=episode_id, person_id=person.id,
                credit_type="crew", job=crew.get("job"), department=crew.get("department"),
                data_hash=compute_hash(crew), last_synced_at=now,
            ))


def _sync_videos(session, client, media_type, media_id, tmdb_id):
    data = client.get(f"{media_type}/{tmdb_id}/videos")
    if not data:
        return
    now = datetime.now(timezone.utc)
    for v in data.get("results", []):
        vid = v.get("id", "")
        existing = session.query(Video).filter(
            Video.media_type == media_type,
            Video.media_id == media_id,
            Video.tmdb_video_id == str(vid),
        ).first()
        fields = {
            "name": v.get("name"), "key": v.get("key"), "site": v.get("site"),
            "size": v.get("size"), "video_type": v.get("type"),
            "official": bool(v.get("official", False)),
            "data_hash": compute_hash(v), "last_synced_at": now,
        }
        if existing is None:
            session.add(Video(
                media_type=media_type, media_id=media_id,
                tmdb_video_id=str(vid), **fields,
            ))
        elif existing.data_hash != fields["data_hash"]:
            for k, val in fields.items():
                setattr(existing, k, val)


def _sync_images(session, client, media_type, media_id, tmdb_id):
    data = client.get(f"{media_type}/{tmdb_id}/images")
    if not data:
        return
    now = datetime.now(timezone.utc)
    for img_type in ("posters", "backdrops"):
        itype = "poster" if img_type == "posters" else "backdrop"
        for img in data.get(img_type, []):
            fp = img.get("file_path", "")
            existing = session.query(Image).filter(
                Image.media_type == media_type, Image.media_id == media_id,
                Image.file_path == fp, Image.image_type == itype,
            ).first()
            fields = {
                "width": img.get("width"), "height": img.get("height"),
                "aspect_ratio": img.get("aspect_ratio"),
                "vote_average": img.get("vote_average"),
                "vote_count": img.get("vote_count"),
                "iso_639_1": img.get("iso_639_1"),
                "data_hash": compute_hash(img), "last_synced_at": now,
            }
            if existing is None:
                session.add(Image(
                    media_type=media_type, media_id=media_id,
                    file_path=fp, image_type=itype, **fields,
                ))
            elif existing.data_hash != fields["data_hash"]:
                for k, val in fields.items():
                    setattr(existing, k, val)


def _sync_keywords(session, client, media_type, media_id, tmdb_id):
    data = client.get(f"{media_type}/{tmdb_id}/keywords")
    if not data:
        return
    now = datetime.now(timezone.utc)
    keywords = data.get("keywords", data.get("results", []))
    session.query(MediaKeyword).filter(
        MediaKeyword.media_type == media_type, MediaKeyword.media_id == media_id
    ).delete(synchronize_session=False)
    for kw in keywords:
        existing = session.query(Keyword).filter(Keyword.tmdb_id == kw["id"]).first()
        if existing is None:
            existing = Keyword(
                tmdb_id=kw["id"], name=kw["name"],
                data_hash=compute_hash(kw), last_synced_at=now,
            )
            session.add(existing)
            session.flush()
        session.add(MediaKeyword(
            media_type=media_type, media_id=media_id, keyword_id=existing.id
        ))


def _sync_recommendations(session, client, media_type, media_id, tmdb_id):
    data = client.get(f"{media_type}/{tmdb_id}/recommendations")
    if not data:
        return
    now = datetime.now(timezone.utc)
    for rec in data.get("results", []):
        rec_id = rec["id"]
        existing = session.query(Recommendation).filter(
            Recommendation.media_type == media_type,
            Recommendation.media_id == media_id,
            Recommendation.recommended_media_id == rec_id,
        ).first()
        if existing is None:
            session.add(Recommendation(
                media_type=media_type, media_id=media_id,
                recommended_media_id=rec_id,
                recommendation_score=rec.get("vote_average"),
                last_synced_at=now,
            ))


def _sync_similar(session, client, media_type, media_id, tmdb_id):
    data = client.get(f"{media_type}/{tmdb_id}/similar")
    if not data:
        return
    now = datetime.now(timezone.utc)
    for sim in data.get("results", []):
        sim_id = sim["id"]
        existing = session.query(SimilarMedia).filter(
            SimilarMedia.media_type == media_type,
            SimilarMedia.media_id == media_id,
            SimilarMedia.similar_media_id == sim_id,
        ).first()
        if existing is None:
            session.add(SimilarMedia(
                media_type=media_type, media_id=media_id,
                similar_media_id=sim_id,
                similarity_score=sim.get("popularity"),
                last_synced_at=now,
            ))


def _sync_external_ids(session, client, media_type, media_id, tmdb_id):
    data = client.get(f"{media_type}/{tmdb_id}/external_ids")
    if not data:
        return
    now = datetime.now(timezone.utc)
    existing = session.query(ExternalId).filter(
        ExternalId.media_type == media_type, ExternalId.media_id == media_id
    ).first()
    fields = {
        "imdb_id": data.get("imdb_id"),
        "facebook_id": str(data.get("facebook_id") or "") or None,
        "instagram_id": str(data.get("instagram_id") or "") or None,
        "twitter_id": str(data.get("twitter_id") or "") or None,
        "wikidata_id": data.get("wikidata_id"),
        "youtube_id": data.get("youtube_id"),
        "last_synced_at": now,
    }
    if existing is None:
        session.add(ExternalId(media_type=media_type, media_id=media_id, **fields))
    else:
        for k, v in fields.items():
            setattr(existing, k, v)


def _sync_translations(session, client, media_type, media_id, tmdb_id):
    data = client.get(f"{media_type}/{tmdb_id}/translations")
    if not data:
        return
    now = datetime.now(timezone.utc)
    for tr in data.get("translations", []):
        lang = tr.get("iso_639_1") or ""
        region = tr.get("iso_3166_1") or ""
        td = tr.get("data", {})
        existing = session.query(Translation).filter(
            Translation.media_type == media_type,
            Translation.media_id == media_id,
            Translation.language_code == lang,
            Translation.region_code == region,
        ).first()
        fields = {
            "title": td.get("title") or td.get("name"),
            "overview": td.get("overview"),
            "homepage": td.get("homepage"),
            "data_hash": compute_hash(tr),
            "last_synced_at": now,
        }
        if existing is None:
            session.add(Translation(
                media_type=media_type, media_id=media_id,
                language_code=lang, region_code=region, **fields,
            ))
        elif existing.data_hash != fields["data_hash"]:
            for k, v in fields.items():
                setattr(existing, k, v)


def _sync_watch_providers(session, client, media_type, media_id, tmdb_id):
    data = client.get(f"{media_type}/{tmdb_id}/watch/providers")
    if not data:
        return
    now = datetime.now(timezone.utc)
    results = data.get("results", {})
    for country, providers in results.items():
        if country == "link" or not isinstance(providers, dict):
            continue
        for ptype in ("flatrate", "rent", "buy", "free"):
            for prov in providers.get(ptype, []) or []:
                pid = prov["provider_id"]
                wp = session.query(WatchProvider).filter(WatchProvider.tmdb_id == pid).first()
                if wp is None:
                    wp = WatchProvider(
                        tmdb_id=pid,
                        provider_name=prov.get("provider_name", ""),
                        logo_path=prov.get("logo_path"),
                        display_priority=prov.get("display_priority"),
                        provider_type=ptype,
                        country_code=country,
                        data_hash=compute_hash(prov),
                        last_synced_at=now,
                    )
                    session.add(wp)
                    session.flush()
                existing = session.query(MediaWatchProvider).filter(
                    MediaWatchProvider.media_type == media_type,
                    MediaWatchProvider.media_id == media_id,
                    MediaWatchProvider.provider_id == wp.id,
                    MediaWatchProvider.country_code == country,
                    MediaWatchProvider.provider_type == ptype,
                ).first()
                if existing is None:
                    session.add(MediaWatchProvider(
                        media_type=media_type, media_id=media_id,
                        provider_id=wp.id, country_code=country,
                        provider_type=ptype,
                    ))


def _sync_release_dates(session, client, media_id, tmdb_id):
    data = client.get(f"movie/{tmdb_id}/release_dates")
    if not data:
        return
    now = datetime.now(timezone.utc)
    seen: set[tuple] = set()
    for country_data in data.get("results", []):
        cc = country_data.get("iso_3166_1", "")
        for rd in country_data.get("release_dates", []):
            cert = rd.get("certification", "")
            release_type = rd.get("type", 0)
            key = (cc, release_type)
            if key in seen:
                continue
            seen.add(key)
            existing = session.query(Certification).filter(
                Certification.media_type == "movie",
                Certification.media_id == media_id,
                Certification.country_code == cc,
                Certification.release_type == release_type,
            ).first()
            if existing is None:
                session.add(Certification(
                    media_type="movie", media_id=media_id,
                    country_code=cc, release_type=release_type,
                    rating=cert or None,
                    last_synced_at=now,
                ))
            elif cert and existing.rating != cert:
                existing.rating = cert
                existing.last_synced_at = now


def _sync_collection(session, client, collection_id):
    data = client.get(f"collection/{collection_id}")
    if not data:
        return
    now = datetime.now(timezone.utc)
    data_hash = compute_hash(data)
    existing = session.query(Collection).filter(Collection.tmdb_id == collection_id).first()
    fields = {
        "name": data.get("name"),
        "overview": data.get("overview"),
        "poster_path": data.get("poster_path"),
        "backdrop_path": data.get("backdrop_path"),
        "data_hash": data_hash,
        "last_synced_at": now,
    }
    if existing is None:
        session.add(Collection(tmdb_id=collection_id, **fields))
    elif existing.data_hash != data_hash:
        for k, v in fields.items():
            setattr(existing, k, v)


def sync_movie_changes(session: Session, client: TMDBClient, days: int = 1) -> dict:
    """Incremental sync via /movie/changes."""
    stats = {"updated": 0, "failed": 0}
    for page, data in client.paginate("movie/changes", {"start_date": _days_ago(days)}):
        for item in data.get("results", []):
            tmdb_id = item["id"]
            try:
                detail = client.get(f"movie/{tmdb_id}")
                if detail:
                    upsert_movie(session, detail)
                    stats["updated"] += 1
            except Exception as exc:
                stats["failed"] += 1
                logger.error("Incremental movie %s failed: %s", tmdb_id, exc)
        session.commit()
    return stats


def _days_ago(days: int) -> str:
    from datetime import timedelta
    return (datetime.now(timezone.utc) - timedelta(days=days)).strftime("%Y-%m-%d")
