<?php

declare(strict_types=1);

/**
 * Admin daily sync — PHP-only (no Python on live).
 */
final class SyncAdminRepository
{
    private TmdbClient $tmdb;
    private SyncMediaWriter $writer;
    private SyncEnqueueService $enqueue;
    private SyncProcessService $process;

    public function __construct(
        private PDO $db,
        private array $config,
    ) {
        $this->tmdb = new TmdbClient($config);
        $this->writer = new SyncMediaWriter($db);
        $this->enqueue = new SyncEnqueueService($db, $this->tmdb, $this->writer, $config);
        $this->process = new SyncProcessService($db, $this->tmdb, $this->writer, $this->enqueue);
    }

    public function status(?string $syncDay = null): array
    {
        $day = $syncDay ?: $this->todayIst();
        $stmt = $this->db->prepare(
            'SELECT status, COUNT(*) AS cnt
             FROM media_sync_queue
             WHERE sync_day = :day
             GROUP BY status'
        );
        $stmt->execute(['day' => $day]);
        $byStatus = [];
        foreach ($stmt->fetchAll() as $row) {
            $byStatus[(string) $row['status']] = (int) $row['cnt'];
        }

        $stmt = $this->db->prepare(
            'SELECT media_type, COUNT(*) AS cnt
             FROM media_sync_queue
             WHERE sync_day = :day
             GROUP BY media_type'
        );
        $stmt->execute(['day' => $day]);
        $byType = [];
        foreach ($stmt->fetchAll() as $row) {
            $byType[(string) $row['media_type']] = (int) $row['cnt'];
        }

        $recent = $this->db->prepare(
            'SELECT id, media_type, tmdb_id, source, status, attempts, last_error,
                    enqueued_at, started_at, finished_at
             FROM media_sync_queue
             WHERE sync_day = :day
             ORDER BY id DESC
             LIMIT 30'
        );
        $recent->execute(['day' => $day]);

        return [
            'sync_day' => $day,
            'by_status' => $byStatus,
            'by_type' => $byType,
            'pending' => $byStatus['pending'] ?? 0,
            'running' => $byStatus['running'] ?? 0,
            'done' => $byStatus['done'] ?? 0,
            'failed' => $byStatus['failed'] ?? 0,
            'total' => array_sum($byStatus),
            'tmdb_keys' => $this->tmdb->keyCount(),
            'engine' => 'php',
            'recent' => $recent->fetchAll(),
        ];
    }

    public function enqueue(?string $syncDay = null): array
    {
        @set_time_limit(600);
        ignore_user_abort(true);
        $stats = $this->enqueue->enqueue($syncDay);
        return [
            'action' => 'enqueue',
            'enqueue' => $stats,
            'status' => $this->status($stats['sync_day'] ?? $syncDay),
        ];
    }

    public function process(?int $payloadLimit = null, ?string $payloadMediaType = null, ?string $payloadSource = null): array
    {
        $opts = $this->resolveProcessOptions($payloadLimit, $payloadMediaType, $payloadSource);
        $batchHolds = intdiv(max(0, $opts['limit'] - 1), 50);
        @set_time_limit(300 + ($batchHolds * 30));
        ignore_user_abort(true);
        $stats = $this->process->process($opts['limit'], $opts['media_type'], $opts['source']);
        $stats['resolved_from'] = $opts['resolved_from'];
        return [
            'action' => 'process',
            'process' => $stats,
            'status' => $this->status(),
        ];
    }

    public function run(?string $syncDay = null, ?int $payloadLimit = null, ?string $payloadMediaType = null, ?string $payloadSource = null): array
    {
        $opts = $this->resolveProcessOptions($payloadLimit, $payloadMediaType, $payloadSource);
        $batchHolds = intdiv(max(0, $opts['limit'] - 1), 50);
        @set_time_limit(900 + ($batchHolds * 30));
        ignore_user_abort(true);
        $enq = $this->enqueue->enqueue($syncDay);
        $proc = $this->process->process($opts['limit'], $opts['media_type'], $opts['source']);
        $proc['resolved_from'] = $opts['resolved_from'];
        return [
            'action' => 'run',
            'enqueue' => $enq,
            'process' => $proc,
            'status' => $this->status($enq['sync_day'] ?? $syncDay),
            'note' => 'Process only handles limit items per request. Call /admin/sync/process repeatedly until pending=0.',
        ];
    }

