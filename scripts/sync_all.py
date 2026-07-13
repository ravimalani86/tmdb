import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from db import get_session, test_connection, reset_engine
from scripts.wait_and_sync import wait_for_mysql
from services.genre_sync import sync_genres
from services.movie_sync import sync_movies
from services.tv_sync import sync_tv_shows
from services.people_sync import sync_people
from services.tmdb_client import TMDBClient
from utils.logger import get_logger
from sqlalchemy import text

logger = get_logger(__name__)


def validate_counts(session):
    tables = [
        "genres", "movies", "tv_shows", "people", "credits", "videos",
        "images", "collections", "keywords", "recommendations",
        "tv_seasons", "tv_episodes", "watch_providers",
    ]
    logger.info("--- Validation ---")
    for table in tables:
        try:
            count = session.execute(text(f"SELECT COUNT(*) FROM `{table}`")).scalar()
            logger.info("%s: %s rows", table, count)
        except Exception as exc:
            logger.warning("%s: %s", table, exc)


def main():
    logger.info("=== TMDB Full Sync ===")
    start = time.time()

    logger.info("Connecting database...")
    wait_for_mysql(max_wait=120)
    test_connection()
    logger.info("Database connected.")

    session = get_session()
    client = TMDBClient()

    try:
        logger.info("Step 1/4: Syncing genres...")
        sync_genres(session, client)

        logger.info("Step 2/4: Syncing movies...")
        sync_movies(session, client)

        logger.info("Step 3/4: Syncing TV shows...")
        sync_tv_shows(session, client)

        logger.info("Step 4/4: Syncing people...")
        sync_people(session, client)

        validate_counts(session)
        logger.info("Full sync completed in %.1fs", time.time() - start)
    finally:
        session.close()


if __name__ == "__main__":
    main()
