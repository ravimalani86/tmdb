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

/** Echo a pre-encoded JSON string without wrapping or re-encoding. */
function json_raw_response(string $json, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo $json === '' ? '{}' : $json;
    exit;
}

function tmdb_image(?string $path, string $size = 'w500'): ?string
{
    global $config;
    if ($path === null || $path === '') {
        return null;
    }
    return rtrim($config['tmdb_image_base'], '/') . '/' . $size . $path;
}

/** Public site root URL, e.g. http://localhost/tmdb or https://tmdb.example.com */
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

    // /tmdb/api/index.php → /tmdb
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $apiDir = dirname($script);
    $basePath = dirname($apiDir);
    if ($basePath === '/' || $basePath === '.' || $basePath === '\\') {
        $basePath = '';
    }

    return $scheme . '://' . $host . $basePath;
}

/** Prefer local logos/{tmdb_id}.png; fall back to TMDB logo path. */
function provider_logo_url(int $tmdbId, ?string $tmdbLogoPath = null): ?string
{
    if ($tmdbId > 0) {
        $localFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logos' . DIRECTORY_SEPARATOR . $tmdbId . '.png';
        if (is_readable($localFile)) {
            return app_public_base() . '/logos/' . $tmdbId . '.png';
        }
    }

    return tmdb_image($tmdbLogoPath, 'w45');
}

function query_int(array $source, string $key, int $default, int $min = 1, int $max = PHP_INT_MAX): int
{
    $value = isset($source[$key]) ? (int) $source[$key] : $default;
    return max($min, min($max, $value));
}

/** @return list<string> */
function allowed_sync_sources(): array
{
    return ['changes', 'discover', 'credits', 'backfill'];
}

function is_allowed_sync_source(string $source): bool
{
    return in_array(strtolower($source), allowed_sync_sources(), true);
}

function query_string(array $source, string $key): ?string
{
    if (!isset($source[$key])) {
        return null;
    }
    $value = trim((string) $source[$key]);
    return $value === '' ? null : $value;
}

function query_bool(array $source, string $key, bool $default = false): bool
{
    if (!array_key_exists($key, $source)) {
        return $default;
    }

    $value = $source[$key];
    if (is_bool($value)) {
        return $value;
    }
    if ($value === 1 || $value === 0 || $value === '1' || $value === '0') {
        return (bool) $value;
    }

    return $default;
}

/**
 * Accepts "IN" or ["IN", "US"]. Returns unique uppercase country codes, or null.
 *
 * @return list<string>|null
 */
function query_countries(array $source, string $key): ?array
{
    if (!array_key_exists($key, $source) || $source[$key] === null) {
        return null;
    }

    $raw = $source[$key];
    if (is_string($raw) || is_numeric($raw)) {
        $raw = [$raw];
    }
    if (!is_array($raw)) {
        return null;
    }

    $countries = [];
    foreach ($raw as $item) {
        if (!is_string($item) && !is_numeric($item)) {
            continue;
        }
        $code = strtoupper(trim((string) $item));
        if ($code === '') {
            continue;
        }
        $countries[$code] = $code;
    }

    return $countries === [] ? null : array_values($countries);
}

/**
 * Accepts 28 or [28, 35]. Returns unique positive ints, or null.
 *
 * @return list<int>|null
 */
function query_ids(array $source, string $key): ?array
{
    if (!array_key_exists($key, $source) || $source[$key] === null) {
        return null;
    }

    $raw = $source[$key];
    if (is_int($raw) || is_float($raw) || (is_string($raw) && is_numeric($raw))) {
        $raw = [$raw];
    }
    if (!is_array($raw)) {
        return null;
    }

    $ids = [];
    foreach ($raw as $item) {
        if (!is_int($item) && !is_float($item) && !(is_string($item) && is_numeric($item))) {
            continue;
        }
        $id = (int) $item;
        if ($id <= 0) {
            continue;
        }
        $ids[$id] = $id;
    }

    return $ids === [] ? null : array_values($ids);
}

