import argparse
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from db import get_session, test_connection
from models.movie import Movie
from repositories.movie_repo import find_movie_by_tmdb_id, upsert_movie
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
    _sync_videos,
    _sync_watch_providers,
)
from services.tmdb_client import TMDBClient
from utils.logger import get_logger

logger = get_logger(__name__)


def sync_one_movie(session, client, tmdb_id: int) -> Movie:
    logger.info("Starting sync for movie TMDB ID %s", tmdb_id)

    detail = client.get(f"movie/{tmdb_id}")
    if not detail:
        raise RuntimeError(f"TMDB detail not found for movie {tmdb_id}")

    action = upsert_movie(session, detail)
    session.commit()
    logger.info("Detail upsert complete for movie %s (%s)", tmdb_id, action)

    movie = find_movie_by_tmdb_id(session, tmdb_id)
    if movie is None:
        raise RuntimeError(f"Movie {tmdb_id} could not be loaded after upsert")

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
        steps.append(("collection", lambda: _sync_collection(session, client, movie.collection_id)))

    for name, step in steps:
        try:
            step()
            session.flush()
            logger.info("Synced %s for movie %s", name, tmdb_id)
        except Exception as exc:
            logger.warning("Failed %s for movie %s: %s", name, tmdb_id, exc)
            session.rollback()

    session.commit()
    logger.info("Completed sub-detail sync for movie %s", tmdb_id)
    return movie


def main() -> None:
    parser = argparse.ArgumentParser(description="Sync one movie and its sub-detail data")
    parser.add_argument("tmdb_id", nargs="?", type=int, help="TMDB movie id")
    parser.add_argument("--movie-id", type=int, help="Local DB movie id")
    args = parser.parse_args()

    test_connection()
    session = get_session()
    client = TMDBClient()
    try:
        if args.movie_id is not None:
            movie = session.query(Movie).filter(Movie.id == args.movie_id).first()
            if movie is None:
                raise RuntimeError(f"Local movie id {args.movie_id} not found")
            target_tmdb_id = movie.tmdb_id
        elif args.tmdb_id is not None:
            target_tmdb_id = args.tmdb_id
        else:
            movie = session.query(Movie).order_by(Movie.id).first()
            if movie is None:
                raise RuntimeError("No movies found in DB")
            target_tmdb_id = movie.tmdb_id

        sync_one_movie(session, client, target_tmdb_id)
        logger.info("Done for TMDB movie %s", target_tmdb_id)
    finally:
        session.close()


if __name__ == "__main__":
    main()
