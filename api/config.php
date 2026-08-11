<?php

declare(strict_types=1);

// PHP 7.4 compatibility (some hosts still lack PHP 8 string helpers).
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

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

function api_resolve_path(string $raw, string $defaultRelativeToProject): string
{
    $raw = trim($raw);
    if ($raw === '') {
        $raw = $defaultRelativeToProject;
    }
    if ($raw !== '' && (str_starts_with($raw, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $raw))) {
        return $raw;
    }
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $raw);
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

$firebaseSa = trim((string) ($env['FIREBASE_SERVICE_ACCOUNT_PATH'] ?? ''));
if ($firebaseSa === '') {
    $firebaseSaPath = __DIR__ . DIRECTORY_SEPARATOR . 'firebase-service-account.json';
} elseif (str_starts_with($firebaseSa, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $firebaseSa)) {
    $firebaseSaPath = $firebaseSa;
} else {
    $firebaseSaPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $firebaseSa);
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
    'providers_config_path' => api_resolve_path(
        (string) ($env['SYNC_PROVIDERS_FILE'] ?? 'providers_config.json'),
        'providers_config.json'
    ),
    'tmdb_image_base' => 'https://image.tmdb.org/t/p/',
    // Optional absolute site root for local logos, e.g. https://tmdb.growdevinfotech.in
    'public_base_url' => $env['PUBLIC_BASE_URL'] ?? '',
    'cors_origin' => $env['API_CORS_ORIGIN'] ?? '*',
    'firebase' => [
        'project_id' => $env['FIREBASE_PROJECT_ID'] ?? 'movflik',
        'remote_config_key' => $env['FIREBASE_REMOTE_CONFIG_KEY'] ?? 'movflik_config',
        'service_account_path' => $firebaseSaPath,
    ],
];
