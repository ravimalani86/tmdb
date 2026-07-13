import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from db import get_session, test_connection
from services.genre_sync import sync_genres
from services.tmdb_client import TMDBClient
from utils.logger import get_logger
from sqlalchemy import text

logger = get_logger(__name__)


def main():
    logger.info("Starting genre sync...")
    test_connection()
    session = get_session()
    client = TMDBClient()
    try:
        sync_genres(session, client)
        count = session.execute(text("SELECT COUNT(*) FROM genres")).scalar()
        logger.info("Validation: genres table has %s rows", count)
    finally:
        session.close()


if __name__ == "__main__":
    main()
