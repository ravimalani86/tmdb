<?php

declare(strict_types=1);

/**
 * Read-only Trailers feed. No writes and no schema changes.
 * Each call returns a fresh random batch. Repeats across calls are allowed.
 */
final class ReelRepository
{
    public function __construct(private PDO $db, private UserStateRepository $userState)
    {
    }

    public function listReels(int $page, int $limit, ?string $deviceId): array
    {
        $page = max(1, $page);
        $limit = max(1, min(20, $limit));
        $day = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');

        $this->db->exec('SET SESSION group_concat_max_len = 8192');

        $sql = "
            SELECT
                src.media_type,
                src.internal_id,
                src.tmdb_id,
                src.title,
                src.overview,
                src.poster_path,
                src.backdrop_path,
                src.vote_average,
                src.vote_count,
                src.runtime,
                src.number_of_seasons,
                src.original_language,
                src.release_day,
                src.youtube_key
            FROM (
                SELECT
                    'movie' AS media_type,
                    m.id AS internal_id,
                    m.tmdb_id,
                    m.title AS title,
                    m.overview,
                    m.poster_path,
                    m.backdrop_path,
                    m.vote_average,
                    m.vote_count,
                    m.runtime,
                    NULL AS number_of_seasons,
                    m.original_language,
                    m.release_date AS release_day,
                    m.popularity,
                    v.`key` AS youtube_key
                FROM movies m
                INNER JOIN (
                    {$this->bestVideoSql('movie')}
                ) pick ON pick.media_id = m.id
                INNER JOIN videos v ON v.id = pick.video_id
                WHERE m.is_active = 1
                  AND m.poster_path IS NOT NULL
                  AND m.poster_path <> ''

                UNION ALL

                SELECT
                    'tv' AS media_type,
                    t.id AS internal_id,
                    t.tmdb_id,
                    t.name AS title,
                    t.overview,
                    t.poster_path,
                    t.backdrop_path,
                    t.vote_average,
                    t.vote_count,
                    NULL AS runtime,
                    t.number_of_seasons,
                    t.original_language,
                    t.first_air_date AS release_day,
                    t.popularity,
                    v.`key` AS youtube_key
                FROM tv_shows t
                INNER JOIN (
                    {$this->bestVideoSql('tv')}
                ) pick ON pick.media_id = t.id
                INNER JOIN videos v ON v.id = pick.video_id
                WHERE t.is_active = 1
                  AND t.poster_path IS NOT NULL
                  AND t.poster_path <> ''
            ) src
            ORDER BY RAND()
            LIMIT :limit
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $hasMore = $rows !== [];

        $movieIds = [];
        $tvIds = [];
        $movieTmdb = [];
        $tvTmdb = [];
        foreach ($rows as $row) {
            if ($row['media_type'] === 'tv') {
                $tvIds[] = (int) $row['internal_id'];
                $tvTmdb[] = (int) $row['tmdb_id'];
            } else {
                $movieIds[] = (int) $row['internal_id'];
                $movieTmdb[] = (int) $row['tmdb_id'];
            }
        }

        $genres = $this->genreNames('movie', $movieIds) + $this->genreNames('tv', $tvIds);
        $device = $deviceId ?? '';
        $movieFlags = $device !== '' ? $this->userState->getFlagsMap($device, 'movie', $movieTmdb) : [];
        $tvFlags = $device !== '' ? $this->userState->getFlagsMap($device, 'tv', $tvTmdb) : [];

        $data = [];
        foreach ($rows as $row) {
            $type = (string) $row['media_type'];
            $tmdbId = (int) $row['tmdb_id'];
            $flags = $type === 'tv'
                ? ($tvFlags[$tmdbId] ?? ['is_saved_for_later' => false])
                : ($movieFlags[$tmdbId] ?? ['is_saved_for_later' => false]);
            $names = $genres[$type . ':' . (int) $row['internal_id']] ?? [];
            $data[] = [
                'media_type' => $type,
                'tmdb_id' => $tmdbId,
                'title' => $row['title'],
                'overview' => $row['overview'],
                'poster_url' => tmdb_image($row['poster_path'], 'w500'),
                'backdrop_url' => tmdb_image($row['backdrop_path'], 'original'),
                'vote_average' => $row['vote_average'] !== null ? (float) $row['vote_average'] : null,
                'vote_count' => $row['vote_count'] !== null ? (int) $row['vote_count'] : null,
                'runtime' => $row['runtime'] !== null ? (int) $row['runtime'] : null,
                'number_of_seasons' => $row['number_of_seasons'] !== null ? (int) $row['number_of_seasons'] : null,
                'original_language' => $row['original_language'],
                'date' => $row['release_day'],
                'genres' => array_slice($names, 0, 3),
                'youtube_id' => $row['youtube_key'],
                'is_saved_for_later' => (bool) ($flags['is_saved_for_later'] ?? false),
                'is_today' => $row['release_day'] === $day,
            ];
        }

        return [
            'page' => $page,
            'limit' => $limit,
            'has_more' => $hasMore,
            'day' => $day,
            'data' => $data,
        ];
    }

    private function bestVideoSql(string $mediaType): string
    {
        $type = $mediaType === 'tv' ? 'tv' : 'movie';

        return "
            SELECT media_id,
                   CAST(SUBSTRING_INDEX(
                       GROUP_CONCAT(id ORDER BY official DESC, (video_type = 'Trailer') DESC, published_at DESC, id DESC),
                       ',',
                       1
                   ) AS UNSIGNED) AS video_id
            FROM videos
            WHERE media_type = '{$type}'
              AND LOWER(site) = 'youtube'
              AND `key` IS NOT NULL
              AND `key` <> ''
              AND video_type IN ('Trailer', 'Teaser')
            GROUP BY media_id
        ";
    }

    /** @param list<int> $ids @return array<string, list<string>> */
    private function genreNames(string $mediaType, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $params = ['media_type' => $mediaType];
        $inList = bind_in_list('genre_media', $ids, $params);
        $stmt = $this->db->prepare(
            "SELECT mg.media_id, g.name
             FROM media_genres mg
             INNER JOIN genres g ON g.id = mg.genre_id
             WHERE mg.media_type = :media_type AND mg.media_id IN ({$inList})
             ORDER BY g.name"
        );
        $stmt->execute($params);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[$mediaType . ':' . (int) $row['media_id']][] = (string) $row['name'];
        }

        return $map;
    }
}
