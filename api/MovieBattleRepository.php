<?php
declare(strict_types=1);

final class MovieBattleRepository
{
    public function __construct(private PDO $db) {}

    public static function validateVoter(string $voter): string
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $voter)) {
            throw new InvalidArgumentException('Invalid voter_id');
        }
        return strtolower($voter);
    }

    private function todayDate(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
    }

    private function normalizeMediaType(mixed $raw): string
    {
        $type = strtolower(trim((string) $raw));
        if (!in_array($type, ['movie', 'tv'], true)) {
            throw new InvalidArgumentException('media_type must be movie or tv');
        }
        return $type;
    }

    /** @return array{table:string,title:string} */
    private function catalog(string $mediaType): array
    {
        return $mediaType === 'tv'
            ? ['table' => 'tv_shows', 'title' => 'name']
            : ['table' => 'movies', 'title' => 'title'];
    }

    public function today(string $voter): ?array
    {
        $stmt = $this->db->prepare("SELECT id FROM movie_battles WHERE battle_date = ? AND status = 'scheduled'");
        $stmt->execute([$this->todayDate()]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : $this->detail((int) $id, $voter);
    }

    public function detail(int $id, string $voter): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM movie_battles WHERE id = ? AND status = \'scheduled\' AND battle_date <= ?');
        $stmt->execute([$id, $this->todayDate()]);
        $row = $stmt->fetch();
        if (!$row) return null;

        $mediaType = $this->normalizeMediaType($row['media_type'] ?? 'movie');
        $catalog = $this->catalog($mediaType);
        $join = $this->db->prepare(
            "SELECT a.{$catalog['title']} AS a_title, a.poster_path AS a_poster,
                c.{$catalog['title']} AS b_title, c.poster_path AS b_poster
             FROM {$catalog['table']} a
             INNER JOIN {$catalog['table']} c ON c.tmdb_id = ? AND c.is_active = 1
             WHERE a.tmdb_id = ? AND a.is_active = 1"
        );
        $join->execute([(int) $row['movie_b_tmdb_id'], (int) $row['movie_a_tmdb_id']]);
        $titles = $join->fetch();
        if (!$titles) return null;

        $vote = $this->db->prepare('SELECT selected_tmdb_id FROM movie_battle_votes WHERE battle_id = ? AND voter_id = ?');
        $vote->execute([$id, $voter]);
        $selected = $vote->fetchColumn();
        $date = new DateTimeImmutable($row['battle_date'], new DateTimeZone('UTC'));
        $closed = $row['battle_date'] < $this->todayDate();
        $side = static fn(int $tmdbId, string $title, ?string $poster): array => [
            'tmdb_id' => $tmdbId,
            'title' => $title,
            'poster_url' => tmdb_image($poster, 'w500'),
            'media_type' => $mediaType,
        ];
        $result = [
            'id' => (int) $row['id'],
            'battle_date' => $row['battle_date'],
            'media_type' => $mediaType,
            'category' => $row['category'],
            'starts_at' => $date->format(DATE_ATOM),
            'ends_at' => $date->modify('+1 day')->format(DATE_ATOM),
            'status' => $closed ? 'closed' : 'active',
            'movie_a' => $side((int) $row['movie_a_tmdb_id'], $titles['a_title'], $titles['a_poster']),
            'movie_b' => $side((int) $row['movie_b_tmdb_id'], $titles['b_title'], $titles['b_poster']),
            'selected_tmdb_id' => $selected === false ? null : (int) $selected,
            'results' => null,
        ];
        if ($selected !== false || $closed) {
            $counts = $this->db->prepare('SELECT selected_tmdb_id, COUNT(*) AS votes FROM movie_battle_votes WHERE battle_id = ? GROUP BY selected_tmdb_id');
            $counts->execute([$id]);
            $a = 0; $b = 0;
            foreach ($counts->fetchAll() as $count) {
                if ((int) $count['selected_tmdb_id'] === (int) $row['movie_a_tmdb_id']) $a = (int) $count['votes'];
                if ((int) $count['selected_tmdb_id'] === (int) $row['movie_b_tmdb_id']) $b = (int) $count['votes'];
            }
            $total = $a + $b;
            $pa = $total ? round(100 * $a / $total, 1) : 0;
            $result['results'] = ['movie_a_votes' => $a, 'movie_b_votes' => $b, 'total_votes' => $total,
                'movie_a_percent' => $pa, 'movie_b_percent' => $total ? round(100 - $pa, 1) : 0,
                'leading_tmdb_id' => $a === $b ? null : (int) $row[$a > $b ? 'movie_a_tmdb_id' : 'movie_b_tmdb_id']];
        }
        return $result;
    }

    public function vote(int $id, string $voter, int $selection): array
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM movie_battles WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $battle = $stmt->fetch();
            if (!$battle || $battle['status'] !== 'scheduled' || $battle['battle_date'] !== $this->todayDate()) {
                throw new DomainException('Battle is not active');
            }
            if (!in_array($selection, [(int) $battle['movie_a_tmdb_id'], (int) $battle['movie_b_tmdb_id']], true)) {
                throw new InvalidArgumentException('Selected movie does not belong to this battle');
            }
            if ($this->detail($id, $voter) === null) throw new DomainException('Battle movies are unavailable');
            // Ignore a repeated vote without changing the original choice; safe for retries.
            $stmt = $this->db->prepare('INSERT INTO movie_battle_votes (battle_id, voter_id, selected_tmdb_id)
                VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE id = id');
            $stmt->execute([$id, $voter, $selection]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return $this->detail($id, $voter) ?? throw new DomainException('Battle unavailable');
    }

    public function listBattles(): array
    {
        $rows = $this->db->query("SELECT b.*,
            (SELECT COUNT(*) FROM movie_battle_votes v WHERE v.battle_id = b.id) AS total_votes
            FROM movie_battles b ORDER BY battle_date DESC LIMIT 100")->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $mediaType = in_array(($row['media_type'] ?? 'movie'), ['movie', 'tv'], true)
                ? $row['media_type']
                : 'movie';
            $catalog = $this->catalog($mediaType);
            $lookup = $this->db->prepare(
                "SELECT {$catalog['title']} AS title FROM {$catalog['table']} WHERE tmdb_id = ? LIMIT 1"
            );
            $lookup->execute([(int) $row['movie_a_tmdb_id']]);
            $titleA = $lookup->fetchColumn();
            $lookup->execute([(int) $row['movie_b_tmdb_id']]);
            $titleB = $lookup->fetchColumn();
            $out[] = array_merge($row, [
                'media_type' => $mediaType,
                'movie_a_title' => $titleA === false ? null : $titleA,
                'movie_b_title' => $titleB === false ? null : $titleB,
            ]);
        }
        return $out;
    }

    public function searchMovies(string $query, string $mediaType = 'movie'): array
    {
        $mediaType = $this->normalizeMediaType($mediaType);
        $catalog = $this->catalog($mediaType);
        $stmt = $this->db->prepare(
            "SELECT tmdb_id, {$catalog['title']} AS title
             FROM {$catalog['table']}
             WHERE is_active = 1 AND {$catalog['title']} LIKE ?
             ORDER BY popularity DESC LIMIT 10"
        );
        $stmt->execute(['%' . $query . '%']);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['media_type'] = $mediaType;
        }
        unset($row);
        return $rows;
    }

    public function save(array $input): array
    {
        $id = (int) ($input['id'] ?? 0);
        $raw = (string) ($input['battle_date'] ?? '');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $raw || $raw < $this->todayDate()) throw new InvalidArgumentException('Use today or a future date (YYYY-MM-DD, GMT)');
        $mediaType = $this->normalizeMediaType($input['media_type'] ?? '');
        $a = (int) ($input['movie_a_tmdb_id'] ?? 0); $b = (int) ($input['movie_b_tmdb_id'] ?? 0);
        $category = trim((string) ($input['category'] ?? ''));
        $status = (string) ($input['status'] ?? 'scheduled');
        if ($a <= 0 || $b <= 0 || $a === $b) throw new InvalidArgumentException('Choose two different titles');
        if (strlen($category) > 100 || !in_array($status, ['scheduled', 'disabled'], true)) throw new InvalidArgumentException('Invalid category or status');
        $catalog = $this->catalog($mediaType);
        $this->db->beginTransaction();
        try {
            if ($id > 0) {
                $stmt = $this->db->prepare('SELECT * FROM movie_battles WHERE id = ? FOR UPDATE');
                $stmt->execute([$id]); $old = $stmt->fetch();
                if (!$old) throw new InvalidArgumentException('Battle not found');
                $stmt = $this->db->prepare('SELECT COUNT(*) FROM movie_battle_votes WHERE battle_id = ?');
                $stmt->execute([$id]);
                $oldType = (string) ($old['media_type'] ?? 'movie');
                if ((int) $stmt->fetchColumn() > 0 && ($raw !== $old['battle_date'] || $a !== (int) $old['movie_a_tmdb_id'] || $b !== (int) $old['movie_b_tmdb_id'] || $mediaType !== $oldType)) {
                    throw new InvalidArgumentException('Titles, media type, and date cannot change after voting starts');
                }
            }
            $stmt = $this->db->prepare(
                "SELECT COUNT(DISTINCT tmdb_id) FROM {$catalog['table']} WHERE tmdb_id IN (?, ?) AND is_active = 1"
            );
            $stmt->execute([$a, $b]);
            if ((int) $stmt->fetchColumn() !== 2) {
                throw new InvalidArgumentException('Both titles must exist and be active in the ' . ($mediaType === 'tv' ? 'series' : 'movie') . ' catalog');
            }
            if ($id > 0) {
                $stmt = $this->db->prepare('UPDATE movie_battles SET battle_date=?, media_type=?, movie_a_tmdb_id=?, movie_b_tmdb_id=?, category=?, status=? WHERE id=?');
                $stmt->execute([$raw, $mediaType, $a, $b, $category, $status, $id]);
            } else {
                $stmt = $this->db->prepare('INSERT INTO movie_battles (battle_date, media_type, movie_a_tmdb_id, movie_b_tmdb_id, category, status) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$raw, $mediaType, $a, $b, $category, $status]); $id = (int) $this->db->lastInsertId();
            }
            $this->db->commit();
            return ['id' => $id, 'saved' => true];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($e instanceof PDOException && (string) $e->getCode() === '23000') throw new InvalidArgumentException('A battle is already scheduled for this date');
            throw $e;
        }
    }

    public function delete(int $id): array
    {
        if ($id <= 0) throw new InvalidArgumentException('Battle id is required');
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT id FROM movie_battles WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            if (!$stmt->fetch()) throw new InvalidArgumentException('Battle not found');
            $votes = $this->db->prepare('DELETE FROM movie_battle_votes WHERE battle_id = ?');
            $votes->execute([$id]);
            $del = $this->db->prepare('DELETE FROM movie_battles WHERE id = ?');
            $del->execute([$id]);
            $this->db->commit();
            return ['id' => $id, 'deleted' => true];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
