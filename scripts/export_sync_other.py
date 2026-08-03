"""Export shared lookup tables — one SQL file per table in exports/<date-time>/."""

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from db import get_engine, test_connection
from utils.cloud_export import OTHER_TABLES, run_group_export, start_export_run, write_import_index
from utils.logger import get_logger

from models import genres, movie, people, sync_state, tv  # noqa: F401

logger = get_logger(__name__)


def main():
    logger.info("Starting export_sync_other (%s table files)...", len(OTHER_TABLES))
    test_connection()
    engine = get_engine()
    run_dir = start_export_run()

    total = run_group_export(engine, OTHER_TABLES, run_dir)
    logger.info("Other export done — %s rows across %s files in %s", total, len(OTHER_TABLES), run_dir)
    write_import_index(run_dir)


if __name__ == "__main__":
    main()
