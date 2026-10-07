-- One-time move of the appmanagement admin tables into tmdbdata.
-- Does not change catalog tables used by the TMDB API
-- (app_configs, media_sync_queue, movie_battles, and the rest).
-- Run on a server where the appmanagement database still exists:
--   mysql -uroot tmdbdata < database/move_admin_panel.sql

CREATE DATABASE IF NOT EXISTS tmdbdata CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE tmdbdata;

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(64) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'admin',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO tmdbdata.users SELECT * FROM appmanagement.users;
