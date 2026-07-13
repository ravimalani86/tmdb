<?php

declare(strict_types=1);

final class MovieRepository
{
    private MediaExtrasRepository $extras;

    public function __construct(private PDO $db)
    {
        $this->extras = new MediaExtrasRepository($db);
    }

    public function listMovies(array $filters): array
    {
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
            $joins .= ' INNER JOIN media_genres mg ON mg.media_id = m.id AND mg.media_type = \'movie\'';
            $joins .= ' INNER JOIN genres g ON g.id = mg.genre_id';
        } elseif ($filters['provider_tmdb_id'] !== null || $filters['country'] !== null) {
            $joins .= ' LEFT JOIN media_genres mg ON mg.media_id = m.id AND mg.media_type = \'movie\'';
            $joins .= ' LEFT JOIN genres g ON g.id = mg.genre_id';
        } else {
            $joins .= ' LEFT JOIN media_genres mg ON mg.media_id = m.id AND mg.media_type = \'movie\'';
            $joins .= ' LEFT JOIN genres g ON g.id = mg.genre_id';
        }
        if ($filters['provider_tmdb_id'] !== null || $filters['country'] !== null) {
            $joins .= ' INNER JOIN media_watch_providers mwp ON mwp.media_id = m.id AND mwp.media_type = \'movie\'';
            $joins .= ' INNER JOIN watch_providers wp ON wp.id = mwp.provider_id';
            $whereSql = implode(' AND ', $where) . $providerSqlFilter;
        } else {
            $joins .= ' LEFT JOIN media_watch_providers mwp ON mwp.media_id = m.id AND mwp.media_type = \'movie\'';
            $joins .= ' LEFT JOIN watch_providers wp ON wp.id = mwp.provider_id';
            $whereSql = implode(' AND ', $where);
        }
        if ($filters['spoken_language'] !== null) {
            $joins .= ' INNER JOIN media_spoken_languages msl_filter ON msl_filter.media_id = m.id AND msl_filter.media_type = \'movie\'';
            $joins .= ' INNER JOIN spoken_languages sl_filter ON sl_filter.id = msl_filter.language_id';
        }

        $countSql = "SELECT COUNT(DISTINCT m.id) FROM movies m {$joins} WHERE {$whereSql}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $orderColumn = match ($sort) {
            'vote_average' => 'm.vote_average',
            'release_date' => 'm.release_date',
            'title' => 'm.title',
            default => 'm.popularity',
        };

        $sql = "
            SELECT DISTINCT
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

        $movies = [];
        foreach ($rows as $row) {
            $movies[] = [
                'tmdb_id' => (int) $row['tmdb_id'],
                'title' => $row['title'],
                'poster_url' => tmdb_image($row['poster_path'], 'w500'),
                'backdrop_url' => tmdb_image($row['backdrop_path'], 'original'),
                'vote_average' => $row['vote_average'] !== null ? (float) $row['vote_average'] : null,
                'vote_count' => $row['vote_count'] !== null ? (int) $row['vote_count'] : null,
                'release_date' => $row['release_date'],
                'popularity' => $row['popularity'] !== null ? (float) $row['popularity'] : null,
                'original_language' => $row['original_language'],
                'genres' => $this->getGenreNames((int) $row['id']),
                'spoken_languages' => $this->extras->getSpokenLanguages('movie', (int) $row['id']),
            ];
        }

        return [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $limit > 0 ? (int) ceil($total / $limit) : 0,
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

    public function getMovieDetail(int $tmdbId): ?array
    {
        $movie = $this->findByTmdbId($tmdbId);
        if ($movie === null) {
            return null;
        }

        $movieId = (int) $movie['id'];

        return [
            'tmdb_id' => (int) $movie['tmdb_id'],
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
            'images' => $this->extras->getImages('movie', $movieId),
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

    private function getGenreNames(int $movieId): array
    {
        $stmt = $this->db->prepare(
            "SELECT g.name
             FROM media_genres mg
             INNER JOIN genres g ON g.id = mg.genre_id
             WHERE mg.media_type = 'movie' AND mg.media_id = :movie_id
             ORDER BY g.name"
        );
        $stmt->execute(['movie_id' => $movieId]);
        return array_column($stmt->fetchAll(), 'name');
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
            'logo_url' => tmdb_image($row['logo_path'], 'w45'),
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
