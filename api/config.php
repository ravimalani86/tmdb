<?php

declare(strict_types=1);

function api_load_env(string $path): array
{
    $vars = [];
    if (!is_readable($path)) {
        return $vars;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $vars[trim($key)] = trim($value);
    }
    return $vars;
}

$envPaths = [
    dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env',  // project root (local)
    __DIR__ . DIRECTORY_SEPARATOR . '.env',           // api/.env (server)
];
$env = [];
foreach ($envPaths as $envPath) {
    $loaded = api_load_env($envPath);
    if ($loaded !== []) {
        $env = array_merge($env, $loaded);
    }
}

return [
    'db' => [
        'host' => $env['DB_HOST'] ?? 'localhost',
        'port' => (int) ($env['DB_PORT'] ?? 3306),
        'name' => $env['DB_NAME'] ?? 'tmdbdata',
        'user' => $env['DB_USER'] ?? 'root',
        'pass' => $env['DB_PASSWORD'] ?? '',
        'charset' => 'utf8mb4',
    ],
    // Simple API security for Flutter clients (set this in api/.env on server).
    'api_key' => $env['API_KEY'] ?? '',
    // Admin sync endpoints (defaults to API_KEY if unset).
    'admin_api_key' => $env['ADMIN_API_KEY'] ?? ($env['API_KEY'] ?? ''),
    'tmdb_base_url' => $env['TMDB_BASE_URL'] ?? 'https://api.themoviedb.org/3',
    'tmdb_api_key' => $env['TMDB_API_KEY'] ?? '',
    'tmdb_api_keys' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) ($env['TMDB_API_KEYS'] ?? ($env['TMDB_API_KEY'] ?? '')))
    ))),
    'tmdb_rate_sleep' => (float) ($env['RATE_LIMIT_SLEEP'] ?? 0.2),
    'tmdb_monetization' => $env['WITH_WATCH_MONETIZATION_TYPES'] ?? 'flatrate',
    'providers_config_path' => (static function () use ($env): string {
        $raw = (string) ($env['SYNC_PROVIDERS_FILE'] ?? 'providers_config.json');
        if ($raw !== '' && (str_starts_with($raw, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $raw))) {
            return $raw;
        }
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . ($raw !== '' ? $raw : 'providers_config.json');
    })(),
    'tmdb_image_base' => 'https://image.tmdb.org/t/p/',
    // Optional absolute site root for local logos, e.g. https://tmdb.growdevinfotech.in
    'public_base_url' => $env['PUBLIC_BASE_URL'] ?? '',
    'cors_origin' => $env['API_CORS_ORIGIN'] ?? '*',
];
