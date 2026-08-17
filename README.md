# TMDB Local API

Local movie & TV catalog for **Movflik**. Data lives in MySQL; Flutter talks only to this **PHP API** (never public TMDB). Daily catalog refresh is **PHP admin sync** + cron.

| Layer | Tech |
|-------|------|
| API | Core PHP (PDO) |
| Database | MySQL (`tmdbdata`) |
| Sync | PHP admin endpoints + server cron |
| Config | `api/.env`, `providers_config.json` |
| Ops UI | `api/admin-sync.html`, `api/admin-apps.html`, `api/admin-remote-config.html` |

---

## URLs

| Env | Base |
|-----|------|
| Live | `https://app.myappworld.in/tmdb/api` |
| Local (XAMPP) | `http://localhost/tmdb/api` |

**Auth (all endpoints):** header `X-API-Key: <API_KEY>`  
Admin sync also accepts `ADMIN_API_KEY` (defaults to `API_KEY`).

**Method:** `POST` only. JSON body. No query-string filters.

---

## Setup

### 1. Env

Create **`api/.env`** (this is the only env file the PHP API reads):

```env
DB_HOST=localhost
DB_PORT=3306
DB_USER=root
DB_PASSWORD=
DB_NAME=tmdbdata
PUBLIC_BASE_URL=http://localhost/tmdb

API_CORS_ORIGIN=*
API_KEY=tmdb_flutter_secret_123
# Optional separate admin key (defaults to API_KEY)
# ADMIN_API_KEY=your_admin_secret

TMDB_API_KEY=your_primary_tmdb_key
TMDB_API_KEYS=key1,key2,key3,key4,key5,key6
RATE_LIMIT_SLEEP=0.2

# Optional Firebase Remote Config admin
# FIREBASE_PROJECT_ID=movflik
# FIREBASE_REMOTE_CONFIG_KEY=movflik_config
# FIREBASE_SERVICE_ACCOUNT_PATH=firebase-service-account.json
```

Do **not** put a `.env` in the project root — it is ignored. Live and local both use `api/.env`.

### 2. Files on server

- Entire `api/` folder (PHP + `.htaccess` + `api/.env`)
- Root `providers_config.json`
- `logos/` (provider PNGs)
- `privacy-policy.html` (Play Store / public page)

### 3. Queue table (once)

