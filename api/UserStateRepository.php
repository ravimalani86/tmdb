<?php

declare(strict_types=1);

final class UserStateRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function getFlagsForMedia(string $deviceId, string $mediaType, int $tmdbId): array
    {
        $stmt = $this->db->prepare(
            "SELECT is_watched, is_saved_for_later
             FROM user_media_state
             WHERE device_id = :device_id
               AND media_type = :media_type
               AND tmdb_id = :tmdb_id
             LIMIT 1"
        );
        $stmt->execute([
            'device_id' => $deviceId,
            'media_type' => $mediaType,
            'tmdb_id' => $tmdbId,
        ]);
        $row = $stmt->fetch();

        if ($row === false) {
            return [
                'is_watched' => false,
                'is_saved_for_later' => false,
            ];
        }

        return [
            'is_watched' => (bool) $row['is_watched'],
            'is_saved_for_later' => (bool) $row['is_saved_for_later'],
        ];
    }

    public function getFlagsMap(string $deviceId, string $mediaType, array $tmdbIds): array
    {
        if ($deviceId === '' || $tmdbIds === []) {
            return [];
        }

        $tmdbIds = array_values(array_unique(array_map('intval', $tmdbIds)));
        $placeholders = implode(', ', array_fill(0, count($tmdbIds), '?'));

        $stmt = $this->db->prepare(
            "SELECT tmdb_id, is_watched, is_saved_for_later
             FROM user_media_state
             WHERE device_id = ?
               AND media_type = ?
               AND tmdb_id IN ({$placeholders})"
        );

        $params = array_merge([$deviceId, $mediaType], $tmdbIds);
        $stmt->execute($params);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['tmdb_id']] = [
                'is_watched' => (bool) $row['is_watched'],
                'is_saved_for_later' => (bool) $row['is_saved_for_later'],
            ];
        }

        return $map;
    }

    public function setWatchState(string $deviceId, string $mediaType, int $tmdbId, bool $isWatched): array
    {
        return $this->upsertFlags($deviceId, $mediaType, $tmdbId, ['is_watched' => $isWatched]);
    }

    public function setSaveForLaterState(string $deviceId, string $mediaType, int $tmdbId, bool $isSavedForLater): array
    {
        return $this->upsertFlags($deviceId, $mediaType, $tmdbId, ['is_saved_for_later' => $isSavedForLater]);
    }

    /**
     * List saved / watched media for a device (My List screens).
     *
     * @param string|null $mediaType movie|tv|null (both)
     * @param string $filter saved|watched|all
     */
    public function listMedia(
        string $deviceId,
        ?string $mediaType,
        string $filter,
        int $page,
        int $limit
    ): array {
        $page = max(1, $page);
        $limit = max(1, min(50, $limit));
        $offset = ($page - 1) * $limit;

        $conditions = ['ums.device_id = :device_id'];
        $params = ['device_id' => $deviceId];

        if ($mediaType === 'movie' || $mediaType === 'tv') {
            $conditions[] = 'ums.media_type = :media_type';
            $params['media_type'] = $mediaType;
        }

        if ($filter === 'saved') {
            $conditions[] = 'ums.is_saved_for_later = 1';
        } elseif ($filter === 'watched') {
            $conditions[] = 'ums.is_watched = 1';
        } else {
            $conditions[] = '(ums.is_saved_for_later = 1 OR ums.is_watched = 1)';
        }

        $where = implode(' AND ', $conditions);

        $countStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM user_media_state ums WHERE {$where}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT
                    ums.media_type,
                    ums.tmdb_id,
                    ums.is_watched,
                    ums.is_saved_for_later,
                    ums.updated_at,
                    CASE
                        WHEN ums.media_type = 'movie' THEN m.title
                        ELSE t.name
                    END AS title,
                    CASE
                        WHEN ums.media_type = 'movie' THEN m.poster_path
                        ELSE t.poster_path
                    END AS poster_path,
                    CASE
                        WHEN ums.media_type = 'movie' THEN m.backdrop_path
                        ELSE t.backdrop_path
                    END AS backdrop_path,
                    CASE
                        WHEN ums.media_type = 'movie' THEN m.vote_average
                        ELSE t.vote_average
                    END AS vote_average,
                    CASE
                        WHEN ums.media_type = 'movie' THEN m.release_date
                        ELSE t.first_air_date
                    END AS date
                FROM user_media_state ums
                LEFT JOIN movies m
                    ON ums.media_type = 'movie' AND m.tmdb_id = ums.tmdb_id AND m.is_active = 1
                LEFT JOIN tv_shows t
                    ON ums.media_type = 'tv' AND t.tmdb_id = ums.tmdb_id AND t.is_active = 1
                WHERE {$where}
                  AND (
                    (ums.media_type = 'movie' AND m.id IS NOT NULL)
                    OR (ums.media_type = 'tv' AND t.id IS NOT NULL)
                  )
                ORDER BY ums.updated_at DESC
                LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                'media_type' => $row['media_type'],
                'tmdb_id' => (int) $row['tmdb_id'],
                'title' => $row['title'],
                'poster_url' => tmdb_image($row['poster_path'], 'w500'),
                'backdrop_url' => tmdb_image($row['backdrop_path'], 'original'),
                'vote_average' => $row['vote_average'] !== null ? (float) $row['vote_average'] : null,
                'date' => $row['date'],
                'is_watched' => (bool) $row['is_watched'],
                'is_saved_for_later' => (bool) $row['is_saved_for_later'],
                'updated_at' => $row['updated_at'],
            ];
        }

        return [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $limit > 0 ? (int) ceil($total / $limit) : 0,
            'filter' => $filter,
            'media_type' => $mediaType,
            'data' => $data,
        ];
    }

    private function upsertFlags(string $deviceId, string $mediaType, int $tmdbId, array $changes): array
    {
        $current = $this->getFlagsForMedia($deviceId, $mediaType, $tmdbId);

        $isWatched = array_key_exists('is_watched', $changes)
            ? (bool) $changes['is_watched']
            : $current['is_watched'];
        $isSavedForLater = array_key_exists('is_saved_for_later', $changes)
            ? (bool) $changes['is_saved_for_later']
            : $current['is_saved_for_later'];

        $stmt = $this->db->prepare(
            "INSERT INTO user_media_state (device_id, media_type, tmdb_id, is_watched, is_saved_for_later)
             VALUES (:device_id, :media_type, :tmdb_id, :is_watched, :is_saved_for_later)
             ON DUPLICATE KEY UPDATE
                is_watched = VALUES(is_watched),
                is_saved_for_later = VALUES(is_saved_for_later),
                updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([
            'device_id' => $deviceId,
            'media_type' => $mediaType,
            'tmdb_id' => $tmdbId,
            'is_watched' => $isWatched ? 1 : 0,
            'is_saved_for_later' => $isSavedForLater ? 1 : 0,
        ]);

        return [
            'device_id' => $deviceId,
            'media_type' => $mediaType,
            'tmdb_id' => $tmdbId,
            'is_watched' => $isWatched,
            'is_saved_for_later' => $isSavedForLater,
        ];
    }
}
