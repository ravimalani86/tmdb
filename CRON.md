# Daily Sync Cron (PHP only)

Live server par Python nathi — daily sync **PHP admin APIs** thi chale.

Base URL (live): `https://app.myappworld.in/tmdb/api`  
Auth header: `X-API-Key: <API_KEY or ADMIN_API_KEY>`

---

## 1. One-time setup

### Queue table

```bash
# MySQL ma once run karo
mysql -u USER -p DATABASE < scripts/migrations/create_media_sync_queue.sql
```

### Env (live `api/.env` or project root `.env`)

```env
API_KEY=your_live_api_key
# Optional separate admin key (defaults to API_KEY)
# ADMIN_API_KEY=your_admin_secret

# All 6 TMDB keys (comma-separated)
TMDB_API_KEYS=key1,key2,key3,key4,key5,key6

# Optional
# SYNC_PROVIDERS_FILE=providers_config.json
# TMDB_RATE_SLEEP_MS=40
```

Also ensure `providers_config.json` project root par present hoy.

### Deploy PHP files

- `api/TmdbClient.php`
- `api/SyncMediaWriter.php`
- `api/SyncEnqueueService.php`
- `api/SyncProcessService.php`
- `api/SyncAdminRepository.php`
- `api/config.php`, `api/index.php`

---

## 2. Admin endpoints

| Method | Path | Body | Purpose |
|--------|------|------|---------|
| `POST` | `/admin/sync/status` | `{}` or `{"day":"YYYY-MM-DD"}` | Queue stats |
| `POST` | `/admin/sync/enqueue` | `{}` or `{"day":"YYYY-MM-DD"}` | Fill today’s queue |
| `POST` | `/admin/sync/process` | `{"limit":5}` | Process N items (1–20) |
| `POST` | `/admin/sync/run` | `{"limit":5}` | Enqueue + first process batch |

**Sync day** = IST calendar “aaj” (default). Optional `day` override.

**Queue sources**

- TMDB `movie/changes` ∩ DB movies  
- TMDB `tv/changes` ∩ DB TV  
- Discover today + `providers_config` (release / first_air / air_date)  
- TMDB `person/changes` ∩ people in DB  
- After media sync: top cast (`order_index < 20`) + Director → person queue  

**Process** = full upsert movie / tv / person + related tables (credits, videos, images, keywords, similar, recommendations, providers; TV = full seasons).

---

## 3. Recommended cron (live)

Hosting timeout avoid karva mate **enqueue once**, pachhi **process repeatedly** until `pending=0`.

### Option A — two cron entries (recommended)

**1) Morning enqueue** (IST 06:00 ≈ UTC 00:30)

```cron
30 0 * * * curl -sS -X POST "https://app.myappworld.in/tmdb/api/admin/sync/enqueue" -H "Content-Type: application/json" -H "X-API-Key: YOUR_KEY" -d '{}' >> /home/USER/logs/tmdb-enqueue.log 2>&1
```

**2) Drain queue every 10 minutes**

```cron
*/10 * * * * curl -sS -X POST "https://app.myappworld.in/tmdb/api/admin/sync/process" -H "Content-Type: application/json" -H "X-API-Key: YOUR_KEY" -d '{"limit":5}' >> /home/USER/logs/tmdb-process.log 2>&1
```

`limit` tip:

- Shared hosting / short PHP timeout → `3`–`5`
- Better host → `8`–`10` (max `20`)

### Option B — cPanel “Cron Jobs” UI

1. **Enqueue** — once daily  
   Command:

   ```text
   curl -sS -X POST "https://app.myappworld.in/tmdb/api/admin/sync/enqueue" -H "Content-Type: application/json" -H "X-API-Key: YOUR_KEY" -d '{}'
   ```

2. **Process** — every 5–15 minutes  

   ```text
   curl -sS -X POST "https://app.myappworld.in/tmdb/api/admin/sync/process" -H "Content-Type: application/json" -H "X-API-Key: YOUR_KEY" -d '{"limit":5}'
   ```

### Option C — one-shot (manual / test)

```bash
curl -sS -X POST "https://app.myappworld.in/tmdb/api/admin/sync/run" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_KEY" \
  -d '{"limit":5}'
```

Note: `run` only processes first `limit` items. Baki mate `process` repeat karo.

---

## 4. Status check

```bash
curl -sS -X POST "https://app.myappworld.in/tmdb/api/admin/sync/status" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_KEY" \
  -d '{}'
```

Useful fields:

- `engine` → should be `"php"`
- `tmdb_keys` → should be `6` (or however many you set)
- `pending` / `running` / `done` / `failed`
- `recent` → last queue rows

When `pending=0` and `running=0`, aaj nu work complete.

---

## 5. Local test (XAMPP)

```bash
# Status
curl -sS -X POST "http://localhost/tmdb/api/admin/sync/status" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: tmdb_flutter_secret_123" \
  -d '{}'

# Enqueue
curl -sS -X POST "http://localhost/tmdb/api/admin/sync/enqueue" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: tmdb_flutter_secret_123" \
  -d '{}'

# Process one batch
curl -sS -X POST "http://localhost/tmdb/api/admin/sync/process" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: tmdb_flutter_secret_123" \
  -d '{"limit":3}'
```

---

## 6. Failure / retry behaviour

- Fail thay to item `pending` par wapas (attempts++)
- `attempts >= 3` → `failed`
- `last_error` ma reason store thay
- Stuck `running` rows: process claim logic / re-enqueue next day handle thay; zarurat hoy to SQL thi `running` → `pending` reset kari shako

```sql
-- Emergency: stuck running → pending
UPDATE media_sync_queue
SET status = 'pending', started_at = NULL
WHERE status = 'running'
  AND started_at < (NOW() - INTERVAL 30 MINUTE);
```

---

## 7. What this cron does NOT do

- Public TMDB ne app direct call nathi — only local PHP API
- Full catalog first-time sync nathi (use Python scripts locally for bulk bootstrap)
- Live par `scripts/cron_*.py` use na karo

---

## Quick checklist (go-live)

- [ ] `media_sync_queue` table created  
- [ ] `TMDB_API_KEYS` set (6 keys)  
- [ ] `providers_config.json` on server  
- [ ] Admin PHP files deployed  
- [ ] `POST /admin/sync/status` returns `engine: php`, `tmdb_keys: 6`  
- [ ] Enqueue cron + process cron configured  
- [ ] After first run, `pending` drops toward 0  
