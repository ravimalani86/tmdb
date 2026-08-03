"""Export movie API tables — one SQL file per table in exports/<date-time>/."""

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from db import get_engine, test_connection
from utils.cloud_export import MOVIE_TABLES, get_export_run_dir, run_group_export, write_import_index
from utils.logger import get_logger

from models import genres, movie, people, sync_state, tv  # noqa: F401

logger = get_logger(__name__)


def main():
    logger.info("Starting export_sync_movies (%s table files)...", len(MOVIE_TABLES))
    test_connection()
    engine = get_engine()
    run_dir = get_export_run_dir()

    total = run_group_export(engine, MOVIE_TABLES, run_dir)
    logger.info("Movie export done — %s rows across %s files in %s", total, len(MOVIE_TABLES), run_dir)
    write_import_index(run_dir)


if __name__ == "__main__":
    main()
