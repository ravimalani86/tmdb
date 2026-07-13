import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from db import get_session, test_connection
from services.movie_sync import sync_movies_videos
from services.tmdb_client import TMDBClient
from utils.logger import get_logger
from sqlalchemy import text

logger = get_logger(__name__)


def main():
    logger.info("Phase 5: Movie videos sync (GET /movie/{id}/videos)")
    test_connection()
    session = get_session()
    client = TMDBClient()
    try:
        sync_movies_videos(session, client)
        count = session.execute(
            text("SELECT COUNT(*) FROM videos WHERE media_type = 'movie'")
        ).scalar()
        logger.info("Validation: movie videos rows = %s", count)
    finally:
        session.close()


if __name__ == "__main__":
    main()
