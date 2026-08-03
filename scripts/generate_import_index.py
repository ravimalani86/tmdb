"""Generate table-wise import index without running export."""

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from utils.cloud_export import get_export_run_dir, write_import_index
from utils.logger import get_logger

logger = get_logger(__name__)


def main():
    run_dir = get_export_run_dir()
    path = write_import_index(run_dir)
    logger.info("Done — open %s", path)


if __name__ == "__main__":
    main()
