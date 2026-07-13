import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from db import get_session
from services.genre_sync import sync_genres
from services.movie_sync import sync_movies
from services.tmdb_client import TMDBClient
from utils.logger import get_logger
from sqlalchemy import text

logger = get_logger(__name__)


def main():
    logger.info("Starting movie sync...")
    session = get_session()
    client = TMDBClient()
    try:
        sync_movies(session, client)
        count = session.execute(text("SELECT COUNT(*) FROM movies")).scalar()
        logger.info("Validation: movies table has %s rows", count)
    finally:
        session.close()


if __name__ == "__main__":
    main()
