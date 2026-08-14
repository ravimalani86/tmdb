<?php

declare(strict_types=1);

/**
 * Browser runner: drain media_sync_queue for person + credits only.
 *
 * Open in browser:
 *   https://app.myappworld.in/tmdb/api/sync-person-credits.php
 *   http://localhost/tmdb/api/sync-person-credits.php
 *
 * Optional ?tab=1|2|3|4 — each tab uses 2 TMDB keys from TMDB_API_KEYS
 * (tab1=keys1-2, tab2=3-4, tab3=5-6, tab4=7-8). Omit tab = all keys.
 *
 * Then enter API key and click Start — each completed person prints live.
 */

$config = require __DIR__ . '/config.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/TmdbClient.php';
require __DIR__ . '/SyncMediaWriter.php';

const MAX_ATTEMPTS = 3;
const DEFAULT_PER_RUN = 100;
const STUCK_MINUTES = 20;
const DEFAULT_PERSONS_PER_KEY = 10;

$expectedKey = (string) (($config['admin_api_key'] ?? '') ?: ($config['api_key'] ?? ''));
$providedKey = trim((string) ($_GET['key'] ?? $_POST['key'] ?? ''));
$run = isset($_GET['run']) || (isset($_POST['run']) && (string) $_POST['run'] === '1');
$perRun = (int) ($_GET['limit'] ?? $_POST['limit'] ?? DEFAULT_PER_RUN);
$perRun = max(1, min(500, $perRun));
$personsPerKey = (int) ($_GET['ppk'] ?? $_POST['ppk'] ?? DEFAULT_PERSONS_PER_KEY);
$personsPerKey = max(1, min(100, $personsPerKey));
$tab = (int) ($_GET['tab'] ?? $_POST['tab'] ?? 0);
if ($tab < 0) {
    $tab = 0;
}
$source = strtolower(trim((string) ($_GET['source'] ?? $_POST['source'] ?? 'credits')));
if ($source === '' || !preg_match('/^[a-z0-9_,]+$/', $source)) {
    $source = 'credits';
}
$sources = array_values(array_filter(array_map('trim', explode(',', $source))));
if ($sources === []) {
    $sources = ['credits'];
}

if (!$run) {
    renderForm($expectedKey !== '', $perRun, implode(',', $sources), $personsPerKey, $tab);
    exit;
}

if ($expectedKey === '' || $providedKey === '' || !hash_equals($expectedKey, $providedKey)) {
    http_response_code(401);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body style="font-family:monospace;background:#111;color:#f66;padding:24px">';
    echo '<p>Unauthorized — wrong or missing API key.</p>';
    echo '<p><a href="?" style="color:#8cf">Back</a></p></body></html>';
    exit;
}

$allKeys = array_values(array_filter(array_map('strval', $config['tmdb_api_keys'] ?? [])));
$keySliceLabel = 'all';
if ($tab >= 1) {
    $start = ($tab - 1) * 2;
    $slice = array_slice($allKeys, $start, 2);
    if ($slice === []) {
        http_response_code(400);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><body style="font-family:monospace;background:#111;color:#f66;padding:24px">';
        echo '<p>tab=' . $tab . ' needs TMDB keys at positions ' . ($start + 1) . '-' . ($start + 2)
            . ' — only ' . count($allKeys) . ' key(s) in .env.</p>';
        echo '<p><a href="?" style="color:#8cf">Back</a></p></body></html>';
        exit;
    }
    $config['tmdb_api_keys'] = array_values($slice);
    $config['tmdb_api_key'] = $slice[0];
    $keySliceLabel = 'tab ' . $tab . ' → env keys ' . ($start + 1) . '-' . ($start + count($slice));
}

@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);
@set_time_limit(0);
ignore_user_abort(true);

header('Content-Type: text/html; charset=utf-8');
header('X-Accel-Buffering: no');
header('Cache-Control: no-cache, no-store');

$continueParams = [
    'run' => '1',
    'key' => $providedKey,
    'limit' => $perRun,
    'ppk' => $personsPerKey,
    'source' => implode(',', $sources),
];
if ($tab >= 1) {
    $continueParams['tab'] = $tab;
}
$continueUrl = '?' . http_build_query($continueParams);

