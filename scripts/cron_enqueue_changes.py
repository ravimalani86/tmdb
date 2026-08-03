"""Enqueue today's provider-scoped TMDB changes into media_sync_queue."""

import argparse
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from db import get_session, test_connection
from services.daily_sync import enqueue_daily_changes, sync_day_today
from services.tmdb_client import TMDBClient
from utils.logger import get_logger

logger = get_logger(__name__)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--day", help="YYYY-MM-DD (default: today IST)")
    args = parser.parse_args()

    test_connection()
    session = get_session()
    client = TMDBClient(round_robin=True)
    try:
        day = args.day or sync_day_today()
        stats = enqueue_daily_changes(session, client, sync_day=day)
        logger.info("ENQUEUE OK %s", stats)
        print(stats)
    finally:
        client.close()
        session.close()


if __name__ == "__main__":
    main()
