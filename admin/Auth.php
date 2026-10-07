<?php

declare(strict_types=1);

/**
 * Session-based admin auth. One shared users table; no roles for now.
 */
final class Auth
{
    public function __construct(private PDO $db)
    {
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(64) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_users_username (username)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        ensure_column(
            $this->db,
            'users',
            'role',
            "role VARCHAR(20) NOT NULL DEFAULT 'admin' AFTER password_hash"
        );
        $this->ensureSuperAdminExists();
    }

    /**
     * Upgrade path: a users table created before roles existed has one
     * account (the original bootstrap admin) with the default 'admin'
     * role. Promote it so nobody loses super-admin access after upgrading.
     */
    private function ensureSuperAdminExists(): void
    {
        $hasSuperAdmin = (int) $this->db->query(
            "SELECT COUNT(*) FROM users WHERE role = 'super_admin'"
        )->fetchColumn();
        if ($hasSuperAdmin > 0) {
            return;
        }
        $this->db->exec(
            "UPDATE users SET role = 'super_admin' WHERE id = (SELECT id FROM (SELECT MIN(id) AS id FROM users) t)"
        );
    }

    /** Creates the first super-admin user from .env if the table is empty. */
    public function ensureBootstrapAdmin(string $username, string $password): void
    {
        $count = (int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($count > 0) {
            return;
        }
        $username = trim($username) !== '' ? trim($username) : 'admin';
        $password = $password !== '' ? $password : bin2hex(random_bytes(6));
        $stmt = $this->db->prepare(
            "INSERT INTO users (username, password_hash, role) VALUES (:username, :hash, 'super_admin')"
        );
        $stmt->execute([
            'username' => $username,
            'hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public function login(string $username, string $password): bool
    {
        $stmt = $this->db->prepare(
            'SELECT id, username, password_hash, role FROM users WHERE username = :username LIMIT 1'
        );
        $stmt->execute(['username' => $username]);
        $row = $stmt->fetch();
        if ($row === false || !password_verify($password, (string) $row['password_hash'])) {
            return false;
        }

        $this->startSession();
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $row['id'];
        $_SESSION['username'] = (string) $row['username'];
        $_SESSION['role'] = (string) $row['role'];
        return true;
    }

    public function logout(): void
    {
        $this->startSession();
        $_SESSION = [];
        session_destroy();
    }

    /** @return array{id:int, username:string, role:string}|null */
    public function currentUser(): ?array
    {
        $this->startSession();
        if (!isset($_SESSION['user_id'])) {
            return null;
        }
        return [
            'id' => (int) $_SESSION['user_id'],
            'username' => (string) $_SESSION['username'],
            'role' => (string) ($_SESSION['role'] ?? 'admin'),
        ];
    }

    public function requireAuth(): array
    {
        $user = $this->currentUser();
        if ($user === null) {
            json_error('Unauthorized', 401);
        }
        return $user;
    }

    public function requireSuperAdmin(): array
    {
        $user = $this->requireAuth();
        if ($user['role'] !== 'super_admin') {
            json_error('Forbidden: super admin only', 403);
        }
        return $user;
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword): void
    {
        $stmt = $this->db->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        if ($row === false || !password_verify($currentPassword, (string) $row['password_hash'])) {
            throw new InvalidArgumentException('Current password is incorrect');
        }
        if (strlen($newPassword) < 6) {
            throw new InvalidArgumentException('New password must be at least 6 characters');
        }
        $update = $this->db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $update->execute([
            'hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'id' => $userId,
        ]);
    }
}
