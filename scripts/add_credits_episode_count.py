import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from sqlalchemy import inspect, text

from db import get_engine, test_connection
from utils.logger import get_logger

logger = get_logger(__name__)


def main():
    logger.info("Adding episode_count column to credits...")
    test_connection()
    engine = get_engine()
    inspector = inspect(engine)
    columns = [col["name"] for col in inspector.get_columns("credits")]
    if "episode_count" in columns:
        logger.info("credits.episode_count already exists")
        return

    with engine.begin() as conn:
        conn.execute(text("ALTER TABLE `credits` ADD COLUMN episode_count INT NULL"))
    logger.info("Done — credits.episode_count added")


if __name__ == "__main__":
    main()
