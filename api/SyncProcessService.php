<?php

declare(strict_types=1);

/**
 * Process pending media_sync_queue rows (PHP-only, multi-key TMDB client).
 * Use small limit per HTTP request to avoid shared-hosting timeouts.
 */
final class SyncProcessService
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private PDO $db,
        private TmdbClient $tmdb,
        private SyncMediaWriter $writer,
        private SyncEnqueueService $enqueue,
    ) {
    }

    public function process(int $limit = 3, ?string $mediaType = null): array
    {
        $limit = max(1, min(50, $limit));
        $mediaType = $this->normalizeMediaType($mediaType);
        $stats = [
            'done' => 0,
            'failed' => 0,
            'people_enqueued' => 0,
            'processed' => 0,
            'limit' => $limit,
            'media_type' => $mediaType,
            'tmdb_keys' => $this->tmdb->keyCount(),
        ];

        $rows = $this->claim($limit, $mediaType);
        foreach ($rows as $row) {
            $stats['processed']++;
            try {
                $this->db->beginTransaction();
                $action = match ($row['media_type']) {
                    'movie' => $this->writer->syncMovie($this->tmdb, (int) $row['tmdb_id']),
                    'tv' => $this->writer->syncTv($this->tmdb, (int) $row['tmdb_id']),
                    'person' => $this->writer->syncPerson($this->tmdb, (int) $row['tmdb_id']),
                    default => throw new RuntimeException('Unknown media_type'),
                };

                if ($row['media_type'] === 'movie' || $row['media_type'] === 'tv') {
                    $localId = $row['media_type'] === 'movie'
                        ? $this->writer->movieLocalId((int) $row['tmdb_id'])
                        : $this->writer->tvLocalId((int) $row['tmdb_id']);
                    if ($localId !== null) {
                        foreach ($this->writer->topCreditPersonTmdbIds($row['media_type'], $localId) as $pid) {
                            if ($this->enqueue->enqueuePerson($pid, $row['sync_day'], 'credits')) {
                                $stats['people_enqueued']++;
                            }
                        }
                    }
                }

                $this->markDone((int) $row['id']);
                $this->db->commit();
                $stats['done']++;
                unset($action);
            } catch (Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                $this->markFailed((int) $row['id'], (int) $row['attempts'], $e->getMessage());
                $stats['failed']++;
            }
        }

        return $stats;
    }

    /** @return 'movie'|'tv'|'person'|null */
    private function normalizeMediaType(?string $mediaType): ?string
    {
        if ($mediaType === null || $mediaType === '') {
            return null;
        }
        $mediaType = strtolower(trim($mediaType));
        return in_array($mediaType, ['movie', 'tv', 'person'], true) ? $mediaType : null;
    }

    /** @return list<array<string, mixed>> */
    private function claim(int $limit, ?string $mediaType = null): array
    {
        $typeSql = $mediaType !== null ? ' AND media_type = :mt' : '';
        try {
            $stmt = $this->db->prepare(
                'SELECT id FROM media_sync_queue
                 WHERE status = \'pending\' AND attempts < :maxa' . $typeSql . '
                 ORDER BY id ASC
                 LIMIT :lim
                 FOR UPDATE SKIP LOCKED'
            );
            $stmt->bindValue('maxa', self::MAX_ATTEMPTS, PDO::PARAM_INT);
            $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
            if ($mediaType !== null) {
                $stmt->bindValue('mt', $mediaType);
            }
            $this->db->beginTransaction();
            $stmt->execute();
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            if ($ids === []) {
                $this->db->commit();
                return [];
            }
            $in = implode(',', $ids);
            $this->db->exec(
                "UPDATE media_sync_queue
                 SET status = 'running', attempts = attempts + 1, started_at = UTC_TIMESTAMP(), worker_id = 0
                 WHERE id IN ({$in})"
            );
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            // Fallback without SKIP LOCKED
            if ($mediaType !== null) {
                $stmt = $this->db->prepare(
                    'SELECT id FROM media_sync_queue
                     WHERE status = \'pending\' AND attempts < :maxa AND media_type = :mt
                     ORDER BY id ASC
                     LIMIT :lim'
                );
                $stmt->bindValue('maxa', self::MAX_ATTEMPTS, PDO::PARAM_INT);
                $stmt->bindValue('mt', $mediaType);
                $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
                $stmt->execute();
            } else {
                $stmt = $this->db->query(
                    'SELECT id FROM media_sync_queue
                     WHERE status = \'pending\' AND attempts < ' . self::MAX_ATTEMPTS . '
                     ORDER BY id ASC
                     LIMIT ' . (int) $limit
                );
            }
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            if ($ids === []) {
                return [];
            }
            $in = implode(',', $ids);
            $this->db->exec(
                "UPDATE media_sync_queue
                 SET status = 'running', attempts = attempts + 1, started_at = UTC_TIMESTAMP(), worker_id = 0
                 WHERE id IN ({$in}) AND status = 'pending'"
            );
        }

        $in = implode(',', $ids);
        return $this->db->query(
            "SELECT id, media_type, tmdb_id, sync_day, attempts
             FROM media_sync_queue WHERE id IN ({$in}) ORDER BY id ASC"
        )->fetchAll();
    }

    private function markDone(int $id): void
    {
        $this->db->prepare(
            'UPDATE media_sync_queue
             SET status = \'done\', finished_at = UTC_TIMESTAMP(), last_error = NULL
             WHERE id = ?'
        )->execute([$id]);
    }

    private function markFailed(int $id, int $attempts, string $error): void
    {
        $status = $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending';
        $this->db->prepare(
            'UPDATE media_sync_queue
             SET status = ?, finished_at = IF(? = \'failed\', UTC_TIMESTAMP(), NULL),
                 last_error = ?, worker_id = NULL
             WHERE id = ?'
        )->execute([$status, $status, mb_substr($error, 0, 2000), $id]);
    }
}
