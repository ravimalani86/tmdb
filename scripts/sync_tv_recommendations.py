import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from db import get_session, test_connection
from services.tv_sync import sync_tv_recommendations
from services.tmdb_client import TMDBClient
from utils.logger import get_logger
from sqlalchemy import text

logger = get_logger(__name__)


def main():
    logger.info("Phase 9: TV recommendations sync (GET /tv/{id}/recommendations)")
    test_connection()
    session = get_session()
    client = TMDBClient()
    try:
        sync_tv_recommendations(session, client)
        count = session.execute(
            text("SELECT COUNT(*) FROM recommendations WHERE media_type = 'tv'")
        ).scalar()
        logger.info("Validation: tv recommendations rows = %s", count)
    finally:
        session.close()


if __name__ == "__main__":
    main()
