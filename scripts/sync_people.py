import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from db import get_session
from services.people_sync import sync_people
from services.tmdb_client import TMDBClient
from utils.logger import get_logger
from sqlalchemy import text

logger = get_logger(__name__)


def main():
    logger.info("Starting people sync...")
    session = get_session()
    client = TMDBClient()
    try:
        sync_people(session, client)
        count = session.execute(text("SELECT COUNT(*) FROM people")).scalar()
        logger.info("Validation: people table has %s rows", count)
    finally:
        session.close()


if __name__ == "__main__":
    main()
