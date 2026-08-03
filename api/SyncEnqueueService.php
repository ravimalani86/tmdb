<?php

declare(strict_types=1);

/**
 * Fill media_sync_queue for IST calendar day (providers_config + TMDB changes).
 */
final class SyncEnqueueService
{
    public function __construct(
        private PDO $db,
        private TmdbClient $tmdb,
        private SyncMediaWriter $writer,
        private array $config,
    ) {
    }

    public function enqueue(?string $syncDay = null): array
    {
        $day = $syncDay ?: $this->todayIst();
        $jobs = $this->providerJobs();
        if ($jobs === []) {
            throw new RuntimeException('providers_config.json missing or empty');
        }

        $stats = [
            'sync_day' => $day,
            'movie_enqueued' => 0,
            'tv_enqueued' => 0,
            'person_enqueued' => 0,
            'movie_skipped' => 0,
            'tv_skipped' => 0,
            'person_skipped' => 0,
        ];

        $movieDb = $this->writer->existingMovieTmdbIdSet();
        $tvDb = $this->writer->existingTvTmdbIdSet();

        // Movies: changes ∩ DB
        foreach ($this->tmdb->paginateResults('movie/changes', [
            'start_date' => $day,
            'end_date' => $day,
        ]) as $row) {
            $tid = (int) ($row['id'] ?? 0);
            if ($tid <= 0) {
                continue;
            }
            if (!isset($movieDb[$tid])) {
                $stats['movie_skipped']++;
                continue;
            }
            if ($this->enqueueRow('movie', $tid, $day, 'changes')) {
                $stats['movie_enqueued']++;
            } else {
                $stats['movie_skipped']++;
            }
        }

        // Movies: discover release today on providers
        foreach ($this->discoverIds('movie', $day, $jobs) as $tid) {
            if ($this->enqueueRow('movie', $tid, $day, 'discover')) {
                $stats['movie_enqueued']++;
                $movieDb[$tid] = true;
            } else {
                $stats['movie_skipped']++;
            }
        }

        // TV: changes ∩ DB
        foreach ($this->tmdb->paginateResults('tv/changes', [
            'start_date' => $day,
            'end_date' => $day,
        ]) as $row) {
            $tid = (int) ($row['id'] ?? 0);
            if ($tid <= 0) {
                continue;
            }
            if (!isset($tvDb[$tid])) {
                $stats['tv_skipped']++;
                continue;
            }
            if ($this->enqueueRow('tv', $tid, $day, 'changes')) {
                $stats['tv_enqueued']++;
            } else {
                $stats['tv_skipped']++;
            }
        }

        // TV: discover
        foreach ($this->discoverIds('tv', $day, $jobs) as $tid) {
            if ($this->enqueueRow('tv', $tid, $day, 'discover')) {
                $stats['tv_enqueued']++;
                $tvDb[$tid] = true;
            } else {
                $stats['tv_skipped']++;
            }
        }

        // People: changes ∩ DB (batched)
        $personIds = [];
        foreach ($this->tmdb->paginateResults('person/changes', [
            'start_date' => $day,
            'end_date' => $day,
        ]) as $row) {
            $tid = (int) ($row['id'] ?? 0);
            if ($tid > 0) {
                $personIds[] = $tid;
            }
        }
        for ($i = 0; $i < count($personIds); $i += 500) {
            $chunk = array_slice($personIds, $i, 500);
            $existing = $this->writer->existingPersonTmdbIds($chunk);
            foreach ($chunk as $tid) {
                if (!isset($existing[$tid])) {
                    $stats['person_skipped']++;
                    continue;
                }
                if ($this->enqueueRow('person', $tid, $day, 'changes')) {
                    $stats['person_enqueued']++;
                } else {
                    $stats['person_skipped']++;
                }
            }
        }

        return $stats;
    }

    public function enqueuePerson(int $tmdbId, string $syncDay, string $source = 'credits'): bool
    {
        return $this->enqueueRow('person', $tmdbId, $syncDay, $source);
    }

    public function todayIst(): string
    {
        return (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
    }

    private function enqueueRow(string $mediaType, int $tmdbId, string $syncDay, string $source): bool
    {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO media_sync_queue
             (media_type, tmdb_id, sync_day, source, status, attempts, enqueued_at)
             VALUES (?, ?, ?, ?, \'pending\', 0, NOW())'
        );
        $stmt->execute([$mediaType, $tmdbId, $syncDay, $source]);
        return $stmt->rowCount() === 1;
    }

    /** @return list<array{region:string,ids:string}> */
    private function providerJobs(): array
    {
        $path = (string) ($this->config['providers_config_path'] ?? '');
        if ($path === '' || !is_readable($path)) {
            $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'providers_config.json';
        }
        if (!is_readable($path)) {
            return [];
        }
        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw)) {
            return [];
        }
        $jobs = [];
        $seen = [];
        foreach ($raw['providers'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $region = strtoupper((string) ($item['region'] ?? ''));
            $pid = (string) ((int) ($item['tmdb_id'] ?? 0));
            if ($region === '' || $pid === '0') {
                continue;
            }
            $key = $region . ':' . $pid;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $jobs[] = ['region' => $region, 'ids' => $pid];
        }
        return $jobs;
    }

    /**
     * @param list<array{region:string,ids:string}> $jobs
     * @return list<int>
     */
    private function discoverIds(string $mediaType, string $day, array $jobs): array
    {
        $monetization = (string) ($this->config['tmdb_monetization'] ?? 'flatrate');
        $seen = [];
        $out = [];

        foreach ($jobs as $job) {
            if ($mediaType === 'movie') {
                $paramsList = [[
                    'with_watch_providers' => $job['ids'],
                    'watch_region' => $job['region'],
                    'with_watch_monetization_types' => $monetization,
                    'primary_release_date.gte' => $day,
                    'primary_release_date.lte' => $day,
                    'sort_by' => 'popularity.desc',
                ]];
                $path = 'discover/movie';
            } else {
                $paramsList = [
                    [
                        'with_watch_providers' => $job['ids'],
                        'watch_region' => $job['region'],
                        'with_watch_monetization_types' => $monetization,
                        'first_air_date.gte' => $day,
                        'first_air_date.lte' => $day,
                        'sort_by' => 'popularity.desc',
                    ],
                    [
                        'with_watch_providers' => $job['ids'],
                        'watch_region' => $job['region'],
                        'with_watch_monetization_types' => $monetization,
                        'air_date.gte' => $day,
                        'air_date.lte' => $day,
                        'sort_by' => 'popularity.desc',
                    ],
                ];
                $path = 'discover/tv';
            }

            foreach ($paramsList as $params) {
                foreach ($this->tmdb->paginateResults($path, $params, 20) as $row) {
                    $tid = (int) ($row['id'] ?? 0);
                    if ($tid > 0 && !isset($seen[$tid])) {
                        $seen[$tid] = true;
                        $out[] = $tid;
                    }
                }
            }
        }

        return $out;
    }
}
