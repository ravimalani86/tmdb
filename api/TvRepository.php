<?php

declare(strict_types=1);

final class TvRepository
{
    private MediaExtrasRepository $extras;

    public function __construct(private PDO $db)
    {
        $this->extras = new MediaExtrasRepository($db);
    }

    public function listShows(array $filters): array
    {
        $page = $filters['page'];
        $limit = $filters['limit'];
        $offset = ($page - 1) * $limit;
        $sort = $filters['sort'];
        $order = $filters['order'];

        $where = ['t.is_active = 1'];
        $params = [];

        if ($filters['search'] !== null) {
            $where[] = 't.name LIKE :search';
            $params['search'] = '%' . $filters['search'] . '%';
        }
        if ($filters['genre_tmdb_id'] !== null) {
            $where[] = 'g.tmdb_id = :genre_tmdb_id';
            $params['genre_tmdb_id'] = $filters['genre_tmdb_id'];
        }
        if ($filters['provider_tmdb_id'] !== null) {
            $where[] = 'wp.tmdb_id = :provider_tmdb_id';
            $params['provider_tmdb_id'] = $filters['provider_tmdb_id'];
        }
        if ($filters['country'] !== null) {
            $where[] = 'mwp.country_code = :country';
            $params['country'] = strtoupper($filters['country']);
        }
        $providerSqlFilter = '';
        if ($filters['provider_tmdb_id'] !== null || $filters['country'] !== null) {
            $providerSqlFilter = ProvidersConfig::sqlFilter('mwp', 'wp', $filters['country']);
            if (
                $filters['provider_tmdb_id'] !== null
                && ProvidersConfig::isActive()
                && ($filters['country'] !== null
                    ? !ProvidersConfig::isAllowed($filters['country'], $filters['provider_tmdb_id'])
                    : !ProvidersConfig::isAllowedInAnyRegion($filters['provider_tmdb_id']))
            ) {
                return [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => 0,
                    'total_pages' => 0,
                    'data' => [],
                ];
            }
        }
        if ($filters['spoken_language'] !== null) {
            $where[] = 'sl_filter.iso_code = :spoken_language';
            $params['spoken_language'] = strtolower($filters['spoken_language']);
        }

        $whereSql = implode(' AND ', $where);
        $joins = '';
        if ($filters['genre_tmdb_id'] !== null) {
            $joins .= ' INNER JOIN media_genres mg ON mg.media_id = t.id AND mg.media_type = \'tv\'';
            $joins .= ' INNER JOIN genres g ON g.id = mg.genre_id';
        } else {
            $joins .= ' LEFT JOIN media_genres mg ON mg.media_id = t.id AND mg.media_type = \'tv\'';
            $joins .= ' LEFT JOIN genres g ON g.id = mg.genre_id';
        }
        if ($filters['provider_tmdb_id'] !== null || $filters['country'] !== null) {
            $joins .= ' INNER JOIN media_watch_providers mwp ON mwp.media_id = t.id AND mwp.media_type = \'tv\'';
            $joins .= ' INNER JOIN watch_providers wp ON wp.id = mwp.provider_id';
            $whereSql = implode(' AND ', $where) . $providerSqlFilter;
        } else {
            $joins .= ' LEFT JOIN media_watch_providers mwp ON mwp.media_id = t.id AND mwp.media_type = \'tv\'';
            $joins .= ' LEFT JOIN watch_providers wp ON wp.id = mwp.provider_id';
            $whereSql = implode(' AND ', $where);
        }
        if ($filters['spoken_language'] !== null) {
            $joins .= ' INNER JOIN media_spoken_languages msl_filter ON msl_filter.media_id = t.id AND msl_filter.media_type = \'tv\'';
            $joins .= ' INNER JOIN spoken_languages sl_filter ON sl_filter.id = msl_filter.language_id';
        }

        $countSql = "SELECT COUNT(DISTINCT t.id) FROM tv_shows t {$joins} WHERE {$whereSql}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $orderColumn = match ($sort) {
            'vote_average' => 't.vote_average',
            'first_air_date' => 't.first_air_date',
            'name' => 't.name',
            default => 't.popularity',
        };

