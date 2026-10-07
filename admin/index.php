<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Auth.php';
require __DIR__ . '/UserRepository.php';

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $type = (int) ($err['type'] ?? 0);
    if (!in_array($type, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    if (headers_sent()) {
        return;
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => 'PHP fatal: ' . (string) ($err['message'] ?? 'unknown'),
        'file' => (string) ($err['file'] ?? ''),
        'line' => (int) ($err['line'] ?? 0),
    ], JSON_UNESCAPED_SLASHES);
});

header('Access-Control-Allow-Origin: ' . $config['cors_origin']);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

try {
    $rawBody = file_get_contents('php://input');
    $input = [];
    if ($rawBody !== false && trim($rawBody) !== '') {
        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            json_error('Bad request: invalid JSON body', 400);
        }
        $input = $decoded;
    }

    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH) ?? '/';
    $path = rawurldecode($path);
    $path = preg_replace('#^.*?/tmdb/admin#', '', $path) ?? $path;
    $path = '/' . trim($path, '/');

    $pdo = Database::connection($config);
    $auth = new Auth($pdo);
    $auth->ensureBootstrapAdmin(
        (string) $config['admin_bootstrap']['username'],
        (string) $config['admin_bootstrap']['password']
    );
    // --- Auth ---
    if ($path === '/auth/login') {
        $username = require_body_string($input, 'username');
        $password = require_body_string($input, 'password');
        if (!$auth->login($username, $password)) {
            json_error('Invalid username or password', 401);
        }
        json_response(['action' => 'logged_in', 'user' => $auth->currentUser()]);
    }

    if ($path === '/auth/logout') {
        $auth->logout();
        json_response(['action' => 'logged_out']);
    }

    if ($path === '/auth/me') {
        json_response(['user' => $auth->currentUser()]);
    }

    if ($path === '/auth/change-password') {
        $user = $auth->requireAuth();
        $current = require_body_string($input, 'current_password');
        $new = require_body_string($input, 'new_password');
        try {
            $auth->changePassword($user['id'], $current, $new);
            json_response(['action' => 'password_changed']);
        } catch (InvalidArgumentException $e) {
            json_error($e->getMessage(), 400);
        }
    }

    // Everything below requires a logged-in session.
    $auth->requireAuth();

    $users = new UserRepository($pdo);

    // --- Catalog tools: forward to the existing TMDB API (unchanged) ---
    if (preg_match('#^/tmdb/(sync|apps|battles)(/|$)#', $path) === 1) {
        $target = '/admin' . substr($path, strlen('/tmdb'));
        tmdb_admin_forward($target, $input);
    }

    // --- Dashboard KPIs ---
    if ($path === '/dashboard/stats') {
        json_response([
            'apps_count' => count_catalog_apps(),
            'users_count' => $users->countUsers(),
        ]);
    }

    // --- Users (super admin only) ---
    if ($path === '/users/list') {
        $auth->requireSuperAdmin();
        json_response(['data' => $users->listUsers()]);
    }

    if ($path === '/users/create') {
        $auth->requireSuperAdmin();
        try {
            $user = $users->createUser(
                require_body_string($input, 'username'),
                require_body_string($input, 'password')
            );
            json_response(['action' => 'created', 'user' => $user]);
        } catch (InvalidArgumentException $e) {
            json_error($e->getMessage(), 400);
        }
    }

    if ($path === '/users/reset-password') {
        $auth->requireSuperAdmin();
        try {
            $users->resetPassword(
                require_body_int($input, 'id'),
                require_body_string($input, 'new_password')
            );
            json_response(['action' => 'password_reset']);
        } catch (InvalidArgumentException $e) {
            json_error($e->getMessage(), 400);
        }
    }

    if ($path === '/users/delete') {
        $auth->requireSuperAdmin();
        try {
            $users->deleteUser(require_body_int($input, 'id'));
            json_response(['action' => 'deleted']);
        } catch (InvalidArgumentException $e) {
            json_error($e->getMessage(), 400);
        }
    }

    json_error('Not found', 404);
} catch (Throwable $e) {
    error_log('appmanagement fatal: ' . $e->getMessage());
    json_error('Server error: ' . $e->getMessage(), 500);
}
