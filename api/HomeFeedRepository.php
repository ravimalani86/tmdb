<?php

declare(strict_types=1);

/**
 * Single-call Home feed for Metflix (hero + CTR rows).
 *
 * Performance notes:
 * - Skips COUNT(*) (`include_total` false) — Home only needs row items.
 * - Skips provider+country joins (very slow under sync).
 * - Caches shared catalog (no device overlays) for ~90s.
 * - My List is always loaded fresh per device_id.
 */
final class HomeFeedRepository
{
    private const GENRE_ACTION = 28;
    private const GENRE_COMEDY = 35;
    private const CACHE_TTL_SECONDS = 90;

    public function __construct(
        private MovieRepository $movies,
        private TvRepository $tv,
        private GenreRepository $genres,
        private UserStateRepository $userState,
    ) {
    }

    /**
     * @param string $filter all|movies|tv
     */
    public function buildFeed(string $filter, ?string $deviceId, string $country = 'IN'): array
    {
        $deviceId = $deviceId !== null ? trim($deviceId) : '';
        $feed = $this->readCatalogCache($filter, $country);
        if ($feed === null) {
            $feed = $this->buildCatalog($filter, $country);
            $this->writeCatalogCache($filter, $country, $feed);
        }

        $myList = ['data' => []];
        if ($deviceId !== '') {
            try {
                $myList = $this->userState->listMedia($deviceId, null, 'saved', 1, 20);
            } catch (Throwable $e) {
                $myList = ['data' => []];
            }
        }
        $feed['rows']['my_list'] = $myList['data'] ?? [];

        return $feed;
    }

    private function buildCatalog(string $filter, string $country): array
    {
        $wantMovies = $filter === 'all' || $filter === 'movies';
        $wantTv = $filter === 'all' || $filter === 'tv';

        $recent = (new DateTimeImmutable('first day of -6 months'))->format('Y-m-d');
        $empty = ['data' => []];

        $safeMovie = function (array $filters) use ($empty): array {
            try {
                return $this->movies->listMovies($filters);
            } catch (Throwable $e) {
                return $empty;
            }
        };
        $safeTv = function (array $filters) use ($empty): array {
            try {
                return $this->tv->listShows($filters);
            } catch (Throwable $e) {
                return $empty;
            }
        };

        // No device_id here — avoids per-user joins; My List is merged later.
        $trendingMovies = $wantMovies
            ? $safeMovie($this->baseMovieFilters(['limit' => 15]))
            : $empty;

        $trendingTv = $wantTv
            ? $safeTv($this->baseTvFilters(['limit' => 15]))
            : $empty;

        if ($wantMovies) {
            $newReleases = $safeMovie($this->baseMovieFilters([
                'limit' => 12,
                'sort' => 'popularity',
                'release_date_gte' => $recent,
            ]));
            $hindi = $safeMovie($this->baseMovieFilters([
                'limit' => 12,
                'original_languages' => ['hi'],
            ]));
            $action = $safeMovie($this->baseMovieFilters([
                'limit' => 12,
                'genre_tmdb_ids' => [self::GENRE_ACTION],
            ]));
            $comedy = $safeMovie($this->baseMovieFilters([
                'limit' => 12,
                'genre_tmdb_ids' => [self::GENRE_COMEDY],
            ]));
            $onNetflix = $empty;
            $topRated = $safeMovie($this->baseMovieFilters([
                'limit' => 12,
                'sort' => 'popularity',
                'vote_average_gte' => 7.5,
                'vote_count_gte' => 500,
            ]));
            $top10Source = $trendingMovies['data'];
        } else {
            $newReleases = $safeTv($this->baseTvFilters([
                'limit' => 12,
                'sort' => 'popularity',
                'first_air_date_gte' => $recent,
            ]));
            $hindi = $safeTv($this->baseTvFilters([
                'limit' => 12,
                'original_languages' => ['hi'],
            ]));
            $action = $safeTv($this->baseTvFilters([
                'limit' => 12,
                'genre_tmdb_ids' => [self::GENRE_ACTION],
            ]));
            $comedy = $safeTv($this->baseTvFilters([
                'limit' => 12,
                'genre_tmdb_ids' => [self::GENRE_COMEDY],
            ]));
            $onNetflix = $empty;
            $topRated = $safeTv($this->baseTvFilters([
                'limit' => 12,
                'sort' => 'popularity',
                'vote_average_gte' => 7.5,
                'vote_count_gte' => 500,
            ]));
            $top10Source = $trendingTv['data'];
        }

        $genreType = ($wantTv && !$wantMovies) ? 'tv' : 'movie';
        $genreList = $this->genres->listGenres($genreType);
        $hero = $this->pickHero($trendingMovies['data'] ?? [], $trendingTv['data'] ?? []);

        return [
            'filter' => $filter,
            'country' => $country,
            'hero' => $hero,
            'rows' => [
                'trending_movies' => $trendingMovies['data'] ?? [],
                'trending_tv' => $trendingTv['data'] ?? [],
                'top_10' => array_slice($top10Source ?? [], 0, 10),
                'new_releases' => $newReleases['data'] ?? [],
                'hindi' => $hindi['data'] ?? [],
                'action' => $action['data'] ?? [],
                'comedy' => $comedy['data'] ?? [],
                'on_netflix' => $onNetflix['data'] ?? [],
                'top_rated' => $topRated['data'] ?? [],
                'my_list' => [],
            ],
            'genres' => $genreList['data'],
        ];
    }

