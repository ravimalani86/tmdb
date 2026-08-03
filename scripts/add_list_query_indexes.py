"""Add indexes that speed up POST /movies and POST /tv list filters."""

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from db import get_engine, test_connection
from utils.logger import get_logger
from sqlalchemy import text

logger = get_logger(__name__)

INDEXES = [
    (
        "media_watch_providers",
        "ix_mwp_type_country_media",
        "CREATE INDEX ix_mwp_type_country_media ON media_watch_providers (media_type, country_code, media_id)",
    ),
    (
        "media_watch_providers",
        "ix_mwp_type_country_ptype_media",
        "CREATE INDEX ix_mwp_type_country_ptype_media ON media_watch_providers (media_type, country_code, provider_type, media_id)",
    ),
    (
        "movies",
        "ix_movies_active_pop",
        "CREATE INDEX ix_movies_active_pop ON movies (is_active, popularity, tmdb_id)",
    ),
    (
        "tv_shows",
        "ix_tv_active_pop",
        "CREATE INDEX ix_tv_active_pop ON tv_shows (is_active, popularity, tmdb_id)",
    ),
    (
        "media_genres",
        "ix_media_genres_type_genre_media",
        "CREATE INDEX ix_media_genres_type_genre_media ON media_genres (media_type, genre_id, media_id)",
    ),
    (
        "media_watch_providers",
        "ix_mwp_media_lookup",
        "CREATE INDEX ix_mwp_media_lookup ON media_watch_providers (media_id, media_type, country_code, provider_type, provider_id)",
    ),
]


def index_exists(conn, table: str, name: str) -> bool:
    row = conn.execute(
        text(
            """
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = :table
              AND index_name = :name
            LIMIT 1
            """
        ),
        {"table": table, "name": name},
    ).fetchone()
    return row is not None


def main():
    logger.info("Starting add_list_query_indexes...")
    test_connection()
    engine = get_engine()

    with engine.begin() as conn:
        for table, name, ddl in INDEXES:
            if index_exists(conn, table, name):
                logger.info("Index already exists: %s.%s", table, name)
                continue
            logger.info("Creating index %s.%s ...", table, name)
            conn.execute(text(ddl))
            logger.info("Created index %s.%s", table, name)

    logger.info("Done.")


if __name__ == "__main__":
    main()
