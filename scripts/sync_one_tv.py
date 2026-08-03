"""Sync one TV show fully (detail + related + seasons)."""

import argparse
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from db import get_session, test_connection
from services.full_item_sync import sync_tv_full
from services.tmdb_client import TMDBClient
from utils.logger import get_logger

logger = get_logger(__name__)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("tmdb_id", type=int)
    args = parser.parse_args()

    test_connection()
    session = get_session()
    client = TMDBClient()
    try:
        action = sync_tv_full(session, client, args.tmdb_id)
        logger.info("TV %s → %s", args.tmdb_id, action)
    finally:
        client.close()
        session.close()


if __name__ == "__main__":
    main()
