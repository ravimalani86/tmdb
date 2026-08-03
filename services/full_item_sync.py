"""Full single-item sync (movie / tv / person) for daily cron queue."""

from __future__ import annotations

from sqlalchemy.orm import Session

from models.movie import Credit, Movie
from models.people import Person
from models.tv import TvShow
from repositories.movie_repo import find_movie_by_tmdb_id, upsert_movie
from repositories.people_repo import find_person_by_tmdb_id, upsert_person
from repositories.tv_repo import find_tv_by_tmdb_id, upsert_tv_show
from services.movie_sync import (
    _sync_collection,
    _sync_credits,
    _sync_external_ids,
    _sync_images,
    _sync_keywords,
    _sync_recommendations,
    _sync_release_dates,
    _sync_similar,
    _sync_translations,
    _sync_tv_aggregate_credits,
    _sync_videos,
    _sync_watch_providers,
)
from services.people_sync import _sync_person_external_ids, _sync_person_images
from services.tmdb_client import TMDBClient
from services.tv_sync import _sync_tv_seasons_for_show
from utils.logger import get_logger

logger = get_logger(__name__)


def sync_movie_full(session: Session, client: TMDBClient, tmdb_id: int) -> str:
    """Upsert movie detail + all related tables. Returns inserted|updated|skipped."""
    detail = client.get(f"movie/{tmdb_id}")
    if not detail:
        raise RuntimeError(f"TMDB movie {tmdb_id} not found")

    action = upsert_movie(session, detail)
    session.commit()

    movie = find_movie_by_tmdb_id(session, tmdb_id)
    if movie is None:
        raise RuntimeError(f"Movie {tmdb_id} missing after upsert")

    steps = [
        ("credits", lambda: _sync_credits(session, client, "movie", movie.id, tmdb_id)),
        ("watch_providers", lambda: _sync_watch_providers(session, client, "movie", movie.id, tmdb_id)),
        ("similar", lambda: _sync_similar(session, client, "movie", movie.id, tmdb_id)),
        ("videos", lambda: _sync_videos(session, client, "movie", movie.id, tmdb_id)),
        ("images", lambda: _sync_images(session, client, "movie", movie.id, tmdb_id)),
        ("keywords", lambda: _sync_keywords(session, client, "movie", movie.id, tmdb_id)),
        ("recommendations", lambda: _sync_recommendations(session, client, "movie", movie.id, tmdb_id)),
        ("external_ids", lambda: _sync_external_ids(session, client, "movie", movie.id, tmdb_id)),
        ("translations", lambda: _sync_translations(session, client, "movie", movie.id, tmdb_id)),
        ("release_dates", lambda: _sync_release_dates(session, client, movie.id, tmdb_id)),
    ]
    if movie.collection_id:
        cid = movie.collection_id
        steps.append(("collection", lambda: _sync_collection(session, client, cid)))

    for name, step in steps:
        try:
            step()
            session.flush()
            logger.info("Movie %s — synced %s", tmdb_id, name)
        except Exception as exc:
            logger.warning("Movie %s — %s failed: %s", tmdb_id, name, exc)
            session.rollback()
            movie = find_movie_by_tmdb_id(session, tmdb_id)
            if movie is None:
                raise

    session.commit()
    return action


def sync_tv_full(session: Session, client: TMDBClient, tmdb_id: int) -> str:
    """Upsert TV detail + related + full seasons/episodes."""
    detail = client.get(f"tv/{tmdb_id}")
    if not detail:
        raise RuntimeError(f"TMDB tv {tmdb_id} not found")

    action = upsert_tv_show(session, detail)
    session.commit()

    show = find_tv_by_tmdb_id(session, tmdb_id)
    if show is None:
        raise RuntimeError(f"TV {tmdb_id} missing after upsert")

    steps = [
        ("credits", lambda: _sync_tv_aggregate_credits(session, client, show.id, tmdb_id)),
        ("watch_providers", lambda: _sync_watch_providers(session, client, "tv", show.id, tmdb_id)),
        ("similar", lambda: _sync_similar(session, client, "tv", show.id, tmdb_id)),
        ("videos", lambda: _sync_videos(session, client, "tv", show.id, tmdb_id)),
        ("images", lambda: _sync_images(session, client, "tv", show.id, tmdb_id)),
        ("keywords", lambda: _sync_keywords(session, client, "tv", show.id, tmdb_id)),
        ("recommendations", lambda: _sync_recommendations(session, client, "tv", show.id, tmdb_id)),
        ("external_ids", lambda: _sync_external_ids(session, client, "tv", show.id, tmdb_id)),
        ("translations", lambda: _sync_translations(session, client, "tv", show.id, tmdb_id)),
    ]

    for name, step in steps:
        try:
            step()
            session.flush()
            logger.info("TV %s — synced %s", tmdb_id, name)
        except Exception as exc:
            logger.warning("TV %s — %s failed: %s", tmdb_id, name, exc)
            session.rollback()
            show = find_tv_by_tmdb_id(session, tmdb_id)
            if show is None:
                raise

    session.commit()

    try:
        show = find_tv_by_tmdb_id(session, tmdb_id)
        if show:
            _sync_tv_seasons_for_show(session, client, show, tmdb_id)
            session.commit()
            logger.info("TV %s — seasons synced", tmdb_id)
    except Exception as exc:
        logger.warning("TV %s — seasons failed: %s", tmdb_id, exc)
        session.rollback()

    return action


def sync_person_full(session: Session, client: TMDBClient, tmdb_id: int) -> str:
    detail = client.get(f"person/{tmdb_id}")
    if not detail:
        raise RuntimeError(f"TMDB person {tmdb_id} not found")

    action = upsert_person(session, detail)
    session.commit()
    person = find_person_by_tmdb_id(session, tmdb_id)
    if person is None:
        raise RuntimeError(f"Person {tmdb_id} missing after upsert")

    try:
        _sync_person_images(session, client, person, tmdb_id)
        _sync_person_external_ids(session, client, person, tmdb_id)
        session.commit()
    except Exception as exc:
        logger.warning("Person %s extras failed: %s", tmdb_id, exc)
        session.rollback()

    return action


def credit_person_tmdb_ids(session: Session, media_type: str, media_id: int) -> list[int]:
    """Top-billed cast (+ director) only — avoids flooding the daily queue."""
    rows = (
        session.query(Credit.person_id)
        .filter(
            Credit.media_type == media_type,
            Credit.media_id == media_id,
            (
                ((Credit.credit_type == "cast") & (Credit.order_index.isnot(None)) & (Credit.order_index < 20))
                | ((Credit.credit_type == "crew") & (Credit.job == "Director"))
            ),
        )
        .all()
    )
    local_ids = [r[0] for r in rows if r[0]]
    if not local_ids:
        return []
    people = session.query(Person.tmdb_id).filter(Person.id.in_(local_ids)).all()
    return [int(p[0]) for p in people]
