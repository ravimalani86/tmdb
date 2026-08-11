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

    public function process(?int $payloadLimit = null, ?string $payloadMediaType = null): array
    {
        $opts = $this->resolveProcessOptions($payloadLimit, $payloadMediaType);
        $batchHolds = intdiv(max(0, $opts['limit'] - 1), 50);
        @set_time_limit(300 + ($batchHolds * 30));
        ignore_user_abort(true);
        $stats = $this->process->process($opts['limit'], $opts['media_type']);
        $stats['resolved_from'] = $opts['resolved_from'];
        return [
            'action' => 'process',
            'process' => $stats,
            'status' => $this->status(),
        ];
    }

    public function run(?string $syncDay = null, ?int $payloadLimit = null, ?string $payloadMediaType = null): array
    {
        $opts = $this->resolveProcessOptions($payloadLimit, $payloadMediaType);
        $batchHolds = intdiv(max(0, $opts['limit'] - 1), 50);
        @set_time_limit(900 + ($batchHolds * 30));
        ignore_user_abort(true);
        $enq = $this->enqueue->enqueue($syncDay);
        $proc = $this->process->process($opts['limit'], $opts['media_type']);
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
     * @return array{pending_by_type: array<string,int>, by_status: array<string,int>}
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
            'by_status' => $byStatus,
            'pending_total' => array_sum($pendingByType),
            'total' => array_sum($byStatus),
        ];
    }

    /**
     * @return array{process_limit: int|null, media_type: string|null, updated_at: string|null}
     */
    public function getCronConfig(): array
    {
        try {
            $this->ensureCronConfigRow();
            $row = $this->db->query(
                'SELECT process_limit, media_type, updated_at FROM sync_cron_config WHERE id = 1 LIMIT 1'
            )->fetch();
        } catch (Throwable $e) {
            return [
                'process_limit' => null,
                'media_type' => null,
                'updated_at' => null,
            ];
        }
        if ($row === false) {
            return [
                'process_limit' => null,
                'media_type' => null,
                'updated_at' => null,
            ];
        }
        $mt = $row['media_type'] ?? null;
        if ($mt !== null) {
            $mt = trim((string) $mt);
            $mt = $mt === '' ? null : strtolower($mt);
        }
        $limit = (int) ($row['process_limit'] ?? 0);
        return [
            'process_limit' => $limit > 0 ? max(1, min(450, $limit)) : null,
            'media_type' => $mt,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    public function saveCronConfig(int $processLimit, ?string $mediaType): array
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

        $this->ensureCronConfigRow();
        $this->db->prepare(
            'UPDATE sync_cron_config
             SET process_limit = ?, media_type = ?, updated_at = UTC_TIMESTAMP()
             WHERE id = 1'
        )->execute([$processLimit, $mediaType]);

        return $this->getCronConfig();
    }

    /**
     * Priority: admin DB config → payload → default.
     *
     * @return array{limit: int, media_type: string|null, resolved_from: array{limit: string, media_type: string}}
     */
    public function resolveProcessOptions(?int $payloadLimit, ?string $payloadMediaType): array
    {
        $cfg = $this->getCronConfig();
        $from = ['limit' => 'default', 'media_type' => 'default'];

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

        return [
            'limit' => $limit,
            'media_type' => $mediaType,
            'resolved_from' => $from,
        ];
    }

    private function ensureCronConfigRow(): void
    {
        try {
            $this->db->exec(
                'INSERT IGNORE INTO sync_cron_config (id, process_limit, media_type)
                 VALUES (1, 50, NULL)'
            );
        } catch (Throwable $e) {
            // Table may not exist yet — caller will see the real error on SELECT/UPDATE.
        }
    }

    public function todayIst(): string
    {
        return (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
    }
}