```sql
CREATE TABLE IF NOT EXISTS media_sync_queue (
  id INT AUTO_INCREMENT PRIMARY KEY,
  media_type VARCHAR(16) NOT NULL,
  tmdb_id INT NOT NULL,
  sync_day VARCHAR(10) NOT NULL COMMENT 'YYYY-MM-DD (IST calendar day)',
  source VARCHAR(32) NOT NULL DEFAULT 'changes',
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  attempts INT NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  worker_id INT NULL,
  enqueued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  UNIQUE KEY uq_media_sync_queue_day_item (media_type, tmdb_id, sync_day),
  KEY ix_media_sync_queue_status (status),
  KEY ix_media_sync_queue_day (sync_day),
  KEY ix_media_sync_queue_type (media_type),
  KEY ix_media_sync_queue_tmdb (tmdb_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 4. App JSON table (once)

Auto-created on first admin/apps or `/app-config` call. Optional SQL:

```sql
CREATE TABLE IF NOT EXISTS app_configs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  app_id VARCHAR(64) NOT NULL,
  app_name VARCHAR(120) NOT NULL,
  package_name VARCHAR(191) NOT NULL,
  config_json LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_app_configs_app_id (app_id),
  UNIQUE KEY uq_app_configs_package (package_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 5. Smoke test

```bash
curl -sS -X POST "http://localhost/tmdb/api/" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: tmdb_flutter_secret_123" \
  -d '{}'
```

Returns API name, version, and endpoint list.

---

## API reference

Replace `BASE` with live or local URL. Replace `YOUR_KEY` with `API_KEY`.

### Meta

| Endpoint | Body | Notes |
|----------|------|--------|
| `POST /` | `{}` | Health + endpoint list |

```bash
curl -sS -X POST "BASE/" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_KEY" \
  -d '{}'
```

---

### Home feed

| Endpoint | Body fields | Notes |
|----------|-------------|--------|
| `POST /home/bootstrap` | `filter`, `country`, `device_id?` | Fast first paint: `hero` + bootstrap `rows` + `genres` + `secondary_rows` list |
| `POST /home/row` | `filter`, `row`, `country`, `device_id?` | One row → `{ filter, country, row, data }` |
| `POST /home/feed` | `filter`, `country`, `device_id?` | Legacy full feed (prefer bootstrap + row) |

`filter`: `all` \| `movies` \| `tv`  
`country`: `"IN"` or `["IN"]` (default `ALL` if omitted)  
`device_id` optional; needed for `my_list` / user flags.

**Bootstrap `rows`:** `home_slider`, `trending_movies`, `trending_tv`, `top_10`, `new_releases`, `my_list`
**`/home/row` keys:** those plus `profile_slider`, `home_slider_anime`, `recently_added`, `international_films`, `this_month`, `top_20_series`, `hindi`, `tamil`, `telugu`, `malayalam`, `action`, `thriller`, `crime`, `drama`, `comedy`, `romance`, `horror`, `animation`, `scifi`, `on_netflix`, `jiohotstar`, `prime_video`, `zee5`, `top_rated`

Slider rows use strict rolling windows and return at most five available titles without widening the window:

- `profile_slider`: movies + TV first aired/released in the last 30 days, popularity descending, poster required.
- `home_slider`: last 30 days, vote count at least 20, popularity descending, backdrop preferred. Respects `all`, `movies`, and `tv`.
- `home_slider_anime`: same as `home_slider`, restricted to Animation genre (TMDB genre 16).

For TV, “released” means the show’s `first_air_date`; a new season or episode of an older show does not qualify.

Editorial rows:

- `recently_added`: movies + TV released in the last 30 days, newest release date first.
- `international_films`: English, Korean, Japanese, Spanish, and French movies ordered by popularity.
- `this_month`: current-calendar-month movies + TV with at least 20 votes, ordered by popularity.
- `top_20_series`: 20 currently popular TV series.

```bash
# Bootstrap
curl -sS -X POST "BASE/home/bootstrap" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_KEY" \
  -d '{"filter":"all","country":"IN","device_id":"device-uuid"}'

# Single row
curl -sS -X POST "BASE/home/row" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_KEY" \
  -d '{"filter":"all","country":"IN","row":"hindi"}'
```

---

### Movies

| Endpoint | Body |
|----------|------|
| `POST /movies` | List + filters (below) |
| `POST /movies/{tmdb_id}` | `device_id?` |
| `POST /movies/{tmdb_id}/credits` | `{}` |
| `POST /movies/{tmdb_id}/providers` | `{}` |
| `POST /movies/{tmdb_id}/similar` | `{}` |
| `POST /movies/{tmdb_id}/videos` | `{}` |
| `POST /movies/{tmdb_id}/images` | `{}` |
| `POST /movies/{tmdb_id}/keywords` | `{}` |
| `POST /movies/{tmdb_id}/recommendations` | `{}` |

**List filters (`/movies`):**

| Field | Example | Notes |
|-------|---------|--------|
| `page` | `1` | |
| `limit` | `20` | 1–50 |
| `sort` | `popularity` | `vote_average` \| `release_date` \| `title` |
| `order` | `desc` | `asc` \| `desc` |
| `search` | `"avatar"` | |
| `genre_id` | `28` or `[28,35]` | |
| `provider_id` | `8` or `[8,9]` | Netflix = 8 |
| `country` | `"IN"` or `["IN"]` | |
| `original_language` | `"hi"` | also `"hi\|en"` or `["hi","en"]` |
| `spoken_language` | `"hi"` | |
| `vote_average_gte` | `7` | |
| `vote_count_gte` | `100` | |
| `release_date_gte` | `"2024-01-01"` | |
| `release_date_lte` | `"2026-12-31"` | |
| `released_only` | `true` | |
| `device_id` | `"uuid"` | required with saved/watched |
| `saved_only` | `true` | |
| `watched_only` | `true` | |
| `include_total` | `false` | |

```bash
# Popular Hindi action on Netflix (IN)
curl -sS -X POST "BASE/movies" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_KEY" \
  -d '{
    "page": 1,
    "limit": 20,
    "sort": "popularity",
    "order": "desc",
    "genre_id": 28,
    "provider_id": 8,
    "country": "IN",
    "original_language": "hi",
    "released_only": true
  }'

# Detail
curl -sS -X POST "BASE/movies/19995" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_KEY" \
  -d '{"device_id":"device-uuid"}'

# Trailers
curl -sS -X POST "BASE/movies/19995/videos" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_KEY" \
  -d '{}'
```

---

### TV

| Endpoint | Body |
|----------|------|
| `POST /tv` | Same filters as movies; `sort` = `popularity` \| `vote_average` \| `first_air_date` \| `name`; dates = `first_air_date_gte` / `first_air_date_lte` |
| `POST /tv/{tmdb_id}` | `device_id?` |
| `POST /tv/{tmdb_id}/credits` | `{}` |
| `POST /tv/{tmdb_id}/providers` | `{}` |
| `POST /tv/{tmdb_id}/similar` | `{}` |
| `POST /tv/{tmdb_id}/seasons` | `{}` |
| `POST /tv/{tmdb_id}/season/{n}/episode/{m}` | `{}` |
| `POST /tv/{tmdb_id}/videos` | `{}` |
| `POST /tv/{tmdb_id}/images` | `{}` |
| `POST /tv/{tmdb_id}/keywords` | `{}` |
| `POST /tv/{tmdb_id}/recommendations` | `{}` |

```bash
curl -sS -X POST "BASE/tv" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_KEY" \
  -d '{"page":1,"limit":20,"sort":"popularity","country":"IN","provider_id":8}'

curl -sS -X POST "BASE/tv/1396/season/1/episode/1" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_KEY" \
  -d '{}'
```

---

### People / genres / providers

| Endpoint | Body |
|----------|------|
| `POST /people/{tmdb_id}` | `{}` — profile + movie/TV filmography |
| `POST /genres` | `type`: `movie` \| `tv` (default `movie`) |
| `POST /providers` | `type`: `movie` \| `tv`; `country`: `"IN"` or `["IN","US"]` |

```bash
# Person detail (e.g. 287 = Brad Pitt)
curl -sS -X POST "BASE/people/287" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_KEY" \
  -d '{}'

# Genres (movie|tv)
curl -sS -X POST "BASE/genres" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_KEY" \
  -d '{"type":"movie"}'

# Providers for country
curl -sS -X POST "BASE/providers" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_KEY" \
  -d '{"type":"movie","country":["IN","US"]}'
```

---

### User state (My List / watched)

```bash
# Mark watched
curl -sS -X POST "BASE/user-state/watch" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_KEY" \
  -d '{"device_id":"device-uuid","media_type":"movie","tmdb_id":19995,"is_watched":true}'

# Save for later
curl -sS -X POST "BASE/user-state/save-for-later" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_KEY" \
  -d '{"device_id":"device-uuid","media_type":"tv","tmdb_id":1396,"is_saved_for_later":true}'

# List saved / watched
curl -sS -X POST "BASE/user-state/list" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_KEY" \
  -d '{"device_id":"device-uuid","filter":"saved","page":1,"limit":20}'
```

`filter`: `saved` \| `watched` \| `all`  
Optional `media_type`: `movie` \| `tv`

---

### App config (per-app JSON)

Returns **only** the JSON saved for that `app_id` (no wrapper). Unknown id → 404.

| Endpoint | Body | Notes |
|----------|------|--------|
| `POST /app-config` | `app_id` | Flutter / public (`API_KEY`) |

```bash
curl -sS -X POST "BASE/app-config" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_KEY" \
  -d '{"app_id":"movflik"}'
```

Empty store returns `{}`.

---

## Admin sync API

Base path: `/admin/sync/*`  
Auth: `X-API-Key` must match `ADMIN_API_KEY` (or `API_KEY`).

| Endpoint | Body | Purpose |
|----------|------|---------|
| `POST /admin/sync/status` | `{}` or `{"day":"YYYY-MM-DD"}` | Queue stats |
| `POST /admin/sync/queue-overview` | `{}` | Broader queue view |
| `POST /admin/sync/config` | `{}` | Read cron process config |
| `POST /admin/sync/config/save` | `{"limit":5,"media_type":"movie"?}` | Save process limit / type |
| `POST /admin/sync/enqueue` | `{}` or `{"day":"YYYY-MM-DD"}` | Fill today’s queue |
| `POST /admin/sync/tv-gaps` | `{}` | TV season/episode completeness counts |
| `POST /admin/sync/enqueue-tv-gaps` | `{}` or `{"limit":500,"day"?}` | Queue incomplete TV (`source=backfill`, popularity first; omit `limit` = all) |
| `POST /admin/sync/process` | `{"limit":5,"media_type":"tv"?}` | Process N items (1–450) |
| `POST /admin/sync/run` | `{"limit":5,"day"?,"media_type"?}` | Enqueue + first process batch |
| `POST /admin/sync/lookup` | `{"media_type":"movie","tmdb_id":12345}` | TMDB preview + MySQL synced? |
| `POST /admin/sync/item` | `{"media_type":"movie","tmdb_id":12345}` | Insert or full-update one item |

**Test order:** status → enqueue → process (repeat until `pending=0`) → status again.  
**Sync day** = IST calendar “today” unless `day` override (empty `day` = today).  
**Queue sources:** TMDB movie/tv/person changes ∩ DB + discover from `providers_config.json` + related people after media sync + `backfill` (incomplete TV seasons/episodes).  
**Enqueue** can take 1–3 minutes. After movie/TV sync, related people may be enqueued.  
**TV gaps:** ~8k shows may lack seasons. Use `/admin/sync/enqueue-tv-gaps` then process with `media_type=tv` and `source=backfill`, limit 3–5.  
**Process:** full upsert (credits, videos, images, keywords, similar, recommendations, providers; TV = seasons). `limit` is 1–450.  
**Lookup / item:** admin UI block on `admin-sync.html` — Find one id on TMDB, show synced vs not synced, then insert or full-update that row only (no related-people enqueue).

### Real examples (local)

```bash
# Status
curl -sS -X POST "http://localhost/tmdb/api/admin/sync/status" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: tmdb_flutter_secret_123" \
  -d '{}'

# Enqueue today
curl -sS -X POST "http://localhost/tmdb/api/admin/sync/enqueue" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: tmdb_flutter_secret_123" \
  -d '{}'

# TV season/episode gaps
curl -sS -X POST "http://localhost/tmdb/api/admin/sync/tv-gaps" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: tmdb_flutter_secret_123" \
  -d '{}'

# Queue incomplete TV (omit limit = all; popularity first)
curl -sS -X POST "http://localhost/tmdb/api/admin/sync/enqueue-tv-gaps" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: tmdb_flutter_secret_123" \
  -d '{"limit":500}'

# Process batch
curl -sS -X POST "http://localhost/tmdb/api/admin/sync/process" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: tmdb_flutter_secret_123" \
  -d '{"limit":5}'

# One-shot enqueue + first batch
curl -sS -X POST "http://localhost/tmdb/api/admin/sync/run" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: tmdb_flutter_secret_123" \
  -d '{"limit":5}'

# Save preferred process limit
curl -sS -X POST "http://localhost/tmdb/api/admin/sync/config/save" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: tmdb_flutter_secret_123" \
  -d '{"limit":5}'

# Lookup one TMDB id (preview + synced flag)
curl -sS -X POST "http://localhost/tmdb/api/admin/sync/lookup" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: tmdb_flutter_secret_123" \
  -d '{"media_type":"movie","tmdb_id":19995}'

# Insert or full-update that item
curl -sS -X POST "http://localhost/tmdb/api/admin/sync/item" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: tmdb_flutter_secret_123" \
  -d '{"media_type":"movie","tmdb_id":19995}'
```

### Real examples (live)

```bash
curl -sS -X POST "https://app.myappworld.in/tmdb/api/admin/sync/status" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_KEY" \
  -d '{}'

curl -sS -X POST "https://app.myappworld.in/tmdb/api/admin/sync/enqueue" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_KEY" \
  -d '{}'

curl -sS -X POST "https://app.myappworld.in/tmdb/api/admin/sync/process" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_KEY" \
  -d '{"limit":5}'
```

Useful status fields: `engine` (`php`), `tmdb_keys`, `pending` / `running` / `done` / `failed`, `recent`.

When `pending=0` and `running=0`, today’s work is done.

---

## Cron jobs (live)

Enqueue **once** daily, then **process repeatedly** until queue empty (avoids PHP timeouts).

### Recommended (two crons)

**1) Morning enqueue** — IST 06:00 ≈ UTC 00:30

