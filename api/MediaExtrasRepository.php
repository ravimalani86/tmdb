<?php

declare(strict_types=1);

final class MediaExtrasRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function getVideos(string $mediaType, int $mediaId): array
    {
        $stmt = $this->db->prepare(
            "SELECT name, `key`, site, size, video_type, official, published_at
             FROM videos
             WHERE media_type = :media_type AND media_id = :media_id
             ORDER BY official DESC, video_type ASC, name ASC"
        );
        $stmt->execute(['media_type' => $mediaType, 'media_id' => $mediaId]);
        $rows = $stmt->fetchAll();

        return array_map(static fn(array $row): array => [
            'name' => $row['name'],
            'key' => $row['key'],
            'site' => $row['site'],
            'type' => $row['video_type'],
            'official' => (bool) $row['official'],
            'size' => $row['size'] !== null ? (int) $row['size'] : null,
            'published_at' => $row['published_at'],
            'url' => video_url($row['site'], $row['key']),
        ], $rows);
    }

    public function getImages(string $mediaType, int $mediaId, int $limitPerType = 20): array
    {
        $posters = $this->fetchImages($mediaType, $mediaId, 'poster', $limitPerType);
        $backdrops = $this->fetchImages($mediaType, $mediaId, 'backdrop', $limitPerType);

        return [
            'posters' => $posters,
            'backdrops' => $backdrops,
        ];
    }

    public function getKeywords(string $mediaType, int $mediaId): array
    {
        $stmt = $this->db->prepare(
            "SELECT k.tmdb_id, k.name
             FROM media_keywords mk
             INNER JOIN keywords k ON k.id = mk.keyword_id
             WHERE mk.media_type = :media_type AND mk.media_id = :media_id
             ORDER BY k.name"
        );
        $stmt->execute(['media_type' => $mediaType, 'media_id' => $mediaId]);
        $rows = $stmt->fetchAll();

        return array_map(static fn(array $row): array => [
            'tmdb_id' => (int) $row['tmdb_id'],
            'name' => $row['name'],
        ], $rows);
    }

    public function getSpokenLanguages(string $mediaType, int $mediaId): array
    {
        $stmt = $this->db->prepare(
            "SELECT sl.iso_code, sl.language_name, sl.english_name
             FROM media_spoken_languages msl
             INNER JOIN spoken_languages sl ON sl.id = msl.language_id
             WHERE msl.media_type = :media_type AND msl.media_id = :media_id
             ORDER BY sl.english_name, sl.language_name"
        );
        $stmt->execute(['media_type' => $mediaType, 'media_id' => $mediaId]);
        $rows = $stmt->fetchAll();

        return array_map(static fn(array $row): array => [
            'code' => $row['iso_code'],
            'name' => $row['language_name'],
            'english_name' => $row['english_name'],
        ], $rows);
    }

    public function getRecommendations(string $mediaType, int $mediaId, int $limit = 20): array
    {
        if ($mediaType === 'tv') {
            $sql = "
                SELECT t.tmdb_id, t.name AS title, t.poster_path, t.vote_average,
                       r.recommendation_score
                FROM recommendations r
                INNER JOIN tv_shows t ON t.tmdb_id = r.recommended_media_id
                WHERE r.media_type = 'tv' AND r.media_id = :media_id
                ORDER BY r.recommendation_score DESC, t.popularity DESC
                LIMIT :limit
            ";
        } else {
            $sql = "
                SELECT m.tmdb_id, m.title, m.poster_path, m.vote_average,
                       r.recommendation_score
                FROM recommendations r
                INNER JOIN movies m ON m.tmdb_id = r.recommended_media_id
                WHERE r.media_type = 'movie' AND r.media_id = :media_id
                ORDER BY r.recommendation_score DESC, m.popularity DESC
                LIMIT :limit
            ";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':media_id', $mediaId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return array_map(static function (array $row) use ($mediaType): array {
            $item = [
                'tmdb_id' => (int) $row['tmdb_id'],
                'poster_url' => tmdb_image($row['poster_path'], 'w500'),
                'vote_average' => $row['vote_average'] !== null ? (float) $row['vote_average'] : null,
                'recommendation_score' => $row['recommendation_score'] !== null
                    ? (float) $row['recommendation_score'] : null,
            ];
            if ($mediaType === 'tv') {
                $item['name'] = $row['title'];
            } else {
                $item['title'] = $row['title'];
            }
            return $item;
        }, $rows);
    }

    private function fetchImages(
        string $mediaType,
        int $mediaId,
        string $imageType,
        int $limit,
    ): array {
        $size = $imageType === 'poster' ? 'w500' : 'original';
        $stmt = $this->db->prepare(
            "SELECT file_path, width, height, aspect_ratio, vote_average, vote_count, iso_639_1
             FROM images
             WHERE media_type = :media_type
               AND media_id = :media_id
               AND image_type = :image_type
             ORDER BY vote_average DESC, vote_count DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':media_type', $mediaType);
        $stmt->bindValue(':media_id', $mediaId, PDO::PARAM_INT);
        $stmt->bindValue(':image_type', $imageType);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return array_map(static fn(array $row): array => [
            'url' => tmdb_image($row['file_path'], $size),
            'width' => $row['width'] !== null ? (int) $row['width'] : null,
            'height' => $row['height'] !== null ? (int) $row['height'] : null,
            'aspect_ratio' => $row['aspect_ratio'] !== null ? (float) $row['aspect_ratio'] : null,
            'vote_average' => $row['vote_average'] !== null ? (float) $row['vote_average'] : null,
            'language' => $row['iso_639_1'],
        ], $rows);
    }
}
