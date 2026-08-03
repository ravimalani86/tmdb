<?php

declare(strict_types=1);

final class MovieRepository
{
    private MediaExtrasRepository $extras;
    private UserStateRepository $userState;

    public function __construct(private PDO $db)
    {
        $this->extras = new MediaExtrasRepository($db);
        $this->userState = new UserStateRepository($db);
    }

    public function listMovies(array $filters): array
    {
        $deviceId = trim((string) ($filters['device_id'] ?? ''));
        $page = $filters['page'];
        $limit = $filters['limit'];
        $offset = ($page - 1) * $limit;
        $sort = $filters['sort'];
        $order = $filters['order'];

        $where = ['m.is_active = 1'];
        $params = [];

        if ($filters['search'] !== null) {
            $where[] = 'm.title LIKE :search';
            $params['search'] = '%' . $filters['search'] . '%';
        }
        $genreIds = $filters['genre_tmdb_ids'];
        if ($genreIds !== null) {
            $genreIn = bind_in_list('genre', $genreIds, $params);
            $where[] = "m.id IN (
                SELECT mg.media_id FROM media_genres mg
                INNER JOIN genres g ON g.id = mg.genre_id
                WHERE mg.media_type = 'movie' AND g.tmdb_id IN ({$genreIn})
            )";
        }
        $joins = '';
        $providerIds = $filters['provider_tmdb_ids'];
        $countries = $filters['countries'];
        if ($providerIds !== null || $countries !== null) {
            if (
                $providerIds !== null
                && !ProvidersConfig::anyProviderAllowed($providerIds, $countries)
            ) {
                return [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => 0,
                    'total_pages' => 0,
                    'data' => [],
                ];
            }
            $providerFilter = build_provider_media_filter(
                $this->db,
                'movie',
                'm',
                $providerIds,
                $countries,
                $params
            );
            if ($providerFilter === null) {
                return [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => 0,
                    'total_pages' => 0,
                    'data' => [],
                ];
            }
            $joins .= $providerFilter['join_sql'];
            if ($providerFilter['where_sql'] !== '') {
                $where[] = $providerFilter['where_sql'];
            }
        }
        if ($filters['released_only']) {
            $where[] = 'm.release_date IS NOT NULL AND m.release_date <= CURDATE()';
        }
        if ($filters['vote_average_gte'] !== null) {
            $where[] = 'm.vote_average >= :vote_average_gte';
            $params['vote_average_gte'] = $filters['vote_average_gte'];
        }
        if ($filters['vote_count_gte'] !== null) {
            $where[] = 'm.vote_count >= :vote_count_gte';
            $params['vote_count_gte'] = $filters['vote_count_gte'];
        }
        if ($filters['release_date_gte'] !== null) {
            $where[] = 'm.release_date IS NOT NULL AND m.release_date >= :release_date_gte';
            $params['release_date_gte'] = $filters['release_date_gte'];
        }
        if ($filters['release_date_lte'] !== null) {
            $where[] = 'm.release_date IS NOT NULL AND m.release_date <= :release_date_lte';
            $params['release_date_lte'] = $filters['release_date_lte'];
        }
        $originalLanguages = $filters['original_languages'];
        if ($originalLanguages !== null) {
            $where[] = 'm.original_language IN (' . bind_in_list('orig_lang', $originalLanguages, $params) . ')';
        }
        if ($filters['spoken_language'] !== null) {
            $params['spoken_language'] = strtolower($filters['spoken_language']);
            $where[] = "m.id IN (
                SELECT msl_filter.media_id FROM media_spoken_languages msl_filter
                INNER JOIN spoken_languages sl_filter ON sl_filter.id = msl_filter.language_id
                WHERE msl_filter.media_type = 'movie' AND sl_filter.iso_code = :spoken_language
            )";
        }

        $savedOnly = !empty($filters['saved_only']);
        $watchedOnly = !empty($filters['watched_only']);
        if ($savedOnly || $watchedOnly) {
            if ($deviceId === '') {
                return [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => 0,
                    'total_pages' => 0,
                    'data' => [],
                ];
            }
            $joins .= " INNER JOIN user_media_state ums_filter
                ON ums_filter.media_type = 'movie'
                AND ums_filter.tmdb_id = m.tmdb_id
                AND ums_filter.device_id = :ums_filter_device";
            $params['ums_filter_device'] = $deviceId;
            if ($savedOnly) {
                $where[] = 'ums_filter.is_saved_for_later = 1';
            }
            if ($watchedOnly) {
                $where[] = 'ums_filter.is_watched = 1';
            }
        }

        $whereSql = implode(' AND ', $where);

        $includeTotal = array_key_exists('include_total', $filters)
            ? (bool) $filters['include_total']
            : true;
        $total = 0;
        if ($includeTotal) {
            $countSql = "SELECT COUNT(*) FROM movies m{$joins} WHERE {$whereSql}";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();
        }

        $orderColumn = match ($sort) {
            'vote_average' => 'm.vote_average',
            'release_date' => 'm.release_date',
            'title' => 'm.title',
            default => 'm.popularity',
        };

        $sql = "
            SELECT
                m.id,
                m.tmdb_id,
                m.title,
                m.poster_path,
                m.backdrop_path,
                m.vote_average,
                m.vote_count,
                m.release_date,
                m.popularity,
                m.original_language
            FROM movies m
            {$joins}
            WHERE {$whereSql}
            ORDER BY {$orderColumn} {$order}, m.tmdb_id DESC
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

        $mediaIds = array_map(static fn(array $row): int => (int) $row['id'], $rows);
        $genreMap = $this->getGenreNamesMap($mediaIds);
        $spokenMap = $this->extras->getSpokenLanguagesMap('movie', $mediaIds);
        $flagsMap = $deviceId !== ''
            ? $this->userState->getFlagsMap($deviceId, 'movie', array_map(static fn(array $row): int => (int) $row['tmdb_id'], $rows))
            : [];

        $movies = [];
        foreach ($rows as $row) {
            $mediaId = (int) $row['id'];
            $flags = $flagsMap[(int) $row['tmdb_id']] ?? ['is_watched' => false, 'is_saved_for_later' => false];
            $movies[] = [
                'tmdb_id' => (int) $row['tmdb_id'],
                'media_type' => 'movie',
                'title' => $row['title'],
                'poster_url' => tmdb_image($row['poster_path'], 'w500'),
                'backdrop_url' => tmdb_image($row['backdrop_path'], 'original'),
                'vote_average' => $row['vote_average'] !== null ? (float) $row['vote_average'] : null,
                'vote_count' => $row['vote_count'] !== null ? (int) $row['vote_count'] : null,
                'release_date' => $row['release_date'],
                'popularity' => $row['popularity'] !== null ? (float) $row['popularity'] : null,
                'original_language' => $row['original_language'],
                'genres' => $genreMap[$mediaId] ?? [],
                'spoken_languages' => $spokenMap[$mediaId] ?? [],
                'is_watched' => $flags['is_watched'],
                'is_saved_for_later' => $flags['is_saved_for_later'],
            ];
        }

        return [
            'page' => $page,
            'limit' => $limit,
            'total' => $includeTotal ? $total : count($movies),
            'total_pages' => $includeTotal
                ? ($limit > 0 ? (int) ceil($total / $limit) : 0)
                : 1,
            'data' => $movies,
        ];
    }

    public function findByTmdbId(int $tmdbId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM movies WHERE tmdb_id = :tmdb_id AND is_active = 1 LIMIT 1'
        );
        $stmt->execute(['tmdb_id' => $tmdbId]);
        $movie = $stmt->fetch();
        return $movie ?: null;
    }

    public function getMovieDetail(int $tmdbId, ?string $deviceId = null): ?array
    {
        $movie = $this->findByTmdbId($tmdbId);
        if ($movie === null) {
            return null;
        }

        $movieId = (int) $movie['id'];
        $flags = $deviceId !== null && trim($deviceId) !== ''
            ? $this->userState->getFlagsForMedia(trim($deviceId), 'movie', $tmdbId)
            : ['is_watched' => false, 'is_saved_for_later' => false];

        return [
            'tmdb_id' => (int) $movie['tmdb_id'],
            'is_watched' => $flags['is_watched'],
            'is_saved_for_later' => $flags['is_saved_for_later'],
            'title' => $movie['title'],
            'original_title' => $movie['original_title'],
            'overview' => $movie['overview'],
            'tagline' => $movie['tagline'],
            'poster_url' => tmdb_image($movie['poster_path'], 'w500'),
            'backdrop_url' => tmdb_image($movie['backdrop_path'], 'original'),
            'vote_average' => $movie['vote_average'] !== null ? (float) $movie['vote_average'] : null,
            'vote_count' => $movie['vote_count'] !== null ? (int) $movie['vote_count'] : null,
            'release_date' => $movie['release_date'],
            'runtime' => $movie['runtime'] !== null ? (int) $movie['runtime'] : null,
            'original_language' => $movie['original_language'],
            'spoken_languages' => $this->extras->getSpokenLanguages('movie', $movieId),
            'popularity' => $movie['popularity'] !== null ? (float) $movie['popularity'] : null,
            'imdb_id' => $movie['imdb_id'],
            'homepage' => $movie['homepage'],
            'genres' => $this->getGenres($movieId),
            'cast' => $this->getCast($movieId),
            'providers' => $this->getProviders($movieId),
            'similar' => $this->getSimilar($movieId),
            'videos' => $this->extras->getVideos('movie', $movieId),
            'images' => $this->extras->getImages('movie', $movieId, 8),
            'keywords' => $this->extras->getKeywords('movie', $movieId),
            'recommendations' => $this->extras->getRecommendations('movie', $movieId),
        ];
    }

    public function getCastByTmdbId(int $tmdbId, int $limit = 20): ?array
    {
        $movie = $this->findByTmdbId($tmdbId);
        if ($movie === null) {
            return null;
        }
        return ['tmdb_id' => $tmdbId, 'cast' => $this->getCast((int) $movie['id'], $limit)];
    }

    public function getProvidersByTmdbId(int $tmdbId): ?array
    {
        $movie = $this->findByTmdbId($tmdbId);
        if ($movie === null) {
            return null;
        }
        return ['tmdb_id' => $tmdbId, 'providers' => $this->getProviders((int) $movie['id'])];
    }

    public function getSimilarByTmdbId(int $tmdbId, int $limit = 20): ?array
    {
        $movie = $this->findByTmdbId($tmdbId);
        if ($movie === null) {
            return null;
        }
        return ['tmdb_id' => $tmdbId, 'similar' => $this->getSimilar((int) $movie['id'], $limit)];
    }

    public function getVideosByTmdbId(int $tmdbId): ?array
    {
        $movie = $this->findByTmdbId($tmdbId);
        if ($movie === null) {
            return null;
        }
        return ['tmdb_id' => $tmdbId, 'videos' => $this->extras->getVideos('movie', (int) $movie['id'])];
    }

    public function getImagesByTmdbId(int $tmdbId): ?array
    {
        $movie = $this->findByTmdbId($tmdbId);
        if ($movie === null) {
            return null;
        }
        return ['tmdb_id' => $tmdbId, 'images' => $this->extras->getImages('movie', (int) $movie['id'])];
    }

    public function getKeywordsByTmdbId(int $tmdbId): ?array
    {
        $movie = $this->findByTmdbId($tmdbId);
        if ($movie === null) {
            return null;
        }
        return ['tmdb_id' => $tmdbId, 'keywords' => $this->extras->getKeywords('movie', (int) $movie['id'])];
    }

    public function getRecommendationsByTmdbId(int $tmdbId, int $limit = 20): ?array
    {
        $movie = $this->findByTmdbId($tmdbId);
        if ($movie === null) {
            return null;
        }
        return [
            'tmdb_id' => $tmdbId,
            'recommendations' => $this->extras->getRecommendations('movie', (int) $movie['id'], $limit),
        ];
    }

    /** @param list<int> $movieIds @return array<int, list<string>> */
    private function getGenreNamesMap(array $movieIds): array
    {
        $movieIds = array_values(array_unique(array_map('intval', $movieIds)));
        if ($movieIds === []) {
            return [];
        }

        $params = [];
        $inList = bind_in_list('movie', $movieIds, $params);
        $stmt = $this->db->prepare(
            "SELECT mg.media_id, g.name
             FROM media_genres mg
             INNER JOIN genres g ON g.id = mg.genre_id
             WHERE mg.media_type = 'movie' AND mg.media_id IN ({$inList})
             ORDER BY g.name"
        );
        $stmt->execute($params);

        $map = [];
        foreach ($movieIds as $id) {
            $map[$id] = [];
        }
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['media_id']][] = $row['name'];
        }
        return $map;
    }

    private function getGenres(int $movieId): array
    {
        $stmt = $this->db->prepare(
            "SELECT g.tmdb_id, g.name
             FROM media_genres mg
             INNER JOIN genres g ON g.id = mg.genre_id
             WHERE mg.media_type = 'movie' AND mg.media_id = :movie_id
             ORDER BY g.name"
        );
        $stmt->execute(['movie_id' => $movieId]);
        $rows = $stmt->fetchAll();
        return array_map(static fn(array $row): array => [
            'tmdb_id' => (int) $row['tmdb_id'],
            'name' => $row['name'],
        ], $rows);
    }

    private function getCast(int $movieId, int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            "SELECT p.tmdb_id, p.name, p.profile_path, c.character, c.order_index
             FROM credits c
             INNER JOIN people p ON p.id = c.person_id
             WHERE c.media_type = 'movie'
               AND c.media_id = :movie_id
               AND c.credit_type = 'cast'
             ORDER BY c.order_index ASC
             LIMIT :limit"
        );
        $stmt->bindValue(':movie_id', $movieId, PDO::PARAM_INT);
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

    private function getProviders(int $movieId): array
    {
        $filter = ProvidersConfig::sqlFilter('mwp', 'wp');
        $stmt = $this->db->prepare(
            "SELECT wp.tmdb_id, wp.provider_name, wp.logo_path,
                    mwp.country_code, mwp.provider_type
             FROM media_watch_providers mwp
             INNER JOIN watch_providers wp ON wp.id = mwp.provider_id
             WHERE mwp.media_type = 'movie' AND mwp.media_id = :movie_id{$filter}
             ORDER BY mwp.country_code, wp.provider_name"
        );
        $stmt->execute(['movie_id' => $movieId]);
        $rows = ProvidersConfig::filterRows($stmt->fetchAll());

        return array_map(static fn(array $row): array => [
            'tmdb_id' => (int) $row['tmdb_id'],
            'name' => $row['provider_name'],
            'country_code' => $row['country_code'],
            'type' => $row['provider_type'],
            'logo_url' => provider_logo_url((int) $row['tmdb_id'], $row['logo_path'] ?? null),
        ], $rows);
    }

    private function getSimilar(int $movieId, int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.tmdb_id, m.title, m.poster_path, m.vote_average, sm.similarity_score
             FROM similar_media sm
             INNER JOIN movies m ON m.tmdb_id = sm.similar_media_id
             WHERE sm.media_type = 'movie' AND sm.media_id = :movie_id
             ORDER BY sm.similarity_score DESC, m.popularity DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':movie_id', $movieId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return array_map(static fn(array $row): array => [
            'tmdb_id' => (int) $row['tmdb_id'],
            'title' => $row['title'],
            'poster_url' => tmdb_image($row['poster_path'], 'w500'),
            'vote_average' => $row['vote_average'] !== null ? (float) $row['vote_average'] : null,
            'similarity_score' => $row['similarity_score'] !== null ? (float) $row['similarity_score'] : null,
        ], $rows);
    }
}
