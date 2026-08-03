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

    public function process(int $limit = 3): array
    {
        @set_time_limit(300);
        ignore_user_abort(true);
        $stats = $this->process->process($limit);
        return [
            'action' => 'process',
            'process' => $stats,
            'status' => $this->status(),
        ];
    }

    public function run(?string $syncDay = null, int $limit = 3): array
    {
        @set_time_limit(900);
        ignore_user_abort(true);
        $enq = $this->enqueue->enqueue($syncDay);
        $proc = $this->process->process($limit);
        return [
            'action' => 'run',
            'enqueue' => $enq,
            'process' => $proc,
            'status' => $this->status($enq['sync_day'] ?? $syncDay),
            'note' => 'Process only handles limit items per request. Call /admin/sync/process repeatedly until pending=0.',
        ];
    }

    public function todayIst(): string
    {
        return (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
    }
}