/**
 * Accepts "hi", "hi|en|te", or ["hi","en","te"]. Returns unique lowercase codes, or null.
 *
 * @return list<string>|null
 */
function query_languages(array $source, string $key): ?array
{
    if (!array_key_exists($key, $source) || $source[$key] === null) {
        return null;
    }

    $raw = $source[$key];
    if (is_string($raw)) {
        $raw = preg_split('/[|,]/', $raw) ?: [];
    }
    if (!is_array($raw)) {
        return null;
    }

    $langs = [];
    foreach ($raw as $item) {
        if (!is_string($item) && !is_numeric($item)) {
            continue;
        }
        $code = strtolower(trim((string) $item));
        if ($code === '') {
            continue;
        }
        $langs[$code] = $code;
    }

    return $langs === [] ? null : array_values($langs);
}

function query_float(array $source, string $key): ?float
{
    if (!array_key_exists($key, $source) || $source[$key] === null || $source[$key] === '') {
        return null;
    }
    if (!is_numeric($source[$key])) {
        return null;
    }
    return (float) $source[$key];
}

function query_optional_int(array $source, string $key): ?int
{
    if (!array_key_exists($key, $source) || $source[$key] === null || $source[$key] === '') {
        return null;
    }
    if (!is_numeric($source[$key])) {
        return null;
    }
    return (int) $source[$key];
}

/** YYYY-MM-DD or null. */
function query_date(array $source, string $key): ?string
{
    $value = query_string($source, $key);
    if ($value === null) {
        return null;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }
    return $value;
}

/**
 * @param list<int|string> $values
 * @param array<string, mixed> $params
 */
function bind_in_list(string $prefix, array $values, array &$params): string
{
    $placeholders = [];
    foreach ($values as $i => $value) {
        $key = $prefix . '_' . $i;
        $placeholders[] = ':' . $key;
        $params[$key] = $value;
    }
    return implode(', ', $placeholders);
}

/**
 * Fast provider/country filter without joining watch_providers in the hot path.
 * Resolves TMDB provider ids to internal ids, then filters media_watch_providers.
 *
 * @param list<int>|null $providerTmdbIds
 * @param list<string>|null $countries
 * @param array<string, mixed> $params
 * @return array{join_sql: string, where_sql: string}|null null when result must be empty
 */
