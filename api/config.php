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
    'tmdb_image_base' => 'https://image.tmdb.org/t/p/',
    'cors_origin' => $env['API_CORS_ORIGIN'] ?? '*',
];
