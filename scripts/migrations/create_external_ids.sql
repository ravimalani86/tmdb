-- Live DB: create external_ids if missing (phpMyAdmin / MySQL)
-- Database: myapprhc_tmdb

CREATE TABLE IF NOT EXISTS external_ids (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  media_type VARCHAR(10) NOT NULL,
  media_id INT NOT NULL,
  imdb_id VARCHAR(20) NULL,
  facebook_id VARCHAR(128) NULL,
  instagram_id VARCHAR(128) NULL,
  twitter_id VARCHAR(128) NULL,
  wikidata_id VARCHAR(64) NULL,
  youtube_id VARCHAR(128) NULL,
  last_synced_at DATETIME NULL,
  UNIQUE KEY uq_external_id (media_type, media_id),
  KEY idx_external_ids_media_id (media_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reset person rows burned by the missing-table error so they can retry
UPDATE media_sync_queue
SET status = 'pending',
    attempts = 0,
    last_error = NULL,
    started_at = NULL,
    finished_at = NULL,
    worker_id = NULL
WHERE media_type = 'person'
  AND status IN ('failed', 'pending', 'running')
  AND last_error LIKE '%external_ids%';
