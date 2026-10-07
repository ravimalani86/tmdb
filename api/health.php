<?php

declare(strict_types=1);

/**
 * Temporary live diagnostics. Delete after API is healthy.
 * Open: https://app.myappworld.in/tmdb/api/health.php
 */
header('Content-Type: application/json; charset=utf-8');

$out = [
    'ok' => true,
    'php' => PHP_VERSION,
    'checks' => [],
];

try {
    $out['checks']['str_starts_with'] = function_exists('str_starts_with');
    $out['checks']['str_contains'] = function_exists('str_contains');
    $out['checks']['curl'] = function_exists('curl_init');
    $out['checks']['openssl'] = function_exists('openssl_sign');
    $out['checks']['pdo_mysql'] = extension_loaded('pdo_mysql');

    $files = [
        'config.php',
        'helpers.php',
        'Database.php',
        'index.php',
        '.env',
    ];
    foreach ($files as $f) {
        $p = __DIR__ . DIRECTORY_SEPARATOR . $f;
        $out['checks']['file:' . $f] = is_readable($p);
    }

    $config = require __DIR__ . '/config.php';
    $out['checks']['config_loaded'] = is_array($config);
    $out['checks']['api_key_set'] = trim((string) ($config['api_key'] ?? '')) !== '';

    require_once __DIR__ . '/Database.php';
    $pdo = Database::connection($config);
    $out['checks']['db'] = true;
    $out['checks']['db_server'] = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
} catch (Throwable $e) {
    $out['ok'] = false;
    $out['error'] = $e->getMessage();
    $out['error_type'] = get_class($e);
    $out['error_file'] = $e->getFile();
    $out['error_line'] = $e->getLine();
    http_response_code(500);
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