```cron
30 0 * * * curl -sS -X POST "https://app.myappworld.in/tmdb/api/admin/sync/enqueue" -H "Content-Type: application/json" -H "X-API-Key: YOUR_KEY" -d '{}' >> /home/USER/logs/tmdb-enqueue.log 2>&1
```

**2) Drain queue every 10 minutes**

```cron
*/10 * * * * curl -sS -X POST "https://app.myappworld.in/tmdb/api/admin/sync/process" -H "Content-Type: application/json" -H "X-API-Key: YOUR_KEY" -d '{"limit":5}' >> /home/USER/logs/tmdb-process.log 2>&1
```

`limit` tip: shared hosting `3`–`5`; stronger host `8`–`10`.

### cPanel Cron Jobs UI

1. **Enqueue** — once daily  

```text
curl -sS -X POST "https://app.myappworld.in/tmdb/api/admin/sync/enqueue" -H "Content-Type: application/json" -H "X-API-Key: YOUR_KEY" -d '{}'
```

2. **Process** — every 5–15 minutes  

```text
curl -sS -X POST "https://app.myappworld.in/tmdb/api/admin/sync/process" -H "Content-Type: application/json" -H "X-API-Key: YOUR_KEY" -d '{"limit":5}'
```

### Manual one-shot

```bash
curl -sS -X POST "https://app.myappworld.in/tmdb/api/admin/sync/run" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_KEY" \
  -d '{"limit":5}'
```

