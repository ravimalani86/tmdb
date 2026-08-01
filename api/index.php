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
require __DIR__ . '/UserStateRepository.php';
require __DIR__ . '/HomeFeedRepository.php';

header('Access-Control-Allow-Origin: ' . $config['cors_origin']);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

try {
    // API key auth (simple security for Flutter apps).
    $expectedApiKey = (string) ($config['api_key'] ?? '');
    $providedApiKey = (string) ($_SERVER['HTTP_X_API_KEY'] ?? '');
    if ($expectedApiKey === '' || $providedApiKey === '' || !hash_equals($expectedApiKey, $providedApiKey)) {
        json_error('Unauthorized', 401);
    }

    // Parse JSON body. List endpoints will use these fields instead of query params.
    $rawBody = file_get_contents('php://input');
    $input = [];
    if ($rawBody !== false && trim($rawBody) !== '') {
        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            json_error('Bad request: invalid JSON body', 400);
        }
        $input = $decoded;
    }

    $pdo = Database::connection($config);
    $movies = new MovieRepository($pdo);
    $tv = new TvRepository($pdo);
    $genres = new GenreRepository($pdo);
    $providers = new ProviderRepository($pdo);
    $userState = new UserStateRepository($pdo);

    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH) ?? '/';
    $path = rawurldecode($path);

    $path = preg_replace('#^.*?/api#', '', $path) ?? $path;
    $path = '/' . trim($path, '/');

    if ($path === '/' || $path === '') {
        json_response([
            'name' => 'TMDB Local API',
            'version' => '1.5',
            'endpoints' => [
                'POST /movies',
                'POST /movies/{tmdb_id}',
                'POST /movies/{tmdb_id}/credits',
                'POST /movies/{tmdb_id}/providers',
                'POST /movies/{tmdb_id}/similar',
                'POST /movies/{tmdb_id}/videos',
                'POST /movies/{tmdb_id}/images',
                'POST /movies/{tmdb_id}/keywords',
                'POST /movies/{tmdb_id}/recommendations',
                'POST /tv',
                'POST /tv/{tmdb_id}',
                'POST /tv/{tmdb_id}/credits',
                'POST /tv/{tmdb_id}/providers',
                'POST /tv/{tmdb_id}/similar',
                'POST /tv/{tmdb_id}/seasons',
                'POST /tv/{tmdb_id}/season/{season_number}/episode/{episode_number}',
                'POST /tv/{tmdb_id}/videos',
                'POST /tv/{tmdb_id}/images',
                'POST /tv/{tmdb_id}/keywords',
                'POST /tv/{tmdb_id}/recommendations',
                'POST /home/feed',
                'POST /genres',
                'POST /providers',
                'POST /user-state/watch',
                'POST /user-state/save-for-later',
                'POST /user-state/list',
            ],
        ]);
    }

    if ($path === '/home/feed') {
        $filterRaw = strtolower(trim((string) ($input['filter'] ?? 'all')));
        $allowed = ['all', 'movies', 'tv'];
        if (!in_array($filterRaw, $allowed, true)) {
            json_error('Invalid filter. Use all, movies, or tv', 400);
        }
        $countries = query_countries($input, 'country') ?? ['IN'];
        $country = strtoupper((string) ($countries[0] ?? 'IN'));
        $homeFeed = new HomeFeedRepository($movies, $tv, $genres, $userState);
        json_response($homeFeed->buildFeed(
            $filterRaw,
            query_string($input, 'device_id'),
            $country
        ));
    }

    if ($path === '/movies') {
        $savedOnly = query_bool($input, 'saved_only');
        $watchedOnly = query_bool($input, 'watched_only');
        $deviceId = query_string($input, 'device_id');
        if (($savedOnly || $watchedOnly) && ($deviceId === null || $deviceId === '')) {
            json_error('device_id is required when saved_only or watched_only is true', 400);
        }
        $result = $movies->listMovies([
            'page' => query_int($input, 'page', 1),
            'limit' => query_int($input, 'limit', 20, 1, 50),
            'sort' => allowed_movie_sort((string) ($input['sort'] ?? 'popularity')),
            'order' => allowed_order((string) ($input['order'] ?? 'desc')),
            'search' => query_string($input, 'search'),
            'genre_tmdb_ids' => query_ids($input, 'genre_id'),
            'provider_tmdb_ids' => query_ids($input, 'provider_id'),
            'countries' => query_countries($input, 'country'),
            'spoken_language' => query_string($input, 'spoken_language'),
            'original_languages' => query_languages($input, 'original_language'),
            'vote_average_gte' => query_float($input, 'vote_average_gte'),
            'vote_count_gte' => query_optional_int($input, 'vote_count_gte'),
            'release_date_gte' => query_date($input, 'release_date_gte'),
            'release_date_lte' => query_date($input, 'release_date_lte'),
            'released_only' => query_bool($input, 'released_only'),
            'device_id' => $deviceId,
            'saved_only' => $savedOnly,
            'watched_only' => $watchedOnly,
            'include_total' => query_bool($input, 'include_total', true),
        ]);
        json_response($result);
    }

    if ($path === '/tv') {
        $savedOnly = query_bool($input, 'saved_only');
        $watchedOnly = query_bool($input, 'watched_only');
        $deviceId = query_string($input, 'device_id');
        if (($savedOnly || $watchedOnly) && ($deviceId === null || $deviceId === '')) {
            json_error('device_id is required when saved_only or watched_only is true', 400);
        }
        $result = $tv->listShows([
            'page' => query_int($input, 'page', 1),
            'limit' => query_int($input, 'limit', 20, 1, 50),
            'sort' => allowed_tv_sort((string) ($input['sort'] ?? 'popularity')),
            'order' => allowed_order((string) ($input['order'] ?? 'desc')),
            'search' => query_string($input, 'search'),
            'genre_tmdb_ids' => query_ids($input, 'genre_id'),
            'provider_tmdb_ids' => query_ids($input, 'provider_id'),
            'countries' => query_countries($input, 'country'),
            'spoken_language' => query_string($input, 'spoken_language'),
            'original_languages' => query_languages($input, 'original_language'),
            'vote_average_gte' => query_float($input, 'vote_average_gte'),
            'vote_count_gte' => query_optional_int($input, 'vote_count_gte'),
            'first_air_date_gte' => query_date($input, 'first_air_date_gte'),
            'first_air_date_lte' => query_date($input, 'first_air_date_lte'),
            'released_only' => query_bool($input, 'released_only'),
            'device_id' => $deviceId,
            'saved_only' => $savedOnly,
            'watched_only' => $watchedOnly,
            'include_total' => query_bool($input, 'include_total', true),
        ]);
        json_response($result);
    }

    if ($path === '/genres') {
        $type = allowed_media_type((string) ($input['type'] ?? 'movie'));
        json_response($genres->listGenres($type));
    }

    if ($path === '/providers') {
        $type = allowed_media_type((string) ($input['type'] ?? 'movie'));
        json_response($providers->listProviders(query_countries($input, 'country'), $type));
    }

    if ($path === '/user-state/watch') {
        $deviceId = require_body_string($input, 'device_id');
        $mediaType = allowed_media_type(require_body_string($input, 'media_type'));
        $tmdbId = require_body_int($input, 'tmdb_id');
        $isWatched = require_body_bool($input, 'is_watched');

        $exists = $mediaType === 'movie'
            ? $movies->findByTmdbId($tmdbId) !== null
            : $tv->findByTmdbId($tmdbId) !== null;
        if (!$exists) {
            json_error(ucfirst($mediaType) . ' not found', 404);
        }

        json_response($userState->setWatchState($deviceId, $mediaType, $tmdbId, $isWatched));
    }

    if ($path === '/user-state/save-for-later') {
        $deviceId = require_body_string($input, 'device_id');
        $mediaType = allowed_media_type(require_body_string($input, 'media_type'));
        $tmdbId = require_body_int($input, 'tmdb_id');
        $isSavedForLater = require_body_bool($input, 'is_saved_for_later');

        $exists = $mediaType === 'movie'
            ? $movies->findByTmdbId($tmdbId) !== null
            : $tv->findByTmdbId($tmdbId) !== null;
        if (!$exists) {
            json_error(ucfirst($mediaType) . ' not found', 404);
        }

        json_response($userState->setSaveForLaterState($deviceId, $mediaType, $tmdbId, $isSavedForLater));
    }

    if ($path === '/user-state/list') {
        $deviceId = require_body_string($input, 'device_id');
        $filterRaw = strtolower(trim((string) ($input['filter'] ?? 'saved')));
        $allowedFilters = ['saved', 'watched', 'all'];
        if (!in_array($filterRaw, $allowedFilters, true)) {
            json_error('Invalid filter. Use saved, watched, or all', 400);
        }

        $mediaTypeRaw = query_string($input, 'media_type');
        $mediaType = null;
        if ($mediaTypeRaw !== null) {
            $mediaType = allowed_media_type($mediaTypeRaw);
        }

        json_response($userState->listMedia(
            $deviceId,
            $mediaType,
            $filterRaw,
            query_int($input, 'page', 1),
            query_int($input, 'limit', 20, 1, 50)
        ));
    }

    if (preg_match('#^/movies/(\d+)$#', $path, $m)) {
        $detail = $movies->getMovieDetail((int) $m[1], query_string($input, 'device_id'));
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
        $detail = $tv->getShowDetail((int) $m[1], query_string($input, 'device_id'));
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

    if (preg_match('#^/tv/(\d+)/season/(\d+)/episode/(\d+)$#', $path, $m)) {
        $result = $tv->getEpisodeDetail((int) $m[1], (int) $m[2], (int) $m[3]);
        if ($result === null) {
            json_error('Episode not found', 404);
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
    // Don't leak internal errors to clients.
    error_log('API server error: ' . $e->getMessage());
    json_error('Server error', 500);
}
