<?php

declare(strict_types=1);

final class GenreRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function listGenres(string $mediaType = 'movie'): array
    {
        $mediaType = $mediaType === 'tv' ? 'tv' : 'movie';
        $stmt = $this->db->prepare(
            "SELECT tmdb_id, name, media_type
             FROM genres
             WHERE media_type = :media_type
             ORDER BY name"
        );
        $stmt->execute(['media_type' => $mediaType]);
        $rows = $stmt->fetchAll();

        return [
            'data' => array_map(static fn(array $row): array => [
                'tmdb_id' => (int) $row['tmdb_id'],
                'name' => $row['name'],
                'media_type' => $row['media_type'],
            ], $rows),
        ];
    }
}
