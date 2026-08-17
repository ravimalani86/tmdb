<?php

declare(strict_types=1);

/**
 * Movflik Home feed — bootstrap (first paint) + per-row APIs.
 *
 * Perf:
 * - Bootstrap runs only trending + new_releases (+ my_list) — not every genre row.
 * - Each catalog row is cached independently (~3 min).
 * - Skips COUNT(*) via include_total false.
 */
final class HomeFeedRepository
{
    private const G_ACTION = 28;
    private const G_COMEDY = 35;
    private const G_THRILLER = 53;
    private const G_DRAMA = 18;
    private const G_CRIME = 80;
    private const G_ROMANCE = 10749;
    private const G_HORROR = 27;
    private const G_ANIMATION = 16;
    private const G_SCIFI = 878;
    private const G_TV_ACTION = 10759;
    private const G_TV_SCIFI = 10765;

    private const PROVIDER_NETFLIX = 8;
    private const PROVIDER_PRIME = 9;
    private const PROVIDER_ZEE5 = 232;
    private const PROVIDER_JIOHOTSTAR = 2336;

    private const CACHE_TTL_SECONDS = 180;

    /** Rows returned by /home/bootstrap (first viewport). */
    public const BOOTSTRAP_ROW_KEYS = [
        'home_slider',
        'trending_movies',
        'trending_tv',
        'top_10',
        'new_releases',
        'my_list',
    ];

    /** Catalog rows loaded lazily via /home/row. */
    public const SECONDARY_ROW_KEYS = [
        'recently_added',
        'international_films',
        'this_month',
        'top_20_series',
        'hindi',
        'tamil',
        'telugu',
        'malayalam',
        'action',
        'thriller',
        'crime',
        'drama',
        'comedy',
        'romance',
        'horror',
        'animation',
        'scifi',
        'on_netflix',
        'jiohotstar',
        'prime_video',
        'zee5',
        'top_rated',
    ];

    /** Slider-only rows available through /home/row. */
    private const SLIDER_ROW_KEYS = [
        'profile_slider',
        'home_slider_anime',
    ];

    public function __construct(
        private MovieRepository $movies,
        private TvRepository $tv,
        private GenreRepository $genres,
        private UserStateRepository $userState,
    ) {
    }

    /**
     * Fast first paint: hero + top rows + genres. Secondary rows omitted.
     *
     * @param string $filter all|movies|tv
     */
    public function buildBootstrap(string $filter, ?string $deviceId, string $country = 'IN'): array
    {
        $deviceId = $deviceId !== null ? trim($deviceId) : '';
        $wantMovies = $filter === 'all' || $filter === 'movies';
        $wantTv = $filter === 'all' || $filter === 'tv';

        $trendingMovies = $wantMovies
            ? $this->cachedRow($filter, $country, 'trending_movies')
            : [];
        $trendingTv = $wantTv
            ? $this->cachedRow($filter, $country, 'trending_tv')
            : [];
        $homeSlider = $this->cachedRow($filter, $country, 'home_slider');
        $newReleases = $this->cachedRow($filter, $country, 'new_releases');
        $top10 = $this->buildTop10($filter, $trendingMovies, $trendingTv);

        $myList = [];
        if ($deviceId !== '') {
            try {
                $listed = $this->userState->listMedia($deviceId, null, 'saved', 1, 20);
                $myList = $listed['data'] ?? [];
            } catch (Throwable $e) {
                $myList = [];
            }
        }

        $rows = [
            'home_slider' => $homeSlider,
            'trending_movies' => $trendingMovies,
            'trending_tv' => $trendingTv,
            'top_10' => $top10,
            'new_releases' => $newReleases,
            'my_list' => $myList,
        ];

        $genreType = ($wantTv && !$wantMovies) ? 'tv' : 'movie';
        $genreList = $this->genres->listGenres($genreType);

        return [
            'filter' => $filter,
            'country' => $country,
            'hero' => $this->pickHeroFromRows($rows, $filter),
            'rows' => $rows,
            'genres' => $genreList['data'] ?? [],
            'secondary_rows' => self::SECONDARY_ROW_KEYS,
        ];
    }

