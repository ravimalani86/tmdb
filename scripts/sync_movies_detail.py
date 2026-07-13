import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from db import get_session, test_connection
from services.movie_sync import sync_movies_detail
from services.tmdb_client import TMDBClient
from utils.logger import get_logger
from sqlalchemy import text

logger = get_logger(__name__)


def main():
    logger.info("Phase 1: Movie detail sync (GET /movie/{id})")
    test_connection()
    session = get_session()
    client = TMDBClient()
    try:
        sync_movies_detail(session, client)
        count = session.execute(text("SELECT COUNT(*) FROM movies")).scalar()
        logger.info("Validation: movies table has %s rows", count)
    finally:
        session.close()


if __name__ == "__main__":
    main()
