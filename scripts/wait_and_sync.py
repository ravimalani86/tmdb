"""Wait for MySQL to become available, then run full sync."""

import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from db import reset_engine, test_connection
from utils.logger import get_logger

logger = get_logger(__name__)


def wait_for_mysql(max_wait: int = 600) -> None:
    start = time.time()
    attempt = 0
    while time.time() - start < max_wait:
        attempt += 1
        try:
            reset_engine()
            test_connection(retries=1)
            logger.info("MySQL is available.")
            return
        except Exception as exc:
            logger.warning(
                "Waiting for MySQL (attempt %s): %s", attempt, exc
            )
            time.sleep(min(2 ** min(attempt, 6), 30))
    raise RuntimeError(f"MySQL not available after {max_wait}s")


if __name__ == "__main__":
    wait_for_mysql()
    from scripts.sync_all import main
    main()
