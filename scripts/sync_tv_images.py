import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from db import get_session, test_connection
from services.tv_sync import sync_tv_images
from services.tmdb_client import TMDBClient
from utils.logger import get_logger
from sqlalchemy import text

logger = get_logger(__name__)


def main():
    logger.info("Phase 7: TV images sync (GET /tv/{id}/images)")
    test_connection()
    session = get_session()
    client = TMDBClient()
    try:
        sync_tv_images(session, client)
        count = session.execute(
            text("SELECT COUNT(*) FROM images WHERE media_type = 'tv'")
        ).scalar()
        logger.info("Validation: tv images rows = %s", count)
    finally:
        session.close()


if __name__ == "__main__":
    main()
