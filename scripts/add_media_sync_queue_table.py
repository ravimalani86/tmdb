"""Create media_sync_queue table for daily cron sync."""

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from db import Base, get_engine, test_connection
from models.sync_state import MediaSyncQueue
from utils.logger import get_logger

logger = get_logger(__name__)


def main() -> None:
    logger.info("Creating media_sync_queue...")
    test_connection()
    engine = get_engine()
    MediaSyncQueue.__table__.create(bind=engine, checkfirst=True)
    logger.info("Done. Table %s ready.", MediaSyncQueue.__tablename__)


if __name__ == "__main__":
    main()
