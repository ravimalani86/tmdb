"""Process media_sync_queue with all TMDB API keys in parallel."""

import argparse
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from db import get_session, test_connection
from services.daily_sync import process_queue, queue_status
from utils.logger import get_logger

logger = get_logger(__name__)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--batch-size", type=int, default=40)
    parser.add_argument("--max-batches", type=int, default=500)
    args = parser.parse_args()

    test_connection()
    session = get_session()
    try:
        stats = process_queue(
            session,
            batch_size=args.batch_size,
            max_batches=args.max_batches,
        )
        status = queue_status(session)
        logger.info("PROCESS OK %s | status %s", stats, status)
        print({"process": stats, "status": status})
    finally:
        session.close()


if __name__ == "__main__":
    main()
