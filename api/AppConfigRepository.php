<?php

declare(strict_types=1);

/**
 * Multi-app JSON store (admin CRUD + public fetch by app_id).
 */
final class AppConfigRepository
{
    public function __construct(private PDO $db)
    {
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS app_configs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                app_id VARCHAR(64) NOT NULL,
                app_name VARCHAR(120) NOT NULL,
                package_name VARCHAR(191) NOT NULL,
                config_json LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_app_configs_app_id (app_id),
                UNIQUE KEY uq_app_configs_package (package_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listApps(): array
    {
        $stmt = $this->db->query(
            'SELECT id, app_id, app_name, package_name, config_json, created_at, updated_at
             FROM app_configs
             ORDER BY app_name ASC, id ASC'
        );
        $rows = $stmt->fetchAll();
        return array_map(fn(array $row): array => $this->mapRow($row), $rows);
    }

    public function getByAppId(string $appId): ?array
    {
        $appId = $this->normalizeAppId($appId, false);
        $stmt = $this->db->prepare(
            'SELECT id, app_id, app_name, package_name, config_json, created_at, updated_at
             FROM app_configs
             WHERE app_id = :app_id
             LIMIT 1'
        );
        $stmt->execute(['app_id' => $appId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        return $this->mapRow($row);
    }

    /** Stored JSON string, or null if app does not exist. Empty store → "{}". */
    public function fetchPublicJson(string $appId): ?string
    {
        $appId = $this->normalizeAppId($appId, false);
        $stmt = $this->db->prepare(
            'SELECT config_json FROM app_configs WHERE app_id = :app_id LIMIT 1'
        );
        $stmt->execute(['app_id' => $appId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $raw = trim((string) ($row['config_json'] ?? ''));
        return $raw === '' ? '{}' : $raw;
    }

    /**
     * @param mixed $config object/array from JSON body, or JSON string
     */
    public function create(string $appName, string $appId, string $packageName, mixed $config): array
    {
        $appId = $this->normalizeAppId($appId, true);
        $appName = $this->normalizeAppName($appName);
        $packageName = $this->normalizePackageName($packageName);
        $json = $this->normalizeConfigJson($config);

        $this->assertUnique($appId, $packageName, null);

        $stmt = $this->db->prepare(
            'INSERT INTO app_configs (app_id, app_name, package_name, config_json)
             VALUES (:app_id, :app_name, :package_name, :config_json)'
        );
        try {
            $stmt->execute([
                'app_id' => $appId,
                'app_name' => $appName,
                'package_name' => $packageName,
                'config_json' => $json,
            ]);
        } catch (PDOException $e) {
            $this->throwDuplicate($e);
        }

        $created = $this->getByAppId($appId);
        if ($created === null) {
            throw new RuntimeException('App missing after create');
        }
        return $created;
    }

    /**
     * Lookup by app_id (not changed). Updates name, package, JSON.
     *
     * @param mixed $config
     */
    public function update(string $appId, string $appName, string $packageName, mixed $config): array
    {
        $appId = $this->normalizeAppId($appId, false);
        $existing = $this->getByAppId($appId);
        if ($existing === null) {
            throw new RuntimeException('App not found');
        }

        $appName = $this->normalizeAppName($appName);
        $packageName = $this->normalizePackageName($packageName);
        $json = $this->normalizeConfigJson($config);

        $this->assertUnique($appId, $packageName, (int) $existing['id']);

        $stmt = $this->db->prepare(
            'UPDATE app_configs
             SET app_name = :app_name, package_name = :package_name, config_json = :config_json
             WHERE app_id = :app_id'
        );
        try {
            $stmt->execute([
                'app_id' => $appId,
                'app_name' => $appName,
                'package_name' => $packageName,
                'config_json' => $json,
            ]);
        } catch (PDOException $e) {
            $this->throwDuplicate($e);
        }

        $updated = $this->getByAppId($appId);
        if ($updated === null) {
            throw new RuntimeException('App missing after update');
        }
        return $updated;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        $raw = trim((string) ($row['config_json'] ?? ''));
        if ($raw === '') {
            $raw = '{}';
        }
        $decoded = json_decode($raw, true);
        $config = is_array($decoded) ? $decoded : new stdClass();

        return [
            'id' => (int) $row['id'],
            'app_id' => (string) $row['app_id'],
            'app_name' => (string) $row['app_name'],
            'package_name' => (string) $row['package_name'],
            'config' => $config,
            'raw' => $raw,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function normalizeAppId(string $appId, bool $forCreate): string
    {
        $appId = trim($appId);
        if ($appId === '') {
            throw new InvalidArgumentException('app_id is required');
        }
        if (strlen($appId) > 64) {
            throw new InvalidArgumentException('app_id must be 64 characters or fewer');
        }
        if ($forCreate && !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $appId)) {
            throw new InvalidArgumentException(
                'app_id must start with a letter or number and use only letters, numbers, dot, underscore, hyphen'
            );
        }
        return $appId;
    }

    private function normalizeAppName(string $appName): string
    {
        $appName = trim($appName);
        if ($appName === '') {
            throw new InvalidArgumentException('app_name is required');
        }
        if (strlen($appName) > 120) {
            throw new InvalidArgumentException('app_name must be 120 characters or fewer');
        }
        return $appName;
    }

    private function normalizePackageName(string $packageName): string
    {
        $packageName = trim($packageName);
        if ($packageName === '') {
            throw new InvalidArgumentException('package_name is required');
        }
        if (strlen($packageName) > 191) {
            throw new InvalidArgumentException('package_name must be 191 characters or fewer');
        }
        return $packageName;
    }

    /** @param mixed $config */
    private function normalizeConfigJson(mixed $config): string
    {
        if ($config === null) {
            return '{}';
        }
        if (is_string($config)) {
            $trimmed = trim($config);
            if ($trimmed === '') {
                return '{}';
            }
            $decoded = json_decode($trimmed);
            if (!is_object($decoded) && !is_array($decoded)) {
                throw new InvalidArgumentException('config must be a JSON object or array');
            }
            $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded)) {
                throw new InvalidArgumentException('config could not be encoded');
            }
            return $encoded;
        }
        if (is_array($config)) {
            $encoded = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded)) {
                throw new InvalidArgumentException('config could not be encoded');
            }
            return $encoded;
        }
        if (is_object($config)) {
            $encoded = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded)) {
                throw new InvalidArgumentException('config could not be encoded');
            }
            return $encoded;
        }
        throw new InvalidArgumentException('config must be a JSON object or array');
    }

    private function assertUnique(string $appId, string $packageName, ?int $ignoreId): void
    {
        $stmt = $this->db->prepare(
            'SELECT id, app_id, package_name FROM app_configs
             WHERE app_id = :app_id OR package_name = :package_name'
        );
        $stmt->execute(['app_id' => $appId, 'package_name' => $packageName]);
        foreach ($stmt->fetchAll() as $row) {
            $id = (int) $row['id'];
            if ($ignoreId !== null && $id === $ignoreId) {
                continue;
            }
            if ((string) $row['app_id'] === $appId) {
                throw new InvalidArgumentException('app_id already exists');
            }
            if ((string) $row['package_name'] === $packageName) {
                throw new InvalidArgumentException('package_name already exists');
            }
        }
    }

    private function throwDuplicate(PDOException $e): void
    {
        $msg = $e->getMessage();
        if (str_contains($msg, 'uq_app_configs_app_id') || str_contains($msg, 'app_id')) {
            throw new InvalidArgumentException('app_id already exists');
        }
        if (str_contains($msg, 'uq_app_configs_package') || str_contains($msg, 'package_name')) {
            throw new InvalidArgumentException('package_name already exists');
        }
        throw $e;
    }
}
