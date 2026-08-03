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
    logger.info("Starting add_tv_season_sync_jobs_table...")
    test_connection()
    engine = get_engine()

    create_sql = text(
        """
        CREATE TABLE IF NOT EXISTS `tv_season_sync_jobs` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `tv_show_id` INT NOT NULL,
            `tmdb_id` INT NOT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `last_processed_season` INT NULL,
            `error_message` TEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_tv_season_sync_job_show` (`tv_show_id`),
            KEY `ix_tv_season_sync_jobs_tv_show_id` (`tv_show_id`),
            KEY `ix_tv_season_sync_jobs_tmdb_id` (`tmdb_id`),
            KEY `ix_tv_season_sync_jobs_status` (`status`),
            CONSTRAINT `fk_tv_season_sync_jobs_show`
                FOREIGN KEY (`tv_show_id`) REFERENCES `tv_shows` (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        """
    )

    with engine.begin() as conn:
        conn.execute(create_sql)

    logger.info("tv_season_sync_jobs table ensured.")


if __name__ == "__main__":
    main()