function build_provider_media_filter(
    PDO $db,
    string $mediaType,
    string $mediaAlias,
    ?array $providerTmdbIds,
    ?array $countries,
    array &$params,
): ?array {
    $providerConds = ["mwp.media_type = " . $db->quote($mediaType)];

    if (ProvidersConfig::isActive()) {
        $providerConds[] = 'mwp.provider_type = ' . $db->quote(ProvidersConfig::monetization());
        $byCountry = ProvidersConfig::allowedTmdbIdsByCountry($countries);
        if ($byCountry === []) {
            return null;
        }

        $neededTmdbIds = [];
        foreach ($byCountry as $tmdbIds) {
            foreach ($tmdbIds as $tmdbId) {
                $neededTmdbIds[$tmdbId] = $tmdbId;
            }
        }
        if ($providerTmdbIds !== null) {
            $wanted = [];
            foreach ($providerTmdbIds as $tmdbId) {
                $wanted[(int) $tmdbId] = true;
            }
            $neededTmdbIds = array_filter(
                $neededTmdbIds,
                static fn(int $id): bool => isset($wanted[$id])
            );
            if ($neededTmdbIds === []) {
                return null;
            }
            foreach ($byCountry as $country => $tmdbIds) {
                $byCountry[$country] = array_values(array_filter(
                    $tmdbIds,
                    static fn(int $id): bool => isset($wanted[$id])
                ));
                if ($byCountry[$country] === []) {
                    unset($byCountry[$country]);
                }
            }
            if ($byCountry === []) {
                return null;
            }
        }

        $idMap = resolve_watch_provider_ids($db, array_values($neededTmdbIds));
        $regionParts = [];
        $ri = 0;
        foreach ($byCountry as $country => $tmdbIds) {
            $internalIds = [];
            foreach ($tmdbIds as $tmdbId) {
                if (isset($idMap[$tmdbId])) {
                    $internalIds[] = $idMap[$tmdbId];
                }
            }
            if ($internalIds === []) {
                continue;
            }
            $countryKey = 'p_country_' . $ri;
            $params[$countryKey] = $country;
            $inList = bind_in_list('p_id_' . $ri, $internalIds, $params);
            $regionParts[] = "(mwp.country_code = :{$countryKey} AND mwp.provider_id IN ({$inList}))";
            $ri++;
        }
        if ($regionParts === []) {
            return null;
        }
        $providerConds[] = '(' . implode(' OR ', $regionParts) . ')';
    } else {
        if ($countries !== null) {
            $providerConds[] = 'mwp.country_code IN (' . bind_in_list('country', $countries, $params) . ')';
        }
        if ($providerTmdbIds !== null) {
            $idMap = resolve_watch_provider_ids($db, $providerTmdbIds);
            $internalIds = array_values($idMap);
            if ($internalIds === []) {
                return null;
            }
            $providerConds[] = 'mwp.provider_id IN (' . bind_in_list('provider_id', $internalIds, $params) . ')';
        }
    }

    // EXISTS stops early under ORDER BY … LIMIT (avoids materializing DISTINCT
    // over millions of media_watch_providers rows).
    $existsSql = 'EXISTS (SELECT 1 FROM media_watch_providers mwp WHERE mwp.media_id = '
        . $mediaAlias . '.id AND ' . implode(' AND ', $providerConds) . ')';

    return [
        'join_sql' => '',
        'where_sql' => $existsSql,
    ];
}

/**
 * @param list<int> $tmdbIds
 * @return array<int, int> tmdb_id => internal id
 */
function resolve_watch_provider_ids(PDO $db, array $tmdbIds): array
{
    $tmdbIds = array_values(array_unique(array_map('intval', $tmdbIds)));
    if ($tmdbIds === []) {
        return [];
    }
    $params = [];
    $inList = bind_in_list('wp_tmdb', $tmdbIds, $params);
    $stmt = $db->prepare("SELECT id, tmdb_id FROM watch_providers WHERE tmdb_id IN ({$inList})");
    $stmt->execute($params);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int) $row['tmdb_id']] = (int) $row['id'];
    }
    return $map;
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

function require_body_bool(array $source, string $key): bool
{
    if (!array_key_exists($key, $source)) {
        json_error("Bad request: missing {$key}", 400);
    }

    $value = $source[$key];
    if (is_bool($value)) {
        return $value;
    }
    if ($value === 1 || $value === 0 || $value === '1' || $value === '0') {
        return (bool) $value;
    }

    json_error("Bad request: invalid {$key}", 400);
}

function allowed_movie_sort(string $sort): string
{
    return match ($sort) {
        'vote_average', 'release_date', 'popularity', 'title' => $sort,
        default => 'popularity',
    };
}

function allowed_tv_sort(string $sort): string
{
    return match ($sort) {
        'vote_average', 'first_air_date', 'popularity', 'name' => $sort,
        default => 'popularity',
    };
}

function allowed_media_type(string $type): string
{
    return $type === 'tv' ? 'tv' : 'movie';
}

function allowed_order(string $order): string
{
    return strtolower($order) === 'asc' ? 'ASC' : 'DESC';
}

function video_url(?string $site, ?string $key): ?string
{
    if ($key === null || $key === '') {
        return null;
    }
    return match (strtolower($site ?? '')) {
        'youtube' => 'https://www.youtube.com/watch?v=' . $key,
        'vimeo' => 'https://vimeo.com/' . $key,
        default => null,
    };
}
