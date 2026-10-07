<?php

declare(strict_types=1);

/**
 * Admin user management. Assumes Auth has already ensured the `users`
 * table (and its `role` column) exists — this class only reads/writes it.
 * The super_admin row (the original bootstrap account) can never be
 * deleted or demoted through here.
 */
final class UserRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function countUsers(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    /** @return list<array{id:int, username:string, role:string, created_at:string}> */
    public function listUsers(): array
    {
        $stmt = $this->db->query(
            'SELECT id, username, role, created_at FROM users ORDER BY (role = \'super_admin\') DESC, username ASC'
        );
        return array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'role' => (string) $row['role'],
            'created_at' => (string) $row['created_at'],
        ], $stmt->fetchAll());
    }

    /** New users are always plain 'admin' — only the bootstrap account is 'super_admin'. */
    public function createUser(string $username, string $password): array
    {
        $username = trim($username);
        if ($username === '' || strlen($username) > 64) {
            throw new InvalidArgumentException('username is required and must be 64 characters or fewer');
        }
        if (strlen($password) < 6) {
            throw new InvalidArgumentException('password must be at least 6 characters');
        }

        $stmt = $this->db->prepare(
            "INSERT INTO users (username, password_hash, role) VALUES (:username, :hash, 'admin')"
        );
        try {
            $stmt->execute([
                'username' => $username,
                'hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'uq_users_username')) {
                throw new InvalidArgumentException('username already exists');
            }
            throw $e;
        }

        return $this->getById((int) $this->db->lastInsertId());
    }

    public function resetPassword(int $userId, string $newPassword): void
    {
        if (strlen($newPassword) < 6) {
            throw new InvalidArgumentException('password must be at least 6 characters');
        }
        $stmt = $this->db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $stmt->execute(['hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $userId]);
    }

    public function deleteUser(int $userId): void
    {
        $user = $this->getById($userId);
        if ($user === null) {
            return;
        }
        if ($user['role'] === 'super_admin') {
            throw new InvalidArgumentException('The super admin account cannot be deleted');
        }
        $stmt = $this->db->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
    }

    /** @return array{id:int, username:string, role:string, created_at:string}|null */
    public function getById(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, username, role, created_at FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'role' => (string) $row['role'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
