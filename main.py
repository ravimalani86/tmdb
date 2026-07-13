"""TMDB Local DB Sync — main entry point."""

import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from db import get_session, test_connection
from scripts.create_tables import create_all_tables
from services.genre_sync import sync_genres
from services.movie_sync import sync_movies
from services.tv_sync import sync_tv_shows
from services.people_sync import sync_people
from services.tmdb_client import TMDBClient
from utils.logger import get_logger

logger = get_logger(__name__)


def main():
    logger.info("TMDB Local Database Sync Engine")
    start = time.time()

    create_all_tables()
    test_connection()

    session = get_session()
    client = TMDBClient()
    try:
        sync_genres(session, client)
        sync_movies(session, client)
        sync_tv_shows(session, client)
        sync_people(session, client)
    finally:
        session.close()

    logger.info("Sync engine finished in %.1fs", time.time() - start)


if __name__ == "__main__":
    main()