    /**
     * Single home row (cached). Used for progressive loading.
     *
     * @param string $filter all|movies|tv
     */
    public function buildRow(
        string $filter,
        string $rowKey,
        ?string $deviceId = null,
        string $country = 'IN'
    ): array {
        $rowKey = strtolower(trim($rowKey));
        $allowed = array_merge(
            self::BOOTSTRAP_ROW_KEYS,
            self::SECONDARY_ROW_KEYS,
            self::SLIDER_ROW_KEYS
        );
        if (!in_array($rowKey, $allowed, true)) {
            throw new InvalidArgumentException(
                'Invalid row. Use one of: ' . implode(', ', $allowed)
            );
        }

        if ($rowKey === 'my_list') {
            $deviceId = $deviceId !== null ? trim($deviceId) : '';
            $data = [];
            if ($deviceId !== '') {
                try {
                    $listed = $this->userState->listMedia($deviceId, null, 'saved', 1, 20);
                    $data = $listed['data'] ?? [];
                } catch (Throwable $e) {
                    $data = [];
                }
            }
            return [
                'filter' => $filter,
                'country' => $country,
                'row' => $rowKey,
                'data' => $data,
            ];
        }

        if ($rowKey === 'top_10') {
            $wantMovies = $filter === 'all' || $filter === 'movies';
            $wantTv = $filter === 'all' || $filter === 'tv';
            $trendingMovies = $wantMovies
                ? $this->cachedRow($filter, $country, 'trending_movies')
                : [];
            $trendingTv = $wantTv
                ? $this->cachedRow($filter, $country, 'trending_tv')
                : [];
            $data = $this->buildTop10($filter, $trendingMovies, $trendingTv);
            return [
                'filter' => $filter,
                'country' => $country,
                'row' => $rowKey,
                'data' => $data,
            ];
        }

        return [
            'filter' => $filter,
            'country' => $country,
            'row' => $rowKey,
            'data' => $this->cachedRow($filter, $country, $rowKey),
        ];
    }

