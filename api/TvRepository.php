<?php

declare(strict_types=1);

final class TvRepository
{
    private MediaExtrasRepository $extras;
    private UserStateRepository $userState;

    public function __construct(private PDO $db)
    {
        $this->extras = new MediaExtrasRepository($db);
        $this->userState = new UserStateRepository($db);
    }

    public function listShows(array $filters): array
    {
        $deviceId = trim((string) ($filters['device_id'] ?? ''));
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
        $genreIds = $filters['genre_tmdb_ids'];
        if ($genreIds !== null) {
            $genreIn = bind_in_list('genre', $genreIds, $params);
            $where[] = "t.id IN (
                SELECT mg.media_id FROM media_genres mg
                INNER JOIN genres g ON g.id = mg.genre_id
                WHERE mg.media_type = 'tv' AND g.tmdb_id IN ({$genreIn})
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
                'tv',
                't',
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
            $where[] = 't.first_air_date IS NOT NULL AND t.first_air_date <= CURDATE()';
        }
        if ($filters['vote_average_gte'] !== null) {
            $where[] = 't.vote_average >= :vote_average_gte';
            $params['vote_average_gte'] = $filters['vote_average_gte'];
        }
        if ($filters['vote_count_gte'] !== null) {
            $where[] = 't.vote_count >= :vote_count_gte';
            $params['vote_count_gte'] = $filters['vote_count_gte'];
        }
        if ($filters['first_air_date_gte'] !== null) {
            $where[] = 't.first_air_date IS NOT NULL AND t.first_air_date >= :first_air_date_gte';
            $params['first_air_date_gte'] = $filters['first_air_date_gte'];
        }
        if ($filters['first_air_date_lte'] !== null) {
            $where[] = 't.first_air_date IS NOT NULL AND t.first_air_date <= :first_air_date_lte';
            $params['first_air_date_lte'] = $filters['first_air_date_lte'];
        }
        $originalLanguages = $filters['original_languages'];
        if ($originalLanguages !== null) {
            $where[] = 't.original_language IN (' . bind_in_list('orig_lang', $originalLanguages, $params) . ')';
        }
        if ($filters['spoken_language'] !== null) {
            $params['spoken_language'] = strtolower($filters['spoken_language']);
            $where[] = "t.id IN (
                SELECT msl_filter.media_id FROM media_spoken_languages msl_filter
                INNER JOIN spoken_languages sl_filter ON sl_filter.id = msl_filter.language_id
                WHERE msl_filter.media_type = 'tv' AND sl_filter.iso_code = :spoken_language
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
                ON ums_filter.media_type = 'tv'
                AND ums_filter.tmdb_id = t.tmdb_id
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
            $countSql = "SELECT COUNT(*) FROM tv_shows t{$joins} WHERE {$whereSql}";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();
        }

        $orderColumn = match ($sort) {
            'vote_average' => 't.vote_average',
            'first_air_date' => 't.first_air_date',
            'name' => 't.name',
            default => 't.popularity',
        };

        $sql = "
            SELECT
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

        $mediaIds = array_map(static fn(array $row): int => (int) $row['id'], $rows);
        $genreMap = $this->getGenreNamesMap($mediaIds);
        $spokenMap = $this->extras->getSpokenLanguagesMap('tv', $mediaIds);
        $flagsMap = $deviceId !== ''
            ? $this->userState->getFlagsMap($deviceId, 'tv', array_map(static fn(array $row): int => (int) $row['tmdb_id'], $rows))
            : [];

        $shows = [];
        foreach ($rows as $row) {
            $mediaId = (int) $row['id'];
            $flags = $flagsMap[(int) $row['tmdb_id']] ?? ['is_watched' => false, 'is_saved_for_later' => false];
            $shows[] = [
                'tmdb_id' => (int) $row['tmdb_id'],
                'media_type' => 'tv',
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
                'genres' => $genreMap[$mediaId] ?? [],
                'spoken_languages' => $spokenMap[$mediaId] ?? [],
                'is_watched' => $flags['is_watched'],
                'is_saved_for_later' => $flags['is_saved_for_later'],
            ];
        }

        return [
            'page' => $page,
            'limit' => $limit,
            'total' => $includeTotal ? $total : count($shows),
            'total_pages' => $includeTotal
                ? ($limit > 0 ? (int) ceil($total / $limit) : 0)
                : 1,
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

    public function getShowDetail(int $tmdbId, ?string $deviceId = null): ?array
    {
        $show = $this->findByTmdbId($tmdbId);
        if ($show === null) {
            return null;
        }

        $showId = (int) $show['id'];
        $flags = $deviceId !== null && trim($deviceId) !== ''
            ? $this->userState->getFlagsForMedia(trim($deviceId), 'tv', $tmdbId)
            : ['is_watched' => false, 'is_saved_for_later' => false];

        return [
            'tmdb_id' => (int) $show['tmdb_id'],
            'is_watched' => $flags['is_watched'],
            'is_saved_for_later' => $flags['is_saved_for_later'],
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
            // Season metadata only — full episode lists via POST /tv/{id}/seasons.
            'seasons' => $this->getSeasons($showId, false),
            'videos' => $this->extras->getVideos('tv', $showId),
            'images' => $this->extras->getImages('tv', $showId, 8),
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

    public function getEpisodeDetail(int $showTmdbId, int $seasonNumber, int $episodeNumber): ?array
    {
        $show = $this->findByTmdbId($showTmdbId);
        if ($show === null) {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT e.id, e.tmdb_id, e.episode_number, e.name, e.overview, e.air_date,
                    e.runtime, e.still_path, e.vote_average, e.vote_count,
                    s.season_number
             FROM tv_episodes e
             INNER JOIN tv_seasons s ON s.id = e.season_id
             WHERE e.tv_show_id = :show_id
               AND s.season_number = :season_number
               AND e.episode_number = :episode_number
             LIMIT 1"
        );
        $stmt->execute([
            'show_id' => (int) $show['id'],
            'season_number' => $seasonNumber,
            'episode_number' => $episodeNumber,
        ]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $episodeId = (int) $row['id'];

        return [
            'tv_tmdb_id' => $showTmdbId,
            'season_number' => (int) $row['season_number'],
            'episode_number' => (int) $row['episode_number'],
            'tmdb_id' => (int) $row['tmdb_id'],
            'name' => $row['name'],
            'overview' => $row['overview'],
            'air_date' => $row['air_date'],
            'runtime' => $row['runtime'] !== null ? (int) $row['runtime'] : null,
            'still_url' => tmdb_image($row['still_path'], 'w300'),
            'vote_average' => $row['vote_average'] !== null ? (float) $row['vote_average'] : null,
            'vote_count' => $row['vote_count'] !== null ? (int) $row['vote_count'] : null,
            'cast' => $this->getEpisodeCast($episodeId),
            'crew' => $this->getEpisodeCrew($episodeId),
        ];
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

    /** @param list<int> $showIds @return array<int, list<string>> */
    private function getGenreNamesMap(array $showIds): array
    {
        $showIds = array_values(array_unique(array_map('intval', $showIds)));
        if ($showIds === []) {
            return [];
        }

        $params = [];
        $inList = bind_in_list('show', $showIds, $params);
        $stmt = $this->db->prepare(
            "SELECT mg.media_id, g.name
             FROM media_genres mg
             INNER JOIN genres g ON g.id = mg.genre_id
             WHERE mg.media_type = 'tv' AND mg.media_id IN ({$inList})
             ORDER BY g.name"
        );
        $stmt->execute($params);

        $map = [];
        foreach ($showIds as $id) {
            $map[$id] = [];
        }
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['media_id']][] = $row['name'];
        }
        return $map;
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
            "SELECT p.tmdb_id, p.name, p.profile_path, c.character, c.episode_count, c.order_index
             FROM credits c
             INNER JOIN people p ON p.id = c.person_id
             WHERE c.media_type = 'tv'
               AND c.media_id = :show_id
               AND c.credit_type = 'cast'
             ORDER BY c.order_index ASC, c.episode_count DESC
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
            'episode_count' => $row['episode_count'] !== null ? (int) $row['episode_count'] : null,
            'profile_url' => tmdb_image($row['profile_path'], 'w185'),
        ], $rows);
    }

    private function getEpisodeCast(int $episodeId, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            "SELECT p.tmdb_id, p.name, p.profile_path, c.character, c.order_index
             FROM credits c
             INNER JOIN people p ON p.id = c.person_id
             WHERE c.media_type = 'episode'
               AND c.media_id = :episode_id
               AND c.credit_type = 'cast'
             ORDER BY c.order_index ASC
             LIMIT :limit"
        );
        $stmt->bindValue(':episode_id', $episodeId, PDO::PARAM_INT);
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

    private function getEpisodeCrew(int $episodeId, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            "SELECT p.tmdb_id, p.name, p.profile_path, c.job, c.department
             FROM credits c
             INNER JOIN people p ON p.id = c.person_id
             WHERE c.media_type = 'episode'
               AND c.media_id = :episode_id
               AND c.credit_type = 'crew'
             ORDER BY c.department ASC, c.job ASC
             LIMIT :limit"
        );
        $stmt->bindValue(':episode_id', $episodeId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return array_map(static fn(array $row): array => [
            'tmdb_id' => (int) $row['tmdb_id'],
            'name' => $row['name'],
            'job' => $row['job'],
            'department' => $row['department'],
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
            'logo_url' => provider_logo_url((int) $row['tmdb_id'], $row['logo_path'] ?? null),
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

    private function getSeasons(int $showId, bool $includeEpisodes = true): array
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
                'episodes' => $includeEpisodes
                    ? $this->getEpisodes((int) $season['id'])
                    : [],
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
