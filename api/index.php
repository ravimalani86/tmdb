<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/ProvidersConfig.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/MovieRepository.php';
require __DIR__ . '/TvRepository.php';
require __DIR__ . '/GenreRepository.php';
require __DIR__ . '/ProviderRepository.php';
require __DIR__ . '/MediaExtrasRepository.php';

header('Access-Control-Allow-Origin: ' . $config['cors_origin']);
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

try {
    $pdo = Database::connection($config);
    $movies = new MovieRepository($pdo);
    $tv = new TvRepository($pdo);
    $genres = new GenreRepository($pdo);
    $providers = new ProviderRepository($pdo);

    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH) ?? '/';
    $path = rawurldecode($path);

    $path = preg_replace('#^.*?/api#', '', $path) ?? $path;
    $path = '/' . trim($path, '/');

    if ($path === '/' || $path === '') {
        json_response([
            'name' => 'TMDB Local API',
            'version' => '1.2',
            'endpoints' => [
                'GET /movies',
                'GET /movies/{tmdb_id}',
                'GET /movies/{tmdb_id}/credits',
                'GET /movies/{tmdb_id}/providers',
                'GET /movies/{tmdb_id}/similar',
                'GET /movies/{tmdb_id}/videos',
                'GET /movies/{tmdb_id}/images',
                'GET /movies/{tmdb_id}/keywords',
                'GET /movies/{tmdb_id}/recommendations',
                'GET /tv',
                'GET /tv/{tmdb_id}',
                'GET /tv/{tmdb_id}/credits',
                'GET /tv/{tmdb_id}/providers',
                'GET /tv/{tmdb_id}/similar',
                'GET /tv/{tmdb_id}/seasons',
                'GET /tv/{tmdb_id}/videos',
                'GET /tv/{tmdb_id}/images',
                'GET /tv/{tmdb_id}/keywords',
                'GET /tv/{tmdb_id}/recommendations',
                'GET /genres?type=movie|tv',
                'GET /providers?type=movie|tv&country=US',
            ],
        ]);
    }

    if ($path === '/movies') {
        $result = $movies->listMovies([
            'page' => query_int($_GET, 'page', 1),
            'limit' => query_int($_GET, 'limit', 20, 1, 50),
            'sort' => allowed_movie_sort((string) ($_GET['sort'] ?? 'popularity')),
            'order' => allowed_order((string) ($_GET['order'] ?? 'desc')),
            'search' => query_string($_GET, 'search'),
            'genre_tmdb_id' => isset($_GET['genre_id']) ? (int) $_GET['genre_id'] : null,
            'provider_tmdb_id' => isset($_GET['provider_id']) ? (int) $_GET['provider_id'] : null,
            'country' => query_string($_GET, 'country'),
            'spoken_language' => query_string($_GET, 'spoken_language'),
        ]);
        json_response($result);
    }

    if ($path === '/tv') {
        $result = $tv->listShows([
            'page' => query_int($_GET, 'page', 1),
            'limit' => query_int($_GET, 'limit', 20, 1, 50),
            'sort' => allowed_tv_sort((string) ($_GET['sort'] ?? 'popularity')),
            'order' => allowed_order((string) ($_GET['order'] ?? 'desc')),
            'search' => query_string($_GET, 'search'),
            'genre_tmdb_id' => isset($_GET['genre_id']) ? (int) $_GET['genre_id'] : null,
            'provider_tmdb_id' => isset($_GET['provider_id']) ? (int) $_GET['provider_id'] : null,
            'country' => query_string($_GET, 'country'),
            'spoken_language' => query_string($_GET, 'spoken_language'),
        ]);
        json_response($result);
    }

    if ($path === '/genres') {
        $type = allowed_media_type((string) ($_GET['type'] ?? 'movie'));
        json_response($genres->listGenres($type));
    }

    if ($path === '/providers') {
        $type = allowed_media_type((string) ($_GET['type'] ?? 'movie'));
        json_response($providers->listProviders(query_string($_GET, 'country'), $type));
    }

    if (preg_match('#^/movies/(\d+)$#', $path, $m)) {
        $detail = $movies->getMovieDetail((int) $m[1]);
        if ($detail === null) {
            json_error('Movie not found', 404);
        }
        json_response($detail);
    }

    if (preg_match('#^/movies/(\d+)/credits$#', $path, $m)) {
        $result = $movies->getCastByTmdbId((int) $m[1]);
        if ($result === null) {
            json_error('Movie not found', 404);
        }
        json_response($result);
    }

    if (preg_match('#^/movies/(\d+)/providers$#', $path, $m)) {
        $result = $movies->getProvidersByTmdbId((int) $m[1]);
        if ($result === null) {
            json_error('Movie not found', 404);
        }
        json_response($result);
    }

    if (preg_match('#^/movies/(\d+)/similar$#', $path, $m)) {
        $result = $movies->getSimilarByTmdbId((int) $m[1]);
        if ($result === null) {
            json_error('Movie not found', 404);
        }
        json_response($result);
    }

    if (preg_match('#^/movies/(\d+)/videos$#', $path, $m)) {
        $result = $movies->getVideosByTmdbId((int) $m[1]);
        if ($result === null) {
            json_error('Movie not found', 404);
        }
        json_response($result);
    }

    if (preg_match('#^/movies/(\d+)/images$#', $path, $m)) {
        $result = $movies->getImagesByTmdbId((int) $m[1]);
        if ($result === null) {
            json_error('Movie not found', 404);
        }
        json_response($result);
    }

    if (preg_match('#^/movies/(\d+)/keywords$#', $path, $m)) {
        $result = $movies->getKeywordsByTmdbId((int) $m[1]);
        if ($result === null) {
            json_error('Movie not found', 404);
        }
        json_response($result);
    }

    if (preg_match('#^/movies/(\d+)/recommendations$#', $path, $m)) {
        $result = $movies->getRecommendationsByTmdbId((int) $m[1]);
        if ($result === null) {
            json_error('Movie not found', 404);
        }
        json_response($result);
    }

    if (preg_match('#^/tv/(\d+)$#', $path, $m)) {
        $detail = $tv->getShowDetail((int) $m[1]);
        if ($detail === null) {
            json_error('TV show not found', 404);
        }
        json_response($detail);
    }

    if (preg_match('#^/tv/(\d+)/credits$#', $path, $m)) {
        $result = $tv->getCastByTmdbId((int) $m[1]);
        if ($result === null) {
            json_error('TV show not found', 404);
        }
        json_response($result);
    }

    if (preg_match('#^/tv/(\d+)/providers$#', $path, $m)) {
        $result = $tv->getProvidersByTmdbId((int) $m[1]);
        if ($result === null) {
            json_error('TV show not found', 404);
        }
        json_response($result);
    }

    if (preg_match('#^/tv/(\d+)/similar$#', $path, $m)) {
        $result = $tv->getSimilarByTmdbId((int) $m[1]);
        if ($result === null) {
            json_error('TV show not found', 404);
        }
        json_response($result);
    }

    if (preg_match('#^/tv/(\d+)/seasons$#', $path, $m)) {
        $result = $tv->getSeasonsByTmdbId((int) $m[1]);
        if ($result === null) {
            json_error('TV show not found', 404);
        }
        json_response($result);
    }

    if (preg_match('#^/tv/(\d+)/videos$#', $path, $m)) {
        $result = $tv->getVideosByTmdbId((int) $m[1]);
        if ($result === null) {
            json_error('TV show not found', 404);
        }
        json_response($result);
    }

    if (preg_match('#^/tv/(\d+)/images$#', $path, $m)) {
        $result = $tv->getImagesByTmdbId((int) $m[1]);
        if ($result === null) {
            json_error('TV show not found', 404);
        }
        json_response($result);
    }

    if (preg_match('#^/tv/(\d+)/keywords$#', $path, $m)) {
        $result = $tv->getKeywordsByTmdbId((int) $m[1]);
        if ($result === null) {
            json_error('TV show not found', 404);
        }
        json_response($result);
    }

    if (preg_match('#^/tv/(\d+)/recommendations$#', $path, $m)) {
        $result = $tv->getRecommendationsByTmdbId((int) $m[1]);
        if ($result === null) {
            json_error('TV show not found', 404);
        }
        json_response($result);
    }

    json_error('Endpoint not found', 404);
} catch (Throwable $e) {
    json_error('Server error: ' . $e->getMessage(), 500);
}
