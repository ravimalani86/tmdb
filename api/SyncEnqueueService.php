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

        $stats = [
            'sync_day' => $day,
            'movie_enqueued' => 0,
            'tv_enqueued' => 0,
            'person_enqueued' => 0,
            'movie_skipped' => 0,
            'tv_skipped' => 0,
            'person_skipped' => 0,
        ];

        // Movies: changes (new + existing — syncMovie() inserts or updates)
        foreach ($this->tmdb->paginateResults('movie/changes', [
            'start_date' => $day,
            'end_date' => $day,
        ]) as $row) {
            $tid = (int) ($row['id'] ?? 0);
            if ($tid <= 0) {
                continue;
            }
            if ($this->enqueueRow('movie', $tid, $day, 'changes')) {
                $stats['movie_enqueued']++;
            } else {
                $stats['movie_skipped']++;
            }
        }

        // Movies: discover release today
        foreach ($this->discoverIds('movie', $day, $day) as $tid) {
            if ($this->enqueueRow('movie', $tid, $day, 'discover')) {
                $stats['movie_enqueued']++;
            } else {
                $stats['movie_skipped']++;
            }
        }

        // TV: changes (new + existing — syncTv() inserts or updates)
        foreach ($this->tmdb->paginateResults('tv/changes', [
            'start_date' => $day,
            'end_date' => $day,
        ]) as $row) {
            $tid = (int) ($row['id'] ?? 0);
            if ($tid <= 0) {
                continue;
            }
            if ($this->enqueueRow('tv', $tid, $day, 'changes')) {
                $stats['tv_enqueued']++;
            } else {
                $stats['tv_skipped']++;
            }
        }

        // TV: discover
        foreach ($this->discoverIds('tv', $day, $day) as $tid) {
            if ($this->enqueueRow('tv', $tid, $day, 'discover')) {
                $stats['tv_enqueued']++;
            } else {
                $stats['tv_skipped']++;
            }
        }

        // People: changes (new + existing — syncPerson() inserts or updates)
        foreach ($this->tmdb->paginateResults('person/changes', [
            'start_date' => $day,
            'end_date' => $day,
        ]) as $row) {
            $tid = (int) ($row['id'] ?? 0);
            if ($tid <= 0) {
                continue;
            }
            if ($this->enqueueRow('person', $tid, $day, 'changes')) {
                $stats['person_enqueued']++;
            } else {
                $stats['person_skipped']++;
            }
        }

        return $stats;
    }

    /**
     * Manual date-range enqueue (admin "Sync by date range" tool).
     * $mediaType: null (all) | 'movie' | 'tv' | 'person'.
     * $source: null (all) | 'changes' | 'discover'. TV/movie only for discover —
     * TMDB has no discover/person endpoint, so person always runs changes only.
     * All enqueued rows are stamped with sync_day = $endDate.
     */
    public function enqueueRange(string $startDate, string $endDate, ?string $mediaType, ?string $source): array
    {
        $syncDay = $endDate;
        $runMovie = $mediaType === null || $mediaType === 'movie';
        $runTv = $mediaType === null || $mediaType === 'tv';
        $runPerson = $mediaType === null || $mediaType === 'person';
        $runChanges = $source === null || $source === 'changes';
        $runDiscover = $source === null || $source === 'discover';

        $stats = [
            'sync_day' => $syncDay,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'movie_enqueued' => 0,
            'tv_enqueued' => 0,
            'person_enqueued' => 0,
            'movie_skipped' => 0,
            'tv_skipped' => 0,
            'person_skipped' => 0,
        ];

        if ($runMovie && $runChanges) {
            foreach ($this->tmdb->paginateResults('movie/changes', [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]) as $row) {
                $tid = (int) ($row['id'] ?? 0);
                if ($tid <= 0) {
                    continue;
                }
                if ($this->enqueueRow('movie', $tid, $syncDay, 'changes')) {
                    $stats['movie_enqueued']++;
                } else {
                    $stats['movie_skipped']++;
                }
            }
        }

        if ($runMovie && $runDiscover) {
            foreach ($this->discoverIds('movie', $startDate, $endDate) as $tid) {
                if ($this->enqueueRow('movie', $tid, $syncDay, 'discover')) {
                    $stats['movie_enqueued']++;
                } else {
                    $stats['movie_skipped']++;
                }
            }
        }

        if ($runTv && $runChanges) {
            foreach ($this->tmdb->paginateResults('tv/changes', [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]) as $row) {
                $tid = (int) ($row['id'] ?? 0);
                if ($tid <= 0) {
                    continue;
                }
                if ($this->enqueueRow('tv', $tid, $syncDay, 'changes')) {
                    $stats['tv_enqueued']++;
                } else {
                    $stats['tv_skipped']++;
                }
            }
        }

        if ($runTv && $runDiscover) {
            foreach ($this->discoverIds('tv', $startDate, $endDate) as $tid) {
                if ($this->enqueueRow('tv', $tid, $syncDay, 'discover')) {
                    $stats['tv_enqueued']++;
                } else {
                    $stats['tv_skipped']++;
                }
            }
        }

        // Person: changes only — TMDB has no discover/person endpoint.
        if ($runPerson && $runChanges) {
            foreach ($this->tmdb->paginateResults('person/changes', [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]) as $row) {
                $tid = (int) ($row['id'] ?? 0);
                if ($tid <= 0) {
                    continue;
                }
                if ($this->enqueueRow('person', $tid, $syncDay, 'changes')) {
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

    /**
     * Provider matching removed: discover by release date only, worldwide.
     *
     * @return list<int>
     */
    private function discoverIds(string $mediaType, string $startDate, string $endDate): array
    {
        $seen = [];
        $out = [];

        if ($mediaType === 'movie') {
            $paramsList = [[
                'primary_release_date.gte' => $startDate,
                'primary_release_date.lte' => $endDate,
                'sort_by' => 'popularity.desc',
            ]];
            $path = 'discover/movie';
        } else {
            $paramsList = [
                [
                    'first_air_date.gte' => $startDate,
                    'first_air_date.lte' => $endDate,
                    'sort_by' => 'popularity.desc',
                ],
                [
                    'air_date.gte' => $startDate,
                    'air_date.lte' => $endDate,
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

        return $out;
    }

}