echo '<!DOCTYPE html><html><head><meta charset="utf-8">';
echo '<title>Person/Credits Sync</title>';
echo '<style>
body{margin:0;background:#0b0f14;color:#d7e0ea;font:14px/1.45 Consolas,Menlo,monospace}
.wrap{max-width:980px;margin:0 auto;padding:16px 18px 48px}
h1{font-size:18px;margin:0 0 8px;color:#fff}
.meta{color:#8b9bb0;margin-bottom:14px}
.log{background:#111820;border:1px solid #243041;border-radius:10px;padding:12px 14px;min-height:60vh;word-break:break-word}
.log > div{margin:0;padding:0;line-height:1.35}
.ok{color:#3ddc97}.err{color:#ff6b6b}.info{color:#8ec8ff}.warn{color:#ffd166}.dim{color:#6b7c90}
a{color:#8ec8ff}
</style></head><body><div class="wrap">';
echo '<h1>Person / Credits sync</h1>';
echo '<div class="meta">Live progress — each person prints when complete. Source: <b>'
    . htmlspecialchars(implode(',', $sources), ENT_QUOTES, 'UTF-8')
    . '</b> · batch: <b>' . $perRun . '</b> · rotate key every <b>' . $personsPerKey
    . '</b> persons · keys: <b>' . htmlspecialchars($keySliceLabel, ENT_QUOTES, 'UTF-8') . '</b></div>';
echo '<div class="log" id="log">';
flush_now();

try {
    $pdo = Database::connection($config);
    $tmdb = new TmdbClient($config);
    $writer = new SyncMediaWriter($pdo);
    $keyCount = $tmdb->keyCount();

    out('info', 'Connected. TMDB keys in this worker: ' . $keyCount
        . ($tab >= 1 ? (' (tab=' . $tab . ')') : ' (all keys)'));
    out('info', sprintf(
        'Key rotation: every %d persons → next key (cycle of %d). On 429 auto-switch to next key.',
        $personsPerKey,
        $keyCount
    ));
    $counts = queueCounts($pdo, $sources);
    out('info', sprintf(
        'Queue now — pending=%d running=%d done=%d failed=%d total=%d',
        $counts['pending'],
        $counts['running'],
        $counts['done'],
        $counts['failed'],
        $counts['total']
    ));

    $reclaimed = reclaimStuck($pdo, $sources, STUCK_MINUTES);
    if ($reclaimed > 0) {
        out('warn', "Reclaimed {$reclaimed} stuck running rows (> " . STUCK_MINUTES . ' min)');
    }

    if ($counts['pending'] === 0 && $counts['running'] === 0) {
        out('ok', 'Nothing pending. Person/credits queue is complete.');
        echo '</div><p class="meta"><a href="?">Back</a></p></div></body></html>';
        exit;
    }

    if ($keyCount < 2) {
        out('warn', 'Only 1 TMDB key in .env — add TMDB_API_KEYS=key1,key2,...key6 for rotation');
    }

    $done = 0;
    $failed = 0;
    $processed = 0;
    $startedAt = microtime(true);
    // Continue cycle across auto-refresh using already-done count
    $globalOffset = (int) $counts['done'];
    $activeSlot = -1;

    while ($processed < $perRun) {
        if (connection_aborted()) {
            out('warn', 'Browser disconnected — stopping this batch.');
            break;
        }

        $slot = (int) floor(($globalOffset + $processed) / $personsPerKey) % max(1, $keyCount);
        if ($slot !== $activeSlot) {
            $tmdb->pinToIndex($slot);
            $activeSlot = $slot;
            out('warn', sprintf(
                '→ Using %s for next %d person(s)',
                $tmdb->keyLabel($slot),
                $personsPerKey
            ));
        }

        $row = claimOne($pdo, $sources);
        if ($row === null) {
            out('info', 'No more claimable pending rows in this pass.');
            break;
        }

        $processed++;
        $qid = (int) $row['id'];
        $tmdbId = (int) $row['tmdb_id'];
        $attempts = (int) $row['attempts'];
        $src = (string) $row['source'];
        $t0 = microtime(true);

        out('dim', sprintf(
            '[%d/%d] queue#%d person tmdb_id=%d source=%s attempt=%d %s …',
            $processed,
            $perRun,
            $qid,
            $tmdbId,
            $src,
            $attempts,
            $tmdb->keyLabel()
        ));

        try {
            $pdo->beginTransaction();
            $action = $writer->syncPerson($tmdb, $tmdbId);
            markDone($pdo, $qid);
            $pdo->commit();
            $done++;
            $ms = (int) round((microtime(true) - $t0) * 1000);
            out('ok', sprintf(
                '✓ DONE  person %d (%s) in %dms via %s  | batch done=%d fail=%d',
                $tmdbId,
                $action,
                $ms,
                $tmdb->keyLabel(),
                $done,
                $failed
            ));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            markFailed($pdo, $qid, $attempts, $e->getMessage());
            $failed++;
            out('err', sprintf(
                '✗ FAIL  person %d — %s  | batch done=%d fail=%d',
                $tmdbId,
                $e->getMessage(),
                $done,
                $failed
            ));
        }
    }

    $elapsed = round(microtime(true) - $startedAt, 1);
    $left = queueCounts($pdo, $sources);
    out('info', sprintf(
        'Batch finished in %ss — processed=%d done=%d failed=%d',
        $elapsed,
        $processed,
        $done,
        $failed
    ));
    out('info', sprintf(
        'Remaining — pending=%d running=%d done=%d failed=%d',
        $left['pending'],
        $left['running'],
        $left['done'],
        $left['failed']
    ));

    if ($left['pending'] > 0 || $left['running'] > 0) {
        out('warn', 'More left — auto-continuing in 2 seconds…');
        echo '</div>';
        echo '<p class="meta">If it does not continue, <a href="'
            . htmlspecialchars($continueUrl, ENT_QUOTES, 'UTF-8')
            . '">click here</a>.</p>';
        echo '<script>setTimeout(function(){ location.href = '
            . json_encode($continueUrl)
            . '; }, 2000);</script>';
        echo '</div></body></html>';
        exit;
    }

    out('ok', 'ALL COMPLETE — person/credits pending=0');
    echo '</div><p class="meta"><a href="?">Back</a></p></div></body></html>';
} catch (Throwable $e) {
    out('err', 'Fatal: ' . $e->getMessage());
    echo '</div><p class="meta"><a href="?">Back</a></p></div></body></html>';
}

// --- helpers ---

function renderForm(bool $keyConfigured, int $limit, string $source, int $ppk, int $tab = 0): void
{
    header('Content-Type: text/html; charset=utf-8');
    $limit = (int) $limit;
    $ppk = (int) $ppk;
    $tab = max(0, (int) $tab);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Person/Credits Sync</title>';
    echo '<style>
body{margin:0;background:#0b0f14;color:#d7e0ea;font:15px/1.5 system-ui,Segoe UI,sans-serif}
.box{max-width:520px;margin:8vh auto;padding:28px;background:#121a24;border:1px solid #243041;border-radius:14px}
h1{margin:0 0 8px;font-size:22px;color:#fff}
p{color:#9aa8b8;margin:0 0 18px}
label{display:block;margin:12px 0 6px;color:#c5d0dc;font-size:13px}
input,select{width:100%;box-sizing:border-box;padding:10px 12px;border-radius:8px;border:1px solid #314255;background:#0b121a;color:#fff}
button{margin-top:18px;width:100%;padding:12px 14px;border:0;border-radius:10px;background:#e50914;color:#fff;font-weight:700;cursor:pointer;font-size:15px}
.note{margin-top:14px;font-size:12px;color:#7f8fa3}
.warn{color:#ffd166;font-size:13px;margin-bottom:12px}
</style></head><body><div class="box">';
    echo '<h1>Person / Credits sync</h1>';
    echo '<p>Browser runner — optional <code>tab</code> uses 2 TMDB keys each. Live progress in page.</p>';
    if (!$keyConfigured) {
        echo '<div class="warn">API_KEY / ADMIN_API_KEY not set in .env</div>';
    }
    echo '<form method="get">';
    echo '<input type="hidden" name="run" value="1">';
    echo '<label>API key</label><input type="password" name="key" required autocomplete="off" placeholder="X-API-Key value">';
    echo '<label>Worker tab (2 keys each)</label>';
    echo '<select name="tab">';
    echo '<option value="0"' . ($tab === 0 ? ' selected' : '') . '>All keys</option>';
    echo '<option value="1"' . ($tab === 1 ? ' selected' : '') . '>Tab 1 — keys 1–2</option>';
    echo '<option value="2"' . ($tab === 2 ? ' selected' : '') . '>Tab 2 — keys 3–4</option>';
    echo '<option value="3"' . ($tab === 3 ? ' selected' : '') . '>Tab 3 — keys 5–6</option>';
    echo '<option value="4"' . ($tab === 4 ? ' selected' : '') . '>Tab 4 — keys 7–8</option>';
    echo '</select>';
    echo '<label>Per-batch limit (auto-continues until empty)</label>';
    echo '<input type="number" name="limit" min="1" max="500" value="' . $limit . '">';
    echo '<label>Persons per TMDB key (then rotate)</label>';
    echo '<input type="number" name="ppk" min="1" max="100" value="' . $ppk . '">';
    echo '<label>Source</label>';
    echo '<select name="source">';
    echo '<option value="credits"' . ($source === 'credits' ? ' selected' : '') . '>credits only</option>';
    echo '<option value="credits,changes"' . ($source === 'credits,changes' ? ' selected' : '') . '>credits + changes</option>';
    echo '<option value="changes"' . ($source === 'changes' ? ' selected' : '') . '>changes only</option>';
    echo '</select>';
    echo '<button type="submit">Start sync</button>';
    echo '</form>';
    echo '<div class="note">Keep this tab open. Auto-refreshes until pending = 0.<br>';
    echo 'Open 4 browser tabs with tab=1..4 (8 keys in .env). Queue uses SKIP LOCKED — no double claim.<br>';
    echo 'HTTP 429 આવે તો તરત next key પર switch (within this tab\'s 2 keys).</div>';
    echo '</div></body></html>';
}

function out(string $cls, string $msg): void
{
    $time = gmdate('H:i:s');
    echo '<div class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '">['
        . $time . '] '
        . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')
        . '</div>';
    flush_now();
}

function flush_now(): void
{
    // Chunk for proxy flush — HTML comment so white-space:pre-wrap shows no blank lines.
    echo '<!--' . str_repeat('.', 256) . '-->';
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    @flush();
}

/** @param list<string> $sources */
function queueCounts(PDO $db, array $sources): array
{
    $in = placeholders($sources);
    $stmt = $db->prepare(
        "SELECT status, COUNT(*) AS cnt
         FROM media_sync_queue
         WHERE media_type = 'person' AND source IN ({$in})
         GROUP BY status"
    );
    $stmt->execute($sources);
    $by = ['pending' => 0, 'running' => 0, 'done' => 0, 'failed' => 0];
    $total = 0;
    foreach ($stmt->fetchAll() as $row) {
        $st = (string) $row['status'];
        $c = (int) $row['cnt'];
        $by[$st] = $c;
        $total += $c;
    }
    return [
        'pending' => $by['pending'] ?? 0,
        'running' => $by['running'] ?? 0,
        'done' => $by['done'] ?? 0,
        'failed' => $by['failed'] ?? 0,
        'total' => $total,
    ];
}

/** @param list<string> $sources */
function reclaimStuck(PDO $db, array $sources, int $minutes): int
{
    $in = placeholders($sources);
    $stmt = $db->prepare(
        "UPDATE media_sync_queue
         SET status = 'pending', started_at = NULL, worker_id = NULL
         WHERE media_type = 'person'
           AND source IN ({$in})
           AND status = 'running'
           AND started_at IS NOT NULL
           AND started_at < (UTC_TIMESTAMP() - INTERVAL {$minutes} MINUTE)"
    );
    $stmt->execute($sources);
    return $stmt->rowCount();
}

/**
 * @param list<string> $sources
 * @return array<string, mixed>|null
 */
function claimOne(PDO $db, array $sources): ?array
{
    $in = placeholders($sources);
    $selectSql = "SELECT id FROM media_sync_queue
         WHERE status = 'pending'
           AND attempts < ?
           AND media_type = 'person'
           AND source IN ({$in})
         ORDER BY id ASC
         LIMIT 1";
    $params = array_merge([MAX_ATTEMPTS], $sources);
    $id = null;

    try {
        $db->beginTransaction();
        $stmt = $db->prepare($selectSql . ' FOR UPDATE SKIP LOCKED');
        $stmt->execute($params);
        $rawId = $stmt->fetchColumn();
        if ($rawId === false) {
            $db->commit();
            return null;
        }
        $id = (int) $rawId;
        $db->prepare(
            "UPDATE media_sync_queue
             SET status = 'running',
                 attempts = attempts + 1,
                 started_at = UTC_TIMESTAMP(),
                 worker_id = 0
             WHERE id = ?"
        )->execute([$id]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        // Fallback without SKIP LOCKED
        $stmt = $db->prepare($selectSql);
        $stmt->execute($params);
        $rawId = $stmt->fetchColumn();
        if ($rawId === false) {
            return null;
        }
        $id = (int) $rawId;
        $upd = $db->prepare(
            "UPDATE media_sync_queue
             SET status = 'running',
                 attempts = attempts + 1,
                 started_at = UTC_TIMESTAMP(),
                 worker_id = 0
             WHERE id = ? AND status = 'pending'"
        );
        $upd->execute([$id]);
        if ($upd->rowCount() === 0) {
            return null;
        }
    }

    $row = $db->prepare(
        'SELECT id, media_type, tmdb_id, sync_day, source, attempts
         FROM media_sync_queue WHERE id = ? LIMIT 1'
    );
    $row->execute([$id]);
    $data = $row->fetch();
    return $data === false ? null : $data;
}

function markDone(PDO $db, int $id): void
{
    $db->prepare(
        "UPDATE media_sync_queue
         SET status = 'done', finished_at = UTC_TIMESTAMP(), last_error = NULL
         WHERE id = ?"
    )->execute([$id]);
}

function markFailed(PDO $db, int $id, int $attempts, string $error): void
{
    $status = $attempts >= MAX_ATTEMPTS ? 'failed' : 'pending';
    $db->prepare(
        "UPDATE media_sync_queue
         SET status = ?,
             finished_at = IF(? = 'failed', UTC_TIMESTAMP(), NULL),
             last_error = ?,
             worker_id = NULL
         WHERE id = ?"
    )->execute([$status, $status, mb_substr($error, 0, 2000), $id]);
}

/** @param list<string> $sources */
function placeholders(array $sources): string
{
    return implode(',', array_fill(0, count($sources), '?'));
}
