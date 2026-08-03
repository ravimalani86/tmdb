import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from db import get_session, test_connection
from services.people_sync import sync_people_detail
from services.tmdb_client import TMDBClient
from utils.logger import get_logger
from sqlalchemy import text

logger = get_logger(__name__)


def main():
    logger.info(
        "People detail sync — GET /person/{tmdb_id} for stub rows (biography IS NULL)"
    )
    test_connection()
    session = get_session()
    client = TMDBClient()
    try:
        sync_people_detail(session, client)
        row = session.execute(
            text(
                """
                SELECT
                  COUNT(*) AS total,
                  SUM(biography IS NOT NULL AND biography <> '') AS with_bio,
                  SUM(birthday IS NOT NULL) AS with_birthday,
                  SUM(place_of_birth IS NOT NULL AND place_of_birth <> '') AS with_pob,
                  SUM(imdb_id IS NOT NULL AND imdb_id <> '') AS with_imdb
                FROM people
                """
            )
        ).mappings().first()
        logger.info("Validation people fill: %s", dict(row) if row else None)
    finally:
        session.close()
        client.close()


if __name__ == "__main__":
    main()