        $sql = "
            SELECT DISTINCT
                t.id,
                t.tmdb_id,
                t.name,
                t.poster_path,
                t.backdrop_path,
                t.vote_average,
                t.vote_count,
                t.first_air_date,
                t.popularity,
                t.original_language,
                t.number_of_seasons,
                t.number_of_episodes
            FROM tv_shows t
            {$joins}
            WHERE {$whereSql}
            ORDER BY {$orderColumn} {$order}, t.tmdb_id DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $shows = [];
        foreach ($rows as $row) {
            $shows[] = [
                'tmdb_id' => (int) $row['tmdb_id'],
                'name' => $row['name'],
                'poster_url' => tmdb_image($row['poster_path'], 'w500'),
                'backdrop_url' => tmdb_image($row['backdrop_path'], 'original'),
                'vote_average' => $row['vote_average'] !== null ? (float) $row['vote_average'] : null,
                'vote_count' => $row['vote_count'] !== null ? (int) $row['vote_count'] : null,
                'first_air_date' => $row['first_air_date'],
                'popularity' => $row['popularity'] !== null ? (float) $row['popularity'] : null,
                'original_language' => $row['original_language'],
                'number_of_seasons' => $row['number_of_seasons'] !== null ? (int) $row['number_of_seasons'] : null,
                'number_of_episodes' => $row['number_of_episodes'] !== null ? (int) $row['number_of_episodes'] : null,
                'genres' => $this->getGenreNames((int) $row['id']),
                'spoken_languages' => $this->extras->getSpokenLanguages('tv', (int) $row['id']),
            ];
        }

