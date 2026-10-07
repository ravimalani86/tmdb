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
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
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
        $stmt = $this->db->prepare("SELECT b.*, a.title AS a_title, a.poster_path AS a_poster,
            c.title AS b_title, c.poster_path AS b_poster FROM movie_battles b
            INNER JOIN movies a ON a.tmdb_id = b.movie_a_tmdb_id AND a.is_active = 1
            INNER JOIN movies c ON c.tmdb_id = b.movie_b_tmdb_id AND c.is_active = 1
            WHERE b.id = ? AND b.status = 'scheduled' AND b.battle_date <= ?");
        $stmt->execute([$id, $this->todayDate()]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $vote = $this->db->prepare('SELECT selected_tmdb_id FROM movie_battle_votes WHERE battle_id = ? AND voter_id = ?');
        $vote->execute([$id, $voter]);
        $selected = $vote->fetchColumn();
        $date = new DateTimeImmutable($row['battle_date'], new DateTimeZone('Asia/Kolkata'));
        $closed = $row['battle_date'] < $this->todayDate();
        $result = [
            'id' => (int) $row['id'], 'battle_date' => $row['battle_date'], 'category' => $row['category'],
            'starts_at' => $date->format(DATE_ATOM), 'ends_at' => $date->modify('+1 day')->format(DATE_ATOM),
            'status' => $closed ? 'closed' : 'active',
            'movie_a' => ['tmdb_id' => (int) $row['movie_a_tmdb_id'], 'title' => $row['a_title'], 'poster_url' => tmdb_image($row['a_poster'], 'w500')],
            'movie_b' => ['tmdb_id' => (int) $row['movie_b_tmdb_id'], 'title' => $row['b_title'], 'poster_url' => tmdb_image($row['b_poster'], 'w500')],
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
        return $this->db->query("SELECT b.*, a.title AS movie_a_title, c.title AS movie_b_title,
            (SELECT COUNT(*) FROM movie_battle_votes v WHERE v.battle_id = b.id) AS total_votes
            FROM movie_battles b LEFT JOIN movies a ON a.tmdb_id = b.movie_a_tmdb_id
            LEFT JOIN movies c ON c.tmdb_id = b.movie_b_tmdb_id ORDER BY battle_date DESC LIMIT 100")->fetchAll();
    }

    public function searchMovies(string $query): array
    {
        $stmt = $this->db->prepare('SELECT tmdb_id, title FROM movies WHERE is_active = 1 AND title LIKE ? ORDER BY popularity DESC LIMIT 10');
        $stmt->execute(['%' . $query . '%']);
        return $stmt->fetchAll();
    }

    public function save(array $input): array
    {
        $id = (int) ($input['id'] ?? 0);
        $raw = (string) ($input['battle_date'] ?? '');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new DateTimeZone('Asia/Kolkata'));
        if (!$date || $date->format('Y-m-d') !== $raw || $raw < $this->todayDate()) throw new InvalidArgumentException('Use today or a future date (YYYY-MM-DD, IST)');
        $a = (int) ($input['movie_a_tmdb_id'] ?? 0); $b = (int) ($input['movie_b_tmdb_id'] ?? 0);
        $category = trim((string) ($input['category'] ?? ''));
        $status = (string) ($input['status'] ?? 'scheduled');
        if ($a <= 0 || $b <= 0 || $a === $b) throw new InvalidArgumentException('Choose two different movies');
        if (strlen($category) > 100 || !in_array($status, ['scheduled', 'disabled'], true)) throw new InvalidArgumentException('Invalid category or status');
        $this->db->beginTransaction();
        try {
            if ($id > 0) {
                $stmt = $this->db->prepare('SELECT * FROM movie_battles WHERE id = ? FOR UPDATE');
                $stmt->execute([$id]); $old = $stmt->fetch();
                if (!$old) throw new InvalidArgumentException('Battle not found');
                $stmt = $this->db->prepare('SELECT COUNT(*) FROM movie_battle_votes WHERE battle_id = ?');
                $stmt->execute([$id]);
                if ((int) $stmt->fetchColumn() > 0 && ($raw !== $old['battle_date'] || $a !== (int) $old['movie_a_tmdb_id'] || $b !== (int) $old['movie_b_tmdb_id'])) {
                    throw new InvalidArgumentException('Movies and date cannot change after voting starts');
                }
            }
            $stmt = $this->db->prepare('SELECT COUNT(DISTINCT tmdb_id) FROM movies WHERE tmdb_id IN (?, ?) AND is_active = 1');
            $stmt->execute([$a, $b]);
            if ((int) $stmt->fetchColumn() !== 2) throw new InvalidArgumentException('Both movies must exist and be active in the catalog');
            if ($id > 0) {
                $stmt = $this->db->prepare('UPDATE movie_battles SET battle_date=?, movie_a_tmdb_id=?, movie_b_tmdb_id=?, category=?, status=? WHERE id=?');
                $stmt->execute([$raw, $a, $b, $category, $status, $id]);
            } else {
                $stmt = $this->db->prepare('INSERT INTO movie_battles (battle_date, movie_a_tmdb_id, movie_b_tmdb_id, category, status) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$raw, $a, $b, $category, $status]); $id = (int) $this->db->lastInsertId();
            }
            $this->db->commit();
            return ['id' => $id, 'saved' => true];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($e instanceof PDOException && (string) $e->getCode() === '23000') throw new InvalidArgumentException('A battle is already scheduled for this date');
            throw $e;
        }
    }
}
