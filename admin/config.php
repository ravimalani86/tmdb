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

$env = api_load_env(__DIR__ . DIRECTORY_SEPARATOR . '.env');

return [
    'db' => [
        'host' => $env['DB_HOST'] ?? 'localhost',
        'port' => (int) ($env['DB_PORT'] ?? 3306),
        'name' => $env['DB_NAME'] ?? 'tmdbdata',
        'user' => $env['DB_USER'] ?? 'root',
        'pass' => $env['DB_PASSWORD'] ?? '',
        'charset' => 'utf8mb4',
    ],
    'admin_bootstrap' => [
        'username' => $env['ADMIN_USERNAME'] ?? 'admin',
        'password' => $env['ADMIN_PASSWORD'] ?? '',
    ],
    'public_base_url' => $env['PUBLIC_BASE_URL'] ?? '',
    'cors_origin' => $env['CORS_ORIGIN'] ?? '*',
];