    /**
     * Global queue counts (not filtered by sync_day).
     *
     * @return array{
     *   pending_by_type: array<string,int>,
     *   pending_by_source: array<string,int>,
     *   by_status: array<string,int>,
     *   pending_total: int,
     *   total: int
     * }
     */
    public function queueOverview(): array
    {
        $pendingByType = [];
        foreach ($this->db->query(
            "SELECT media_type, COUNT(*) AS cnt
             FROM media_sync_queue
             WHERE status = 'pending'
             GROUP BY media_type"
        )->fetchAll() as $row) {
            $pendingByType[(string) $row['media_type']] = (int) $row['cnt'];
        }

        $pendingBySource = [];
        foreach ($this->db->query(
            "SELECT COALESCE(NULLIF(TRIM(source), ''), '(empty)') AS source, COUNT(*) AS cnt
             FROM media_sync_queue
             WHERE status = 'pending'
             GROUP BY COALESCE(NULLIF(TRIM(source), ''), '(empty)')"
        )->fetchAll() as $row) {
            $pendingBySource[(string) $row['source']] = (int) $row['cnt'];
        }

        $byStatus = [];
        foreach ($this->db->query(
            'SELECT status, COUNT(*) AS cnt
             FROM media_sync_queue
             GROUP BY status'
        )->fetchAll() as $row) {
            $byStatus[(string) $row['status']] = (int) $row['cnt'];
        }

        return [
            'pending_by_type' => $pendingByType,
            'pending_by_source' => $pendingBySource,
            'by_status' => $byStatus,
            'pending_total' => array_sum($pendingByType),
            'total' => array_sum($byStatus),
        ];
    }