`run` only processes the first `limit` items — keep calling `process` for the rest.

---

## Admin UI

Browser UIs (same admin API key):

| UI | URL |
|----|-----|
| Sync queue | Local: `http://localhost/tmdb/api/admin-sync.html` · Live: `https://app.myappworld.in/tmdb/api/admin-sync.html` |
| App JSON | Local: `http://localhost/tmdb/api/admin-apps.html` · Live: `https://app.myappworld.in/tmdb/api/admin-apps.html` |
| Movflik Remote Config | Local: `http://localhost/tmdb/api/admin-remote-config.html` · Live: `https://app.myappworld.in/tmdb/api/admin-remote-config.html` |

### App JSON admin (MySQL)

Create/edit apps (`app_name`, `app_id`, `package_name`, JSON). Flutter reads via `POST /app-config`. Separate from Firebase Remote Config.

**Admin API** (`ADMIN_API_KEY`)

| Endpoint | Body | Purpose |
|----------|------|---------|
| `POST /admin/apps/list` | `{}` | All apps |
| `POST /admin/apps/get` | `{"app_id":"movflik"}` | One app |
| `POST /admin/apps/create` | `app_name`, `app_id`, `package_name`, `config` or `raw` | Create |
| `POST /admin/apps/update` | same; lookup by `app_id` | Edit (`app_id` not changed) |

