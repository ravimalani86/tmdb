<?php

declare(strict_types=1);

final class PersonRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function getPersonDetail(int $tmdbId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tmdb_id, name, biography, birthday, deathday, place_of_birth,
                    gender, known_for_department, popularity, profile_path, imdb_id, homepage
             FROM people
             WHERE tmdb_id = :tmdb_id AND deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute(['tmdb_id' => $tmdbId]);
        $person = $stmt->fetch();
        if (!$person) {
            return null;
        }

        $personId = (int) $person['id'];

        return [
            'tmdb_id' => (int) $person['tmdb_id'],
            'name' => $person['name'] ?? '',
            'biography' => $person['biography'],
            'birthday' => $person['birthday'],
            'deathday' => $person['deathday'],
            'place_of_birth' => $person['place_of_birth'],
            'gender' => $person['gender'] !== null ? (int) $person['gender'] : null,
            'known_for_department' => $person['known_for_department'],
            'popularity' => $person['popularity'] !== null ? (float) $person['popularity'] : null,
            'profile_url' => tmdb_image($person['profile_path'], 'w500'),
            'imdb_id' => $person['imdb_id'],
            'homepage' => $person['homepage'],
            'credits' => $this->getFilmography($personId),
        ];
    }

    /**
     * Movies + TV the person worked on (cast/crew), popularity sorted.
     *
     * @return list<array<string, mixed>>
     */
    private function getFilmography(int $personId, int $limit = 60): array
    {
        $movies = $this->db->prepare(
            "SELECT m.tmdb_id, m.title, m.poster_path, m.vote_average, m.release_date, m.popularity,
                    c.character, c.job, c.credit_type, c.department,
                    'movie' AS media_type
             FROM credits c
             INNER JOIN movies m ON m.id = c.media_id
             WHERE c.person_id = :person_id
               AND c.media_type = 'movie'
               AND m.deleted_at IS NULL
             ORDER BY m.popularity DESC, m.release_date DESC
             LIMIT :limit"
        );
        $movies->bindValue(':person_id', $personId, PDO::PARAM_INT);
        $movies->bindValue(':limit', $limit, PDO::PARAM_INT);
        $movies->execute();

        $tv = $this->db->prepare(
            "SELECT t.tmdb_id, t.name AS title, t.poster_path, t.vote_average, t.first_air_date AS release_date,
                    t.popularity, c.character, c.job, c.credit_type, c.department,
                    'tv' AS media_type
             FROM credits c
             INNER JOIN tv_shows t ON t.id = c.media_id
             WHERE c.person_id = :person_id
               AND c.media_type = 'tv'
               AND t.deleted_at IS NULL
             ORDER BY t.popularity DESC, t.first_air_date DESC
             LIMIT :limit"
        );
        $tv->bindValue(':person_id', $personId, PDO::PARAM_INT);
        $tv->bindValue(':limit', $limit, PDO::PARAM_INT);
        $tv->execute();

        $mapRow = static function (array $row): array {
            $role = null;
            if (($row['credit_type'] ?? '') === 'cast' && !empty($row['character'])) {
                $role = (string) $row['character'];
            } elseif (!empty($row['job'])) {
                $role = (string) $row['job'];
            }

            return [
                'tmdb_id' => (int) $row['tmdb_id'],
                'media_type' => (string) $row['media_type'],
                'title' => (string) ($row['title'] ?? ''),
                'poster_url' => tmdb_image($row['poster_path'], 'w500'),
                'vote_average' => $row['vote_average'] !== null ? (float) $row['vote_average'] : null,
                'date' => $row['release_date'],
                'role' => $role,
                'credit_type' => $row['credit_type'],
                'popularity' => $row['popularity'] !== null ? (float) $row['popularity'] : 0.0,
            ];
        };

        $items = array_merge(
            array_map($mapRow, $movies->fetchAll()),
            array_map($mapRow, $tv->fetchAll())
        );

        usort($items, static function (array $a, array $b): int {
            return ($b['popularity'] <=> $a['popularity']);
        });

        // De-dupe same title appearing as cast+crew
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            $key = $item['media_type'] . ':' . $item['tmdb_id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            unset($item['popularity']);
            $out[] = $item;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
