import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from db import get_engine, test_connection
from utils.logger import get_logger
from sqlalchemy import text

logger = get_logger(__name__)


def main():
    logger.info("Starting add_user_media_state_table...")
    test_connection()
    engine = get_engine()

    create_sql = text(
        """
        CREATE TABLE IF NOT EXISTS `user_media_state` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `device_id` VARCHAR(191) NOT NULL,
            `media_type` VARCHAR(10) NOT NULL,
            `tmdb_id` INT NOT NULL,
            `is_watched` TINYINT(1) NOT NULL DEFAULT 0,
            `is_saved_for_later` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_user_media_state` (`device_id`, `media_type`, `tmdb_id`),
            KEY `ix_user_media_state_device_id` (`device_id`),
            KEY `ix_user_media_state_tmdb_id` (`tmdb_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        """
    )

    with engine.begin() as conn:
        conn.execute(create_sql)

    logger.info("user_media_state table ensured.")


if __name__ == "__main__":
    main()
