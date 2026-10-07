<?php

declare(strict_types=1);

function json_response(mixed $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status = 400): void
{
    json_response(['error' => $message], $status);
}

function query_string(array $source, string $key): ?string
{
    if (!isset($source[$key])) {
        return null;
    }
    $value = trim((string) $source[$key]);
    return $value === '' ? null : $value;
}

function require_body_string(array $source, string $key): string
{
    $value = query_string($source, $key);
    if ($value === null) {
        json_error("Bad request: missing {$key}", 400);
    }
    return $value;
}

function require_body_int(array $source, string $key): int
{
    if (!isset($source[$key]) || !is_numeric($source[$key])) {
        json_error("Bad request: missing or invalid {$key}", 400);
    }
    return (int) $source[$key];
}

/** Adds a column to an existing table if it isn't there yet (simple migration helper). */
function ensure_column(PDO $db, string $table, string $column, string $addColumnSql): void
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column'
    );
    $stmt->execute(['table' => $table, 'column' => $column]);
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }
    $db->exec("ALTER TABLE {$table} ADD COLUMN {$addColumnSql}");
}

/** Adds a unique index to an existing table if it isn't there yet (simple migration helper). */
function ensure_unique_index(PDO $db, string $table, string $indexName, string $column): void
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index_name'
    );
    $stmt->execute(['table' => $table, 'index_name' => $indexName]);
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }
    $db->exec("ALTER TABLE {$table} ADD UNIQUE KEY {$indexName} ({$column})");
}

/** Drops a column from an existing table if it's still there (simple migration helper). */
function drop_column_if_exists(PDO $db, string $table, string $column): void
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column'
    );
    $stmt->execute(['table' => $table, 'column' => $column]);
    if ((int) $stmt->fetchColumn() === 0) {
        return;
    }
    $db->exec("ALTER TABLE {$table} DROP COLUMN {$column}");
}

/**
 * Forwards a logged-in admin request to the existing TMDB API.
 * The TMDB API itself is not modified; the panel adds the API key server-side.
 */
/**
 * @return array{status:int, body:string}
 */
function tmdb_admin_post(string $targetPath, array $input): array
{
    $envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . '.env';
    $env = api_load_env($envPath);
    $public = rtrim((string) ($env['PUBLIC_BASE_URL'] ?? 'http://127.0.0.1/tmdb'), '/');
    $key = (string) ($env['API_KEY'] ?? '');
    if ($key === '') {
        json_error('TMDB API_KEY is missing from api/.env', 500);
    }

    $url = $public . '/api' . $targetPath;
    $payload = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\nX-API-Key: {$key}\r\n",
            'content' => $payload === false ? '{}' : $payload,
            'timeout' => 300,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    $status = 502;
    foreach ($http_response_header ?? [] as $headerLine) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})\b#', $headerLine, $match) === 1) {
            $status = (int) $match[1];
        }
    }
    if ($body === false) {
        json_error('Could not reach TMDB API at ' . $url, 502);
    }
    return [
        'status' => $status > 0 ? $status : 502,
        'body' => $body,
    ];
}

function tmdb_admin_forward(string $targetPath, array $input): void
{
    $result = tmdb_admin_post($targetPath, $input);
    http_response_code($result['status']);
    header('Content-Type: application/json; charset=utf-8');
    echo $result['body'];
    exit;
}

/** Public site root URL, e.g. http://localhost/tmdb/admin */
function app_public_base(): string
{
    global $config;
    $configured = trim((string) ($config['public_base_url'] ?? ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    // /tmdb/admin/index.php → /tmdb/admin
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = dirname($script);
    if ($basePath === '/' || $basePath === '.' || $basePath === '\\') {
        $basePath = '';
    }

    return $scheme . '://' . $host . $basePath;
}

/** Active catalog apps, using the same list the Catalog apps page shows. */
function count_catalog_apps(): int
{
    $result = tmdb_admin_post('/admin/apps/list', []);
    $decoded = json_decode($result['body'], true);
    if ($result['status'] !== 200 || !is_array($decoded)) {
        $message = is_array($decoded) ? (string) ($decoded['error'] ?? '') : '';
        json_error($message !== '' ? $message : 'Could not load catalog apps', $result['status']);
    }
    $rows = $decoded['data'] ?? [];
    return is_array($rows) ? count($rows) : 0;
}
