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

function tmdb_image(?string $path, string $size = 'w500'): ?string
{
    global $config;
    if ($path === null || $path === '') {
        return null;
    }
    return rtrim($config['tmdb_image_base'], '/') . '/' . $size . $path;
}

function query_int(array $source, string $key, int $default, int $min = 1, int $max = PHP_INT_MAX): int
{
    $value = isset($source[$key]) ? (int) $source[$key] : $default;
    return max($min, min($max, $value));
}

function query_string(array $source, string $key): ?string
{
    if (!isset($source[$key])) {
        return null;
    }
    $value = trim((string) $source[$key]);
    return $value === '' ? null : $value;
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
