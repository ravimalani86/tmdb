-- Daily cron sync queue (movie / tv / person)
-- Run once on local + live DB.

CREATE TABLE IF NOT EXISTS media_sync_queue (
  id INT AUTO_INCREMENT PRIMARY KEY,
  media_type VARCHAR(16) NOT NULL,
  tmdb_id INT NOT NULL,
  sync_day VARCHAR(10) NOT NULL COMMENT 'YYYY-MM-DD (IST calendar day)',
  source VARCHAR(32) NOT NULL DEFAULT 'changes',
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  attempts INT NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  worker_id INT NULL,
  enqueued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  UNIQUE KEY uq_media_sync_queue_day_item (media_type, tmdb_id, sync_day),
  KEY ix_media_sync_queue_status (status),
  KEY ix_media_sync_queue_day (sync_day),
  KEY ix_media_sync_queue_type (media_type),
  KEY ix_media_sync_queue_tmdb (tmdb_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