    /**
     * @return array{
     *   process_limit: int|null,
     *   media_type: string|null,
     *   source: string|null,
     *   updated_at: string|null
     * }
     */
    public function getCronConfig(): array
    {
        $empty = [
            'process_limit' => null,
            'media_type' => null,
            'source' => null,
            'updated_at' => null,
        ];
        try {
            $this->ensureCronConfigRow();
            $row = $this->db->query(
                'SELECT process_limit, media_type, source, updated_at FROM sync_cron_config WHERE id = 1 LIMIT 1'
            )->fetch();
        } catch (Throwable $e) {
            return $empty;
        }
        if ($row === false) {
            return $empty;
        }
        $mt = $row['media_type'] ?? null;
        if ($mt !== null) {
            $mt = trim((string) $mt);
            $mt = $mt === '' ? null : strtolower($mt);
        }
        $src = $row['source'] ?? null;
        if ($src !== null) {
            $src = trim((string) $src);
            $src = $src === '' ? null : strtolower($src);
        }
        $limit = (int) ($row['process_limit'] ?? 0);
        return [
            'process_limit' => $limit > 0 ? max(1, min(450, $limit)) : null,
            'media_type' => $mt,
            'source' => $src,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    public function saveCronConfig(int $processLimit, ?string $mediaType, ?string $source = null): array
    {
        $processLimit = max(1, min(450, $processLimit));
        if ($mediaType !== null) {
            $mediaType = strtolower(trim($mediaType));
            if ($mediaType === '' || $mediaType === 'all') {
                $mediaType = null;
            }
            if ($mediaType !== null && !in_array($mediaType, ['movie', 'tv', 'person'], true)) {
                throw new InvalidArgumentException('media_type must be movie, tv, person, or empty');
            }
        }
        if ($source !== null) {
            $source = strtolower(trim($source));
            if ($source === '' || $source === 'all') {
                $source = null;
            }
            if ($source !== null && !is_allowed_sync_source($source)) {
                throw new InvalidArgumentException('source must be changes, discover, credits, backfill, or empty');
            }
        }

        $this->ensureCronConfigRow();
        $this->db->prepare(
            'UPDATE sync_cron_config
             SET process_limit = ?, media_type = ?, source = ?, updated_at = UTC_TIMESTAMP()
             WHERE id = 1'
        )->execute([$processLimit, $mediaType, $source]);

        return $this->getCronConfig();
    }

    /**
     * Priority: admin DB config → payload → default.
     *
     * @return array{
     *   limit: int,
     *   media_type: string|null,
     *   source: string|null,
     *   resolved_from: array{limit: string, media_type: string, source: string}
     * }
     */
    public function resolveProcessOptions(
        ?int $payloadLimit,
        ?string $payloadMediaType,
        ?string $payloadSource = null
    ): array {
        $cfg = $this->getCronConfig();
        $from = ['limit' => 'default', 'media_type' => 'default', 'source' => 'default'];

        $limit = 3;
        if ($cfg['process_limit'] !== null && (int) $cfg['process_limit'] > 0) {
            $limit = (int) $cfg['process_limit'];
            $from['limit'] = 'admin';
        } elseif ($payloadLimit !== null) {
            $limit = $payloadLimit;
            $from['limit'] = 'payload';
        }
        $limit = max(1, min(450, $limit));

        $mediaType = null;
        $dbMt = $cfg['media_type'] ?? null;
        if (is_string($dbMt) && $dbMt !== '') {
            $mediaType = strtolower($dbMt);
            $from['media_type'] = 'admin';
        } elseif ($payloadMediaType !== null && $payloadMediaType !== '') {
            $mediaType = strtolower($payloadMediaType);
            $from['media_type'] = 'payload';
        }

        if ($mediaType !== null && !in_array($mediaType, ['movie', 'tv', 'person'], true)) {
            $mediaType = null;
            $from['media_type'] = 'default';
        }

        $source = null;
        $dbSrc = $cfg['source'] ?? null;
        if (is_string($dbSrc) && $dbSrc !== '') {
            $source = strtolower($dbSrc);
            $from['source'] = 'admin';
        } elseif ($payloadSource !== null && $payloadSource !== '') {
            $source = strtolower($payloadSource);
            $from['source'] = 'payload';
        }

        if ($source !== null && !is_allowed_sync_source($source)) {
            $source = null;
            $from['source'] = 'default';
        }

        return [
            'limit' => $limit,
            'media_type' => $mediaType,
            'source' => $source,
            'resolved_from' => $from,
        ];
    }

    private function ensureCronConfigRow(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS sync_cron_config (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                process_limit INT NOT NULL DEFAULT 50,
                media_type VARCHAR(16) NULL,
                source VARCHAR(32) NULL,
                updated_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        // Older installs may lack source column.
        try {
            $cols = $this->db->query('SHOW COLUMNS FROM sync_cron_config LIKE \'source\'')->fetchAll();
            if ($cols === []) {
                $this->db->exec('ALTER TABLE sync_cron_config ADD COLUMN source VARCHAR(32) NULL AFTER media_type');
            }
        } catch (Throwable $e) {
            // Ignore if no permission / already exists race.
        }

        $this->db->exec(
            'INSERT IGNORE INTO sync_cron_config (id, process_limit, media_type, source)
             VALUES (1, 50, NULL, NULL)'
        );
    }

    public function todayIst(): string
    {
        return (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
    }

    public function tvGaps(): array
    {
        return $this->enqueue->tvSeasonGaps();
    }

    public function enqueueTvGaps(?string $syncDay = null, ?int $limit = null): array
    {
        @set_time_limit(120);
        ignore_user_abort(true);
        $stats = $this->enqueue->enqueueIncompleteTv($syncDay, $limit);
        return [
            'action' => 'enqueue_tv_gaps',
            'enqueue' => $stats,
            'status' => $this->status($stats['sync_day'] ?? $syncDay),
        ];
    }

    /**
     * Light TMDB GET + MySQL exists check (no extras).
     *
     * @return array{
     *   media_type: string,
     *   tmdb_id: int,
     *   synced: bool,
     *   preview: array<string, mixed>
     * }
     */
    public function lookupItem(string $mediaType, int $tmdbId): array
    {
        $mediaType = $this->requireMediaType($mediaType);
        $tmdbId = $this->requireTmdbId($tmdbId);

        $path = match ($mediaType) {
            'movie' => "movie/{$tmdbId}",
            'tv' => "tv/{$tmdbId}",
            'person' => "person/{$tmdbId}",
        };
        $detail = $this->tmdb->get($path);
        if ($detail === null) {
            throw new RuntimeException(ucfirst($mediaType) . ' not found');
        }

        $synced = $this->isSynced($mediaType, $tmdbId);
        $out = [
            'media_type' => $mediaType,
            'tmdb_id' => $tmdbId,
            'synced' => $synced,
            'preview' => $this->buildPreview($mediaType, $detail),
        ];
        if ($mediaType === 'tv' && $synced) {
            $out['local'] = $this->tvLocalSeasonStats($tmdbId);
        }
        return $out;
    }

    /**
     * Full upsert of one movie / TV / person. Does not enqueue related people.
     *
     * @return array{action: string, media_type: string, tmdb_id: int, synced: true}
     */
    public function syncItem(string $mediaType, int $tmdbId): array
    {
        $mediaType = $this->requireMediaType($mediaType);
        $tmdbId = $this->requireTmdbId($tmdbId);

        @set_time_limit($mediaType === 'tv' ? 600 : 180);
        ignore_user_abort(true);

        $action = match ($mediaType) {
            'movie' => $this->writer->syncMovie($this->tmdb, $tmdbId),
            'tv' => $this->writer->syncTv($this->tmdb, $tmdbId),
            'person' => $this->writer->syncPerson($this->tmdb, $tmdbId),
        };

        return [
            'action' => $action,
            'media_type' => $mediaType,
            'tmdb_id' => $tmdbId,
            'synced' => true,
        ];
    }

    /** @return 'movie'|'tv'|'person' */
    private function requireMediaType(string $mediaType): string
    {
        $mediaType = strtolower(trim($mediaType));
        if (!in_array($mediaType, ['movie', 'tv', 'person'], true)) {
            throw new InvalidArgumentException('media_type must be movie, tv, or person');
        }
        return $mediaType;
    }

    private function requireTmdbId(int $tmdbId): int
    {
        if ($tmdbId <= 0) {
            throw new InvalidArgumentException('tmdb_id must be a positive integer');
        }
        return $tmdbId;
    }

    /** @return array<string, int|null> */
    private function tvLocalSeasonStats(int $tmdbId): array
    {
        $stmt = $this->db->prepare(
            "SELECT t.number_of_seasons, t.number_of_episodes,
                    COALESCE(s.season_cnt, 0) AS seasons_in_db,
                    COALESCE(s.regular_seasons, 0) AS regular_seasons_in_db,
                    COALESCE(e.ep_cnt, 0) AS episodes_in_db
             FROM tv_shows t
             LEFT JOIN (
               SELECT tv_show_id,
                      COUNT(*) AS season_cnt,
                      SUM(CASE WHEN season_number > 0 THEN 1 ELSE 0 END) AS regular_seasons
               FROM tv_seasons GROUP BY tv_show_id
             ) s ON s.tv_show_id = t.id
             LEFT JOIN (
               SELECT tv_show_id, COUNT(*) AS ep_cnt FROM tv_episodes GROUP BY tv_show_id
             ) e ON e.tv_show_id = t.id
             WHERE t.tmdb_id = ?
             LIMIT 1"
        );
        $stmt->execute([$tmdbId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return [];
        }
        return [
            'number_of_seasons' => $row['number_of_seasons'] !== null ? (int) $row['number_of_seasons'] : null,
            'number_of_episodes' => $row['number_of_episodes'] !== null ? (int) $row['number_of_episodes'] : null,
            'seasons_in_db' => (int) $row['seasons_in_db'],
            'regular_seasons_in_db' => (int) $row['regular_seasons_in_db'],
            'episodes_in_db' => (int) $row['episodes_in_db'],
        ];
    }

    private function isSynced(string $mediaType, int $tmdbId): bool
    {
        return match ($mediaType) {
            'movie' => $this->writer->movieLocalId($tmdbId) !== null,
            'tv' => $this->writer->tvLocalId($tmdbId) !== null,
            'person' => $this->writer->personLocalId($tmdbId) !== null,
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    private function buildPreview(string $mediaType, array $detail): array
    {
        if ($mediaType === 'person') {
            return [
                'name' => (string) ($detail['name'] ?? ''),
                'date' => $detail['birthday'] ?? null,
                'known_for_department' => $detail['known_for_department'] ?? null,
                'popularity' => isset($detail['popularity']) ? (float) $detail['popularity'] : null,
                'original_language' => null,
                'vote_average' => null,
                'image_url' => tmdb_image(
                    isset($detail['profile_path']) ? (string) $detail['profile_path'] : null,
                    'w185'
                ),
            ];
        }

        $name = $mediaType === 'tv'
            ? (string) ($detail['name'] ?? '')
            : (string) ($detail['title'] ?? '');
        $date = $mediaType === 'tv'
            ? ($detail['first_air_date'] ?? null)
            : ($detail['release_date'] ?? null);

        return [
            'name' => $name,
            'date' => $date,
            'original_language' => $detail['original_language'] ?? null,
            'vote_average' => isset($detail['vote_average']) ? (float) $detail['vote_average'] : null,
            'image_url' => tmdb_image(
                isset($detail['poster_path']) ? (string) $detail['poster_path'] : null,
                'w185'
            ),
        ];
    }
}