```bash
# List
curl -sS -X POST "http://localhost/tmdb/api/admin/apps/list" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: tmdb_flutter_secret_123" \
  -d '{}'

# Create
curl -sS -X POST "http://localhost/tmdb/api/admin/apps/create" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: tmdb_flutter_secret_123" \
  -d '{"app_name":"Movflik","app_id":"movflik","package_name":"com.company.movflik","config":{"ads":true}}'
```

### Firebase Remote Config admin (Movflik JSON)

Publishes parameter `movflik_config` so the Flutter app refreshes without a store update.

**One-time Firebase setup**

1. Firebase Console → Project settings → Service accounts → **Generate new private key**
2. Save the JSON on the server as `api/firebase-service-account.json` (gitignored)
3. In Google Cloud Console → IAM, ensure that service account can use **Firebase Remote Config Admin** (or Owner/Editor on the project)
4. Add to `api/.env`:

```env
FIREBASE_PROJECT_ID=movflik
FIREBASE_REMOTE_CONFIG_KEY=movflik_config
# Optional if not using default path api/firebase-service-account.json
# FIREBASE_SERVICE_ACCOUNT_PATH=firebase-service-account.json
```

**Admin API**

| Endpoint | Body | Purpose |
|----------|------|---------|
| `POST /admin/remote-config/status` | `{}` | Setup check |
| `POST /admin/remote-config/get` | `{}` | Load current `movflik_config` |
| `POST /admin/remote-config/save` | `{"config":{...},"bump_version":true}` | Publish to Firebase |

```bash
# Status
curl -sS -X POST "http://localhost/tmdb/api/admin/remote-config/status" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: tmdb_flutter_secret_123" \
  -d '{}'

# Load
curl -sS -X POST "http://localhost/tmdb/api/admin/remote-config/get" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: tmdb_flutter_secret_123" \
  -d '{}'
```

---

## Failure / retry

- Failed item → back to `pending` (`attempts++`)
- `attempts >= 3` → `failed` (`last_error` set)
- Stuck `running` rows — emergency reset:

```sql
UPDATE media_sync_queue
SET status = 'pending', started_at = NULL
WHERE status = 'running'
  AND started_at < (NOW() - INTERVAL 30 MINUTE);
```

---

## Project layout

```text
tmdb/
  api/                      # PHP REST, admin UIs, api/.env
  logos/                    # Local provider logos
  providers_config.json     # Discover sync provider list
  privacy-policy.html
  README.md
```

Per-app JSON is stored in MySQL (`app_configs`) and served by `POST /app-config`. Movflik can still use **Firebase Remote Config** separately.

---

## Go-live checklist

- [ ] `api/.env` on server (DB, `API_KEY`, `TMDB_API_KEYS`)  
- [ ] `media_sync_queue` table created  
- [ ] `app_configs` table created (or first `/admin/apps` call)  
- [ ] `providers_config.json` on server  
- [ ] `api/` deployed  
- [ ] `POST /admin/sync/status` → `engine: php`, keys > 0  
- [ ] Enqueue cron + process cron configured  
- [ ] After first run, `pending` drops toward 0  
