import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

import config
from db import get_session, test_connection
from services.tv_sync import sync_tv_seasons
from services.tmdb_client import TMDBClient
from utils.logger import get_logger
from sqlalchemy import text

logger = get_logger(__name__)


def main():
    season_keys = config.SYNC_SEASON_KEY_INDEXES
    workers = config.SYNC_SEASON_WORKERS
    logger.info(
        "Phase 5: TV seasons + episodes (%s workers, indexes %s)",
        workers,
        season_keys,
    )
    test_connection()
    session = get_session()
    # Client arg unused for API — sync_tv_seasons builds per-worker pools.
    client = TMDBClient(credential_indexes=season_keys[:1], rotate_on_limit=False)
    try:
        sync_tv_seasons(session, client)
        seasons = session.execute(text("SELECT COUNT(*) FROM tv_seasons")).scalar()
        episodes = session.execute(text("SELECT COUNT(*) FROM tv_episodes")).scalar()
        logger.info("Validation: tv_seasons=%s, tv_episodes=%s", seasons, episodes)
    finally:
        client.close()
        session.close()


if __name__ == "__main__":
    main()
