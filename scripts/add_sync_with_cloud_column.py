import sys
from pathlib import Path

# Ensure project root is on sys.path so `from db import ...` works when run as a script
ROOT = Path(__file__).resolve().parent.parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from sqlalchemy import inspect, text

from db import get_engine, test_connection
from db import Base
from utils.logger import get_logger

# Ensure model metadata is loaded so SQLAlchemy knows all tables.
from models import genres, movie, people, sync_state, tv  # noqa: F401

logger = get_logger(__name__)


def ensure_sync_column(engine, table_name: str) -> None:
    inspector = inspect(engine)
    columns = [col["name"] for col in inspector.get_columns(table_name)]
    if "sync_with_cloud" in columns:
        logger.info("Table %s already has sync_with_cloud", table_name)
        return

    logger.info("Adding sync_with_cloud to %s", table_name)
    alter_sql = text(
        f"ALTER TABLE `{table_name}` ADD COLUMN sync_with_cloud TINYINT(1) NOT NULL DEFAULT 0"
    )
    with engine.begin() as conn:
        conn.execute(alter_sql)


def main():
    logger.info("Starting add_sync_with_cloud_column...")
    test_connection()
    engine = get_engine()

    tables = [table.name for table in Base.metadata.sorted_tables]
    if not tables:
        logger.error("No tables found in metadata.")
        return

    for table_name in tables:
        try:
            ensure_sync_column(engine, table_name)
        except Exception as exc:
            logger.error("Failed to alter table %s: %s", table_name, exc)

    logger.info("Done adding sync_with_cloud column to %s tables", len(tables))


if __name__ == "__main__":
    main()
