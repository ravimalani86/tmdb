"""Full daily cron: enqueue today's changes then process queue (all API keys)."""

import argparse
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from db import get_session, test_connection
from services.daily_sync import run_daily_sync, sync_day_today
from services.tmdb_client import TMDBClient
from utils.logger import get_logger

logger = get_logger(__name__)


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Daily sync — 3x/day (every 8h). Uses all TMDB_API_KEYS."
    )
    parser.add_argument("--day", help="YYYY-MM-DD override (default today IST)")
    parser.add_argument(
        "--enqueue-only",
        action="store_true",
        help="Only fill queue",
    )
    parser.add_argument(
        "--process-only",
        action="store_true",
        help="Only process existing queue",
    )
    args = parser.parse_args()

    test_connection()
    session = get_session()
    client = TMDBClient(round_robin=True)
    try:
        if args.process_only:
            from services.daily_sync import process_queue, queue_status

            out = {
                "process": process_queue(session),
                "status": queue_status(session, args.day),
            }
        elif args.enqueue_only:
            from services.daily_sync import enqueue_daily_changes, queue_status

            day = args.day or sync_day_today()
            out = {
                "enqueue": enqueue_daily_changes(session, client, sync_day=day),
                "status": queue_status(session, day),
            }
        else:
            # run_daily_sync uses today; if --day set, enqueue then process
            if args.day:
                from services.daily_sync import (
                    enqueue_daily_changes,
                    process_queue,
                    queue_status,
                )

                enq = enqueue_daily_changes(session, client, sync_day=args.day)
                proc = process_queue(session)
                out = {
                    "enqueue": enq,
                    "process": proc,
                    "status": queue_status(session, args.day),
                }
            else:
                out = run_daily_sync(session, client)

        logger.info("DAILY SYNC OK %s", out)
        print(out)
    finally:
        client.close()
        session.close()


if __name__ == "__main__":
    main()
