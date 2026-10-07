-- Import into the existing TMDB database before uploading the Battle API.
-- No existing catalog tables or data are changed. Daily dates use Asia/Kolkata.
CREATE TABLE IF NOT EXISTS movie_battles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  battle_date DATE NOT NULL,
  movie_a_tmdb_id INT UNSIGNED NOT NULL,
  movie_b_tmdb_id INT UNSIGNED NOT NULL,
  category VARCHAR(100) NOT NULL DEFAULT '',
  status ENUM('scheduled', 'disabled') NOT NULL DEFAULT 'scheduled',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_battle_date (battle_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS movie_battle_votes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  battle_id BIGINT UNSIGNED NOT NULL,
  voter_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  selected_tmdb_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_battle_voter (battle_id, voter_id),
  KEY idx_battle_selection (battle_id, selected_tmdb_id),
  CONSTRAINT fk_battle_vote FOREIGN KEY (battle_id) REFERENCES movie_battles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