    private function cachePath(string $filter, string $country): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'metflix_home_feed';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . DIRECTORY_SEPARATOR . 'feed_' . $filter . '_' . strtoupper($country) . '.json';
    }

    private function readCatalogCache(string $filter, string $country): ?array
    {
        $path = $this->cachePath($filter, $country);
        if (!is_file($path)) {
            return null;
        }
        if ((time() - (int) filemtime($path)) > self::CACHE_TTL_SECONDS) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function writeCatalogCache(string $filter, string $country, array $feed): void
    {
        $path = $this->cachePath($filter, $country);
        @file_put_contents($path, json_encode($feed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function pickHero(array $movies, array $tv): ?array
    {
        foreach (array_merge($movies, $tv) as $item) {
            if (!empty($item['backdrop_url'])) {
                return $item;
            }
        }
        return $movies[0] ?? $tv[0] ?? null;
    }

    private function baseMovieFilters(array $overrides): array
    {
        return array_merge([
            'page' => 1,
            'limit' => 12,
            'sort' => 'popularity',
            'order' => 'desc',
            'search' => null,
            'genre_tmdb_ids' => null,
            'provider_tmdb_ids' => null,
            'countries' => null,
            'spoken_language' => null,
            'original_languages' => null,
            'vote_average_gte' => null,
            'vote_count_gte' => null,
            'release_date_gte' => null,
            'release_date_lte' => null,
            'released_only' => true,
            'device_id' => null,
            'saved_only' => false,
            'watched_only' => false,
            'include_total' => false,
        ], $overrides);
    }

    private function baseTvFilters(array $overrides): array
    {
        return array_merge([
            'page' => 1,
            'limit' => 12,
            'sort' => 'popularity',
            'order' => 'desc',
            'search' => null,
            'genre_tmdb_ids' => null,
            'provider_tmdb_ids' => null,
            'countries' => null,
            'spoken_language' => null,
            'original_languages' => null,
            'vote_average_gte' => null,
            'vote_count_gte' => null,
            'first_air_date_gte' => null,
            'first_air_date_lte' => null,
            'released_only' => true,
            'device_id' => null,
            'saved_only' => false,
            'watched_only' => false,
            'include_total' => false,
        ], $overrides);
    }
}
