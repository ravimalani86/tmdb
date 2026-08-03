-- List-query indexes (from scripts/add_list_query_indexes.py)
-- Run in phpMyAdmin / MySQL on the LIVE database (growdevi_tmdbdata or your DB).
-- If an index already exists, MySQL returns "Duplicate key name" — skip that line and continue.
--
-- Note: media_watch_providers indexes on large tables can take 1–5 minutes each.

-- 1) Provider filters (country / type)
CREATE INDEX ix_mwp_type_country_media
  ON media_watch_providers (media_type, country_code, media_id);

CREATE INDEX ix_mwp_type_country_ptype_media
  ON media_watch_providers (media_type, country_code, provider_type, media_id);

-- 2) Popularity list sorts
CREATE INDEX ix_movies_active_pop
  ON movies (is_active, popularity, tmdb_id);

CREATE INDEX ix_tv_active_pop
  ON tv_shows (is_active, popularity, tmdb_id);

-- 3) Genre filters
CREATE INDEX ix_media_genres_type_genre_media
  ON media_genres (media_type, genre_id, media_id);

-- 4) EXISTS provider lookup (biggest speed win for /movies + /tv + home rows)
CREATE INDEX ix_mwp_media_lookup
  ON media_watch_providers (media_id, media_type, country_code, provider_type, provider_id);

-- Optional: verify after run
-- SHOW INDEX FROM media_watch_providers;
-- SHOW INDEX FROM movies;
-- SHOW INDEX FROM tv_shows;
-- SHOW INDEX FROM media_genres;