    /**
     * Legacy full feed (still supported). Uses per-row cache so warm hits are fast.
     *
     * @param string $filter all|movies|tv
     */
    public function buildFeed(string $filter, ?string $deviceId, string $country = 'IN'): array
    {
        $boot = $this->buildBootstrap($filter, $deviceId, $country);
        $rows = $boot['rows'];
        foreach (self::SECONDARY_ROW_KEYS as $key) {
            $rows[$key] = $this->cachedRow($filter, $country, $key);
        }
        $boot['rows'] = $rows;
        $boot['hero'] = $this->pickHeroFromRows($rows, $filter);
        unset($boot['secondary_rows']);
        return $boot;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cachedRow(string $filter, string $country, string $rowKey): array
    {
        $cached = $this->readRowCache($filter, $country, $rowKey);
        if ($cached !== null) {
            return $cached;
        }
        $data = $this->fetchRow($filter, $country, $rowKey);
        $this->writeRowCache($filter, $country, $rowKey, $data);
        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRow(string $filter, string $country, string $rowKey): array
    {
        $wantMovies = $filter === 'all' || $filter === 'movies';
        $wantTv = $filter === 'all' || $filter === 'tv';
        $useTv = $wantTv && !$wantMovies;
        $recent = (new DateTimeImmutable('first day of -6 months'))->format('Y-m-d');

        try {
            if ($rowKey === 'profile_slider') {
                return $this->fetchRecentSlider($filter, 30, true);
            }
            if ($rowKey === 'home_slider') {
                return $this->fetchRecentSlider($filter, 30, false);
            }
            if ($rowKey === 'home_slider_anime') {
                return $this->fetchRecentSlider('all', 30, false, true);
            }
            if ($rowKey === 'recently_added') {
                return $this->fetchMixedReleaseRow(
                    $filter,
                    (new DateTimeImmutable('-30 days'))->format('Y-m-d'),
                    12,
                    true
                );
            }
            if ($rowKey === 'international_films') {
                if ($filter === 'tv') {
                    return [];
                }
                return $this->movies->listMovies($this->baseMovieFilters([
                    'limit' => 12,
                    'original_languages' => ['en', 'ko', 'ja', 'es', 'fr'],
                ]))['data'] ?? [];
            }
            if ($rowKey === 'this_month') {
                return $this->fetchMixedReleaseRow(
                    $filter,
                    (new DateTimeImmutable('first day of this month'))->format('Y-m-d'),
                    12,
                    false
                );
            }
            if ($rowKey === 'top_20_series') {
                if ($filter === 'movies') {
                    return [];
                }
                return $this->tv->listShows($this->baseTvFilters([
                    'limit' => 20,
                ]))['data'] ?? [];
            }
            if ($rowKey === 'trending_movies') {
                if (!$wantMovies) {
                    return [];
                }
                return $this->movies->listMovies($this->baseMovieFilters(['limit' => 15]))['data'] ?? [];
            }
            if ($rowKey === 'trending_tv') {
                if (!$wantTv) {
                    return [];
                }
                return $this->tv->listShows($this->baseTvFilters(['limit' => 15]))['data'] ?? [];
            }
            if ($rowKey === 'new_releases') {
                if ($useTv) {
                    return $this->tv->listShows($this->baseTvFilters([
                        'limit' => 12,
                        'first_air_date_gte' => $recent,
                    ]))['data'] ?? [];
                }
                return $this->movies->listMovies($this->baseMovieFilters([
                    'limit' => 12,
                    'release_date_gte' => $recent,
                ]))['data'] ?? [];
            }

            // Secondary / catalog rows: movie-led on All & Movies; TV-only uses TV ids.
            if ($useTv) {
                return $this->fetchTvCatalogRow($rowKey, $country, $recent);
            }
            return $this->fetchMovieCatalogRow($rowKey, $country, $recent);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Build a mixed movie/TV slider from a strict rolling release window.
     *
     * Profile gate: popularity ordered, portrait poster required.
     * Home: popularity ordered, with a vote floor and backdrop preference.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchRecentSlider(
        string $filter,
        int $days,
        bool $profileGate,
        bool $animeOnly = false
    ): array {
        $since = (new DateTimeImmutable("-{$days} days"))->format('Y-m-d');
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        $wantMovies = $filter !== 'tv';
        $wantTv = $filter !== 'movies';
        $genreIds = $animeOnly ? [self::G_ANIMATION] : null;
        $sort = 'popularity';
        $voteFloor = $profileGate ? null : 20;
        $candidateLimit = 20;

        $movies = [];
        if ($wantMovies) {
            $movies = $this->movies->listMovies($this->baseMovieFilters([
                'limit' => $candidateLimit,
                'sort' => $sort,
                'genre_tmdb_ids' => $genreIds,
                'vote_count_gte' => $voteFloor,
                'release_date_gte' => $since,
                'release_date_lte' => $today,
            ]))['data'] ?? [];
        }

        $shows = [];
        if ($wantTv) {
            $shows = $this->tv->listShows($this->baseTvFilters([
                'limit' => $candidateLimit,
                'sort' => $sort,
                'genre_tmdb_ids' => $genreIds,
                'vote_count_gte' => $voteFloor,
                'first_air_date_gte' => $since,
                'first_air_date_lte' => $today,
            ]))['data'] ?? [];
        }

        $items = array_merge($movies, $shows);
        usort($items, static function (array $a, array $b): int {
            $popularity = ((float) ($b['popularity'] ?? 0))
                <=> ((float) ($a['popularity'] ?? 0));
            if ($popularity !== 0) {
                return $popularity;
            }
            return ((int) ($b['tmdb_id'] ?? 0)) <=> ((int) ($a['tmdb_id'] ?? 0));
        });

        $seen = [];
        $withPreferredArt = [];
        $withFallbackArt = [];
        foreach ($items as $item) {
            $key = ($item['media_type'] ?? '') . ':' . ($item['tmdb_id'] ?? '');
            if ($key === ':' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            if ($profileGate) {
                if (!empty($item['poster_url'])) {
                    $withPreferredArt[] = $item;
                }
                continue;
            }

            if (!empty($item['backdrop_url'])) {
                $withPreferredArt[] = $item;
            } elseif (!empty($item['poster_url'])) {
                $withFallbackArt[] = $item;
            }
        }

        return array_slice(
            array_merge($withPreferredArt, $withFallbackArt),
            0,
            5
        );
    }

    /**
     * Build a mixed movie/TV release row with one global ordering.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchMixedReleaseRow(
        string $filter,
        string $since,
        int $limit,
        bool $sortByDate
    ): array {
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        $wantMovies = $filter === 'all' || $filter === 'movies';
        $wantTv = $filter === 'all' || $filter === 'tv';
        $candidateLimit = max($limit, 20);

        $movies = [];
        if ($wantMovies) {
            $movies = $this->movies->listMovies($this->baseMovieFilters([
                'limit' => $candidateLimit,
                'sort' => $sortByDate ? 'release_date' : 'popularity',
                'release_date_gte' => $since,
                'release_date_lte' => $today,
            ]))['data'] ?? [];
        }

        $shows = [];
        if ($wantTv) {
            $shows = $this->tv->listShows($this->baseTvFilters([
                'limit' => $candidateLimit,
                'sort' => $sortByDate ? 'first_air_date' : 'popularity',
                'first_air_date_gte' => $since,
                'first_air_date_lte' => $today,
            ]))['data'] ?? [];
        }

        $items = array_merge($movies, $shows);
        usort($items, static function (array $a, array $b) use ($sortByDate): int {
            if ($sortByDate) {
                $aDate = (string) ($a['release_date'] ?? $a['first_air_date'] ?? '');
                $bDate = (string) ($b['release_date'] ?? $b['first_air_date'] ?? '');
                $dateOrder = strcmp($bDate, $aDate);
                if ($dateOrder !== 0) {
                    return $dateOrder;
                }
            }

            $popularity = ((float) ($b['popularity'] ?? 0))
                <=> ((float) ($a['popularity'] ?? 0));
            if ($popularity !== 0) {
                return $popularity;
            }
            return ((int) ($b['tmdb_id'] ?? 0)) <=> ((int) ($a['tmdb_id'] ?? 0));
        });

        $seen = [];
        $out = [];
        foreach ($items as $item) {
            $key = ($item['media_type'] ?? '') . ':' . ($item['tmdb_id'] ?? '');
            if ($key === ':' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $item;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchMovieCatalogRow(string $rowKey, string $country, string $recent): array
    {
        $filters = match ($rowKey) {
            'hindi' => $this->baseMovieFilters(['limit' => 12, 'original_languages' => ['hi']]),
            'tamil' => $this->baseMovieFilters(['limit' => 12, 'original_languages' => ['ta']]),
            'telugu' => $this->baseMovieFilters(['limit' => 12, 'original_languages' => ['te']]),
            'malayalam' => $this->baseMovieFilters(['limit' => 12, 'original_languages' => ['ml']]),
            'action' => $this->baseMovieFilters(['limit' => 12, 'genre_tmdb_ids' => [self::G_ACTION]]),
            'thriller' => $this->baseMovieFilters(['limit' => 12, 'genre_tmdb_ids' => [self::G_THRILLER]]),
            'crime' => $this->baseMovieFilters(['limit' => 12, 'genre_tmdb_ids' => [self::G_CRIME]]),
            'drama' => $this->baseMovieFilters(['limit' => 12, 'genre_tmdb_ids' => [self::G_DRAMA]]),
            'comedy' => $this->baseMovieFilters(['limit' => 12, 'genre_tmdb_ids' => [self::G_COMEDY]]),
            'romance' => $this->baseMovieFilters(['limit' => 12, 'genre_tmdb_ids' => [self::G_ROMANCE]]),
            'horror' => $this->baseMovieFilters(['limit' => 12, 'genre_tmdb_ids' => [self::G_HORROR]]),
            'animation' => $this->baseMovieFilters(['limit' => 12, 'genre_tmdb_ids' => [self::G_ANIMATION]]),
            'scifi' => $this->baseMovieFilters(['limit' => 12, 'genre_tmdb_ids' => [self::G_SCIFI]]),
            'on_netflix' => $this->baseMovieFilters([
                'limit' => 12,
                'provider_tmdb_ids' => [self::PROVIDER_NETFLIX],
            ]),
            'jiohotstar' => $this->baseMovieFilters([
                'limit' => 12,
                'provider_tmdb_ids' => [self::PROVIDER_JIOHOTSTAR],
            ]),
            'prime_video' => $this->baseMovieFilters([
                'limit' => 12,
                'provider_tmdb_ids' => [self::PROVIDER_PRIME],
            ]),
            'zee5' => $this->baseMovieFilters([
                'limit' => 12,
                'provider_tmdb_ids' => [self::PROVIDER_ZEE5],
            ]),
            'top_rated' => $this->baseMovieFilters([
                'limit' => 12,
                'vote_average_gte' => 7.5,
                'vote_count_gte' => 500,
            ]),
            default => null,
        };
        if ($filters === null) {
            return [];
        }
        return $this->movies->listMovies($filters)['data'] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchTvCatalogRow(string $rowKey, string $country, string $recent): array
    {
        $filters = match ($rowKey) {
            'hindi' => $this->baseTvFilters(['limit' => 12, 'original_languages' => ['hi']]),
            'tamil' => $this->baseTvFilters(['limit' => 12, 'original_languages' => ['ta']]),
            'telugu' => $this->baseTvFilters(['limit' => 12, 'original_languages' => ['te']]),
            'malayalam' => $this->baseTvFilters(['limit' => 12, 'original_languages' => ['ml']]),
            'action' => $this->baseTvFilters(['limit' => 12, 'genre_tmdb_ids' => [self::G_TV_ACTION]]),
            'thriller' => $this->baseTvFilters(['limit' => 12, 'genre_tmdb_ids' => [self::G_THRILLER]]),
            'crime' => $this->baseTvFilters(['limit' => 12, 'genre_tmdb_ids' => [self::G_CRIME]]),
            'drama' => $this->baseTvFilters(['limit' => 12, 'genre_tmdb_ids' => [self::G_DRAMA]]),
            'comedy' => $this->baseTvFilters(['limit' => 12, 'genre_tmdb_ids' => [self::G_COMEDY]]),
            'romance', 'horror' => null, // TMDB has no TV Romance/Horror genre
            'animation' => $this->baseTvFilters(['limit' => 12, 'genre_tmdb_ids' => [self::G_ANIMATION]]),
            'scifi' => $this->baseTvFilters(['limit' => 12, 'genre_tmdb_ids' => [self::G_TV_SCIFI]]),
            'on_netflix' => $this->baseTvFilters([
                'limit' => 12,
                'provider_tmdb_ids' => [self::PROVIDER_NETFLIX],
            ]),
            'jiohotstar' => $this->baseTvFilters([
                'limit' => 12,
                'provider_tmdb_ids' => [self::PROVIDER_JIOHOTSTAR],
            ]),
            'prime_video' => $this->baseTvFilters([
                'limit' => 12,
                'provider_tmdb_ids' => [self::PROVIDER_PRIME],
            ]),
            'zee5' => $this->baseTvFilters([
                'limit' => 12,
                'provider_tmdb_ids' => [self::PROVIDER_ZEE5],
            ]),
            'top_rated' => $this->baseTvFilters([
                'limit' => 12,
                'vote_average_gte' => 7.5,
                'vote_count_gte' => 500,
            ]),
            default => null,
        };
        if ($filters === null) {
            return [];
        }
        return $this->tv->listShows($filters)['data'] ?? [];
    }

    /**
     * @param list<array<string, mixed>> $trendingMovies
     * @param list<array<string, mixed>> $trendingTv
     * @return list<array<string, mixed>>
     */
    private function buildTop10(string $filter, array $trendingMovies, array $trendingTv): array
    {
        return match ($filter) {
            'movies' => array_slice($trendingMovies, 0, 10),
            'tv' => array_slice($trendingTv, 0, 10),
            default => $this->interleaveLists($trendingMovies, $trendingTv, 10),
        };
    }

    private function cacheDir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'movflik_home_feed';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private function rowCachePath(string $filter, string $country, string $rowKey): string
    {
        return $this->cacheDir()
            . DIRECTORY_SEPARATOR
            . 'row_' . $filter . '_' . strtoupper($country) . '_' . $rowKey . '.json';
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function readRowCache(string $filter, string $country, string $rowKey): ?array
    {
        $path = $this->rowCachePath($filter, $country, $rowKey);
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

    /**
     * @param list<array<string, mixed>> $data
     */
    private function writeRowCache(string $filter, string $country, string $rowKey, array $data): void
    {
        $path = $this->rowCachePath($filter, $country, $rowKey);
        @file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param list<array<string, mixed>> $a
     * @param list<array<string, mixed>> $b
     * @return list<array<string, mixed>>
     */
    private function interleaveLists(array $a, array $b, int $limit): array
    {
        $out = [];
        $seen = [];
        $i = 0;
        $j = 0;
        while (count($out) < $limit && ($i < count($a) || $j < count($b))) {
            if ($i < count($a)) {
                $item = $a[$i++];
                $key = ($item['media_type'] ?? 'movie') . ':' . ($item['tmdb_id'] ?? '');
                if ($key !== ':' && !isset($seen[$key])) {
                    $seen[$key] = true;
                    $out[] = $item;
                }
            }
            if (count($out) >= $limit) {
                break;
            }
            if ($j < count($b)) {
                $item = $b[$j++];
                $key = ($item['media_type'] ?? 'tv') . ':' . ($item['tmdb_id'] ?? '');
                if ($key !== ':' && !isset($seen[$key])) {
                    $seen[$key] = true;
                    $out[] = $item;
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $rows
     */
    private function pickHeroFromRows(array $rows, string $filter = 'all'): ?array
    {
        $pool = [];
        $seen = [];
        $sources = match ($filter) {
            'movies' => array_merge(
                $rows['home_slider'] ?? [],
                $rows['top_10'] ?? [],
                $rows['trending_movies'] ?? []
            ),
            'tv' => array_merge(
                $rows['home_slider'] ?? [],
                $rows['top_10'] ?? [],
                $rows['trending_tv'] ?? []
            ),
            default => array_merge(
                $rows['home_slider'] ?? [],
                $rows['top_10'] ?? [],
                $rows['trending_movies'] ?? [],
                $rows['trending_tv'] ?? []
            ),
        };

        foreach ($sources as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = (string) ($item['media_type'] ?? '');
            if ($filter === 'movies' && $type === 'tv') {
                continue;
            }
            if ($filter === 'tv' && $type === 'movie') {
                continue;
            }
            $key = $type . ':' . ($item['tmdb_id'] ?? '');
            if ($key === ':' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $pool[] = $item;
        }

        if ($pool === []) {
            return null;
        }

        $withBackdrop = array_values(array_filter(
            $pool,
            static fn(array $item): bool => !empty($item['backdrop_url'])
        ));
        $candidates = $withBackdrop !== [] ? $withBackdrop : $pool;

        return $candidates[random_int(0, count($candidates) - 1)];
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