        return [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $limit > 0 ? (int) ceil($total / $limit) : 0,
            'data' => $shows,
        ];
    }

    public function findByTmdbId(int $tmdbId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM tv_shows WHERE tmdb_id = :tmdb_id AND is_active = 1 LIMIT 1'
        );
        $stmt->execute(['tmdb_id' => $tmdbId]);
        $show = $stmt->fetch();
        return $show ?: null;
    }

    public function getShowDetail(int $tmdbId): ?array
    {
        $show = $this->findByTmdbId($tmdbId);
        if ($show === null) {
            return null;
        }

        $showId = (int) $show['id'];

        return [
            'tmdb_id' => (int) $show['tmdb_id'],
            'name' => $show['name'],
            'original_name' => $show['original_name'],
            'overview' => $show['overview'],
            'poster_url' => tmdb_image($show['poster_path'], 'w500'),
            'backdrop_url' => tmdb_image($show['backdrop_path'], 'original'),
            'vote_average' => $show['vote_average'] !== null ? (float) $show['vote_average'] : null,
            'vote_count' => $show['vote_count'] !== null ? (int) $show['vote_count'] : null,
            'first_air_date' => $show['first_air_date'],
            'last_air_date' => $show['last_air_date'],
            'number_of_seasons' => $show['number_of_seasons'] !== null ? (int) $show['number_of_seasons'] : null,
            'number_of_episodes' => $show['number_of_episodes'] !== null ? (int) $show['number_of_episodes'] : null,
            'status' => $show['status'],
            'show_type' => $show['show_type'],
            'in_production' => (bool) $show['in_production'],
            'original_language' => $show['original_language'],
            'spoken_languages' => $this->extras->getSpokenLanguages('tv', $showId),
            'popularity' => $show['popularity'] !== null ? (float) $show['popularity'] : null,
            'homepage' => $show['homepage'],
            'genres' => $this->getGenres($showId),
            'cast' => $this->getCast($showId),
            'providers' => $this->getProviders($showId),
            'similar' => $this->getSimilar($showId),
            'seasons' => $this->getSeasons($showId),
            'videos' => $this->extras->getVideos('tv', $showId),
            'images' => $this->extras->getImages('tv', $showId),
            'keywords' => $this->extras->getKeywords('tv', $showId),
            'recommendations' => $this->extras->getRecommendations('tv', $showId),
        ];
    }

    public function getCastByTmdbId(int $tmdbId, int $limit = 20): ?array
    {
        $show = $this->findByTmdbId($tmdbId);
        if ($show === null) {
            return null;
        }
        return ['tmdb_id' => $tmdbId, 'cast' => $this->getCast((int) $show['id'], $limit)];
    }

    public function getProvidersByTmdbId(int $tmdbId): ?array
    {
        $show = $this->findByTmdbId($tmdbId);
        if ($show === null) {
            return null;
        }
        return ['tmdb_id' => $tmdbId, 'providers' => $this->getProviders((int) $show['id'])];
    }

    public function getSimilarByTmdbId(int $tmdbId, int $limit = 20): ?array
    {
        $show = $this->findByTmdbId($tmdbId);
        if ($show === null) {
            return null;
        }
        return ['tmdb_id' => $tmdbId, 'similar' => $this->getSimilar((int) $show['id'], $limit)];
    }

    public function getSeasonsByTmdbId(int $tmdbId): ?array
    {
        $show = $this->findByTmdbId($tmdbId);
        if ($show === null) {
            return null;
        }
        return ['tmdb_id' => $tmdbId, 'seasons' => $this->getSeasons((int) $show['id'])];
    }

    public function getVideosByTmdbId(int $tmdbId): ?array
    {
        $show = $this->findByTmdbId($tmdbId);
        if ($show === null) {
            return null;
        }
        return ['tmdb_id' => $tmdbId, 'videos' => $this->extras->getVideos('tv', (int) $show['id'])];
    }

    public function getImagesByTmdbId(int $tmdbId): ?array
    {
        $show = $this->findByTmdbId($tmdbId);
        if ($show === null) {
            return null;
        }
        return ['tmdb_id' => $tmdbId, 'images' => $this->extras->getImages('tv', (int) $show['id'])];
    }

    public function getKeywordsByTmdbId(int $tmdbId): ?array
    {
        $show = $this->findByTmdbId($tmdbId);
        if ($show === null) {
            return null;
        }
        return ['tmdb_id' => $tmdbId, 'keywords' => $this->extras->getKeywords('tv', (int) $show['id'])];
    }

    public function getRecommendationsByTmdbId(int $tmdbId, int $limit = 20): ?array
    {
        $show = $this->findByTmdbId($tmdbId);
        if ($show === null) {
            return null;
        }
        return [
            'tmdb_id' => $tmdbId,
            'recommendations' => $this->extras->getRecommendations('tv', (int) $show['id'], $limit),
        ];
    }

    private function getGenreNames(int $showId): array
    {
        $stmt = $this->db->prepare(
            "SELECT g.name
             FROM media_genres mg
             INNER JOIN genres g ON g.id = mg.genre_id
             WHERE mg.media_type = 'tv' AND mg.media_id = :show_id
             ORDER BY g.name"
        );
        $stmt->execute(['show_id' => $showId]);
        return array_column($stmt->fetchAll(), 'name');
    }

    private function getGenres(int $showId): array
    {
        $stmt = $this->db->prepare(
            "SELECT g.tmdb_id, g.name
             FROM media_genres mg
             INNER JOIN genres g ON g.id = mg.genre_id
             WHERE mg.media_type = 'tv' AND mg.media_id = :show_id
             ORDER BY g.name"
        );
        $stmt->execute(['show_id' => $showId]);
        $rows = $stmt->fetchAll();
        return array_map(static fn(array $row): array => [
            'tmdb_id' => (int) $row['tmdb_id'],
            'name' => $row['name'],
        ], $rows);
    }

    private function getCast(int $showId, int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            "SELECT p.tmdb_id, p.name, p.profile_path, c.character, c.order_index
             FROM credits c
             INNER JOIN people p ON p.id = c.person_id
             WHERE c.media_type = 'tv'
               AND c.media_id = :show_id
               AND c.credit_type = 'cast'
             ORDER BY c.order_index ASC
             LIMIT :limit"
        );
        $stmt->bindValue(':show_id', $showId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return array_map(static fn(array $row): array => [
            'tmdb_id' => (int) $row['tmdb_id'],
            'name' => $row['name'],
            'character' => $row['character'],
            'profile_url' => tmdb_image($row['profile_path'], 'w185'),
        ], $rows);
    }

    private function getProviders(int $showId): array
    {
        $filter = ProvidersConfig::sqlFilter('mwp', 'wp');
        $stmt = $this->db->prepare(
            "SELECT wp.tmdb_id, wp.provider_name, wp.logo_path,
                    mwp.country_code, mwp.provider_type
             FROM media_watch_providers mwp
             INNER JOIN watch_providers wp ON wp.id = mwp.provider_id
             WHERE mwp.media_type = 'tv' AND mwp.media_id = :show_id{$filter}
             ORDER BY mwp.country_code, wp.provider_name"
        );
        $stmt->execute(['show_id' => $showId]);
        $rows = ProvidersConfig::filterRows($stmt->fetchAll());

        return array_map(static fn(array $row): array => [
            'tmdb_id' => (int) $row['tmdb_id'],
            'name' => $row['provider_name'],
            'country_code' => $row['country_code'],
            'type' => $row['provider_type'],
            'logo_url' => tmdb_image($row['logo_path'], 'w45'),
        ], $rows);
    }

    private function getSimilar(int $showId, int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            "SELECT t.tmdb_id, t.name, t.poster_path, t.vote_average, sm.similarity_score
             FROM similar_media sm
             INNER JOIN tv_shows t ON t.tmdb_id = sm.similar_media_id
             WHERE sm.media_type = 'tv' AND sm.media_id = :show_id
             ORDER BY sm.similarity_score DESC, t.popularity DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':show_id', $showId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return array_map(static fn(array $row): array => [
            'tmdb_id' => (int) $row['tmdb_id'],
            'name' => $row['name'],
            'poster_url' => tmdb_image($row['poster_path'], 'w500'),
            'vote_average' => $row['vote_average'] !== null ? (float) $row['vote_average'] : null,
            'similarity_score' => $row['similarity_score'] !== null ? (float) $row['similarity_score'] : null,
        ], $rows);
    }

    private function getSeasons(int $showId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, tmdb_id, season_number, name, overview, air_date,
                    episode_count, poster_path, vote_average
             FROM tv_seasons
             WHERE tv_show_id = :show_id
             ORDER BY season_number ASC"
        );
        $stmt->execute(['show_id' => $showId]);
        $seasons = $stmt->fetchAll();

        $result = [];
        foreach ($seasons as $season) {
            $result[] = [
                'tmdb_id' => (int) $season['tmdb_id'],
                'season_number' => (int) $season['season_number'],
                'name' => $season['name'],
                'overview' => $season['overview'],
                'air_date' => $season['air_date'],
                'episode_count' => $season['episode_count'] !== null ? (int) $season['episode_count'] : null,
                'poster_url' => tmdb_image($season['poster_path'], 'w500'),
                'vote_average' => $season['vote_average'] !== null ? (float) $season['vote_average'] : null,
                'episodes' => $this->getEpisodes((int) $season['id']),
            ];
        }
        return $result;
    }

    private function getEpisodes(int $seasonId): array
    {
        $stmt = $this->db->prepare(
            "SELECT tmdb_id, episode_number, name, overview, air_date,
                    runtime, still_path, vote_average, vote_count
             FROM tv_episodes
             WHERE season_id = :season_id
             ORDER BY episode_number ASC"
        );
        $stmt->execute(['season_id' => $seasonId]);
        $rows = $stmt->fetchAll();

        return array_map(static fn(array $row): array => [
            'tmdb_id' => (int) $row['tmdb_id'],
            'episode_number' => (int) $row['episode_number'],
            'name' => $row['name'],
            'overview' => $row['overview'],
            'air_date' => $row['air_date'],
            'runtime' => $row['runtime'] !== null ? (int) $row['runtime'] : null,
            'still_url' => tmdb_image($row['still_path'], 'w300'),
            'vote_average' => $row['vote_average'] !== null ? (float) $row['vote_average'] : null,
            'vote_count' => $row['vote_count'] !== null ? (int) $row['vote_count'] : null,
        ], $rows);
    }
}
