import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from db import get_session, test_connection
from services.tv_sync import sync_tv_shows
from services.tmdb_client import TMDBClient
from utils.logger import get_logger
from sqlalchemy import text

logger = get_logger(__name__)


def main():
    logger.info("TV all-in-one sync (detail → credits → providers → similar → seasons)")
    logger.info("For step-by-step, use sync_tv_detail.py, sync_tv_credits.py, etc.")
    test_connection()
    session = get_session()
    client = TMDBClient()
    try:
        sync_tv_shows(session, client)
        count = session.execute(text("SELECT COUNT(*) FROM tv_shows")).scalar()
        logger.info("Validation: tv_shows table has %s rows", count)
    finally:
        session.close()


if __name__ == "__main__":
    main()
