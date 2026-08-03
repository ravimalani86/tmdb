# TMDB Local Mirror

Local movie & TV catalog powered by [TMDB](https://www.themoviedb.org/) API.  
Data syncs into **MySQL (XAMPP)** via Python, then serves to your app through a **Core PHP REST API**.

---

## Stack

| Layer | Tech |
|-------|------|
| Sync engine | Python 3, SQLAlchemy, requests |
| Database | MySQL (`tmdbdata`) |
| API | Core PHP (PDO) |
| Config | `.env`, `providers_config.json` |

---

## Prerequisites

- **XAMPP** — Apache + MySQL running
- **Python 3** — `py` launcher (Windows Git Bash)
- **TMDB API key** — [themoviedb.org/settings/api](https://www.themoviedb.org/settings/api)

---

## Setup

### 1. Install Python dependencies

```bash
cd /d/tmdb
py -m pip install -r requirements.txt
```

### 2. Configure environment

Copy `.env` and set your values:

```env
DB_HOST=localhost
DB_PORT=3306
DB_USER=root
DB_PASSWORD=
DB_NAME=tmdbdata

TMDB_API_KEY=your_api_key
TMDB_API_READ_ACCESS_TOKEN=your_read_token

# Multi-key pool (detail + seasons workers). Comma-separated, paired by index.
TMDB_API_KEYS=key1,key2,key3,key4,key5
TMDB_API_READ_ACCESS_TOKENS=token1,token2,token3,token4,token5
SYNC_WORKERS=5

RATE_LIMIT_SLEEP=0.25
SYNC_MOVIE_LITE=true
SYNC_PROVIDERS_FILE=providers_config.json
WITH_WATCH_MONETIZATION_TYPES=flatrate
```

Keys vadhare → `.env` ma list ma add karo; `SYNC_WORKERS` optional (default = key count).

### 3. Create database tables

```bash
py scripts/create_tables.py
```

### 4. Sync genres (run once first)

```bash
py scripts/sync_genres.py
```

---

## Sync workflow

**Recommended order**

1. `sync_movies_detail` (workers + key pool — one process)
2. Movie individual phases in parallel (one key each via `SYNC_KEY_INDEX`)
3. `sync_tv_detail` (workers)
4. TV individual phases in parallel
5. `sync_tv_seasons` last (workers) — never with credits

Every phase saves a **checkpoint** — if interrupted, re-run the same script to resume.

### Multi-key parallel phases

Detail + seasons use `TMDB_API_KEYS` automatically (`SYNC_WORKERS`).

Individual scripts (credits, providers, …) — separate terminals, different key index:

```bash
# Git Bash / Linux
SYNC_KEY_INDEX=0 py scripts/sync_movies_credits.py &
SYNC_KEY_INDEX=1 py scripts/sync_movies_providers.py &
SYNC_KEY_INDEX=2 py scripts/sync_movies_similar.py &
SYNC_KEY_INDEX=3 py scripts/sync_movies_videos.py &
wait
```

Do **not** run detail/seasons together with parallel individual scripts. Max parallel individual scripts ≈ number of keys.

### Provider filter

When `SYNC_PROVIDERS_FILE=providers_config.json` is set, **Phase 1** (movies & TV detail) uses TMDB `discover` API to sync only content on your configured providers (Netflix, Prime, Hotstar, etc.) across **US, IN, JP**.

Edit providers in [`providers_config.json`](providers_config.json).

Optional — sync one region only:

```env
SYNC_REGIONS=IN
```

---

### Movies

| Phase | Script | What it syncs |
|-------|--------|---------------|
| 1 | `py scripts/sync_movies_detail.py` | Show list + `GET /movie/{id}` — poster, banner, rating, genres (**multi-key workers**) |
| 2 | `py scripts/sync_movies_credits.py` | Cast & crew (`GET /movie/{id}/credits`) |
| 3 | `py scripts/sync_movies_providers.py` | Watch providers (`GET /movie/{id}/watch/providers`) |
| 4 | `py scripts/sync_movies_similar.py` | Similar movies (`GET /movie/{id}/similar`) |
| 5 | `py scripts/sync_movies_videos.py` | Trailers & clips (`GET /movie/{id}/videos`) |
| 6 | `py scripts/sync_movies_images.py` | Extra posters & backdrops (`GET /movie/{id}/images`) |
| 7 | `py scripts/sync_movies_keywords.py` | Keywords/tags (`GET /movie/{id}/keywords`) |
| 8 | `py scripts/sync_movies_recommendations.py` | Recommendations (`GET /movie/{id}/recommendations`) |

```bash
py scripts/sync_movies_detail.py
# then parallel with SYNC_KEY_INDEX=0..n
py scripts/sync_movies_credits.py
py scripts/sync_movies_providers.py
py scripts/sync_movies_similar.py
py scripts/sync_movies_videos.py
py scripts/sync_movies_images.py
py scripts/sync_movies_keywords.py
py scripts/sync_movies_recommendations.py
```

Phases 5–8 are **optional** — exposed in PHP API as `videos`, `images`, `keywords`, `recommendations` on detail + split endpoints.

**Spoken languages** are saved during Phase 1 (detail sync). Re-run detail sync to backfill existing movies/TV:

```bash
py scripts/sync_movies_detail.py
py scripts/sync_tv_detail.py
```

---

### TV Shows

| Phase | Script | What it syncs |
|-------|--------|---------------|
| 1 | `py scripts/sync_tv_detail.py` | Show list + `GET /tv/{id}` — poster, banner, rating, genres (**multi-key workers**) |
| 2 | `py scripts/sync_tv_credits.py` | Cast & crew via aggregate_credits (includes episode_count per character) |

> First time after this update: `py scripts/add_credits_episode_count.py`, then re-run phase 2 so existing TV cast gets episode counts.
| 3 | `py scripts/sync_tv_providers.py` | Watch providers |
| 4 | `py scripts/sync_tv_similar.py` | Similar TV shows |
| 5 | `py scripts/sync_tv_seasons.py` | Seasons + episodes + episode guest cast/crew (**multi-key workers** — run last) |
| 6 | `py scripts/sync_tv_videos.py` | Trailers & clips |
| 7 | `py scripts/sync_tv_images.py` | Extra posters & backdrops |
| 8 | `py scripts/sync_tv_keywords.py` | Keywords/tags |
| 9 | `py scripts/sync_tv_recommendations.py` | Recommendations |

```bash
py scripts/sync_tv_detail.py
# then parallel with SYNC_KEY_INDEX=0..n
py scripts/sync_tv_credits.py
py scripts/sync_tv_providers.py
py scripts/sync_tv_similar.py
py scripts/sync_tv_videos.py
py scripts/sync_tv_images.py
py scripts/sync_tv_keywords.py
py scripts/sync_tv_recommendations.py
# last
py scripts/sync_tv_seasons.py
```

**All TV phases at once (optional, sequential — no parallel keys):**

```bash
py scripts/sync_tv.py
```

---

## PHP API

Place project in XAMPP `htdocs` (e.g. `C:\xampp\htdocs\tmdb`).

**Production:** `https://tmdb.growdevinfotech.in/api/`  
**Local:** `http://localhost/tmdb/api/`

| Endpoint | Description |
|----------|-------------|
| `POST /movies` | Movie list with filters |
| `POST /movies/{tmdb_id}` | Movie detail (+ videos, images, keywords, recommendations) |
| `POST /tv` | TV list with filters |
| `POST /tv/{tmdb_id}` | TV detail (+ seasons, videos, images, keywords, recommendations) |
| `POST /tv/{tmdb_id}/season/{n}/episode/{e}` | Episode detail (+ guest cast, crew) |
| `POST /movies\|tv/{tmdb_id}/videos` | Trailers only |
| `POST /movies\|tv/{tmdb_id}/images` | Image gallery only |
| `POST /movies\|tv/{tmdb_id}/keywords` | Keywords only |
| `POST /movies\|tv/{tmdb_id}/recommendations` | Recommendations only |
| `POST /genres?type=movie\|tv` | Genre filters |
| `POST /providers?type=movie\|tv&country=US` | Provider filters |
| `POST /user-state/watch` | Mark/unmark watched (per device) |
| `POST /user-state/save-for-later` | Mark/unmark save-for-later (per device) |

- List/detail endpoints accept optional `device_id` inside the JSON body to return per-device flags: `is_watched` and `is_saved_for_later`.
 
Security (required): send header `X-API-Key` with your configured API key (`API_KEY` in `api/.env`).

Full API reference → [`API.md`](API.md)

---

## Documentation

| File | Contents |
|------|----------|
| [`API.md`](API.md) | REST API endpoints, params, response examples |
| [`APP_LAYOUT.md`](APP_LAYOUT.md) | Mobile app screens, navigation, UI layout |
| [`TMDB_LOCAL_DB_SYNC.md`](TMDB_LOCAL_DB_SYNC.md) | Database schema & sync architecture |

---

## Project structure

```
tmdb/
├── api/                  # Core PHP REST API
├── scripts/              # Sync scripts (run these)
├── services/             # TMDB client & sync logic
├── models/               # SQLAlchemy models
├── repositories/         # DB upsert helpers
├── utils/                # Checkpoints, rate limiter, provider config
├── providers_config.json # Your streaming provider list
├── .env                  # DB & TMDB credentials
├── API.md
├── APP_LAYOUT.md
└── README.md
```

---

## Useful commands

```bash
# List TMDB provider IDs for your config
py scripts/list_providers.py

# All-in-one movie sync (not recommended — use phased scripts)
py scripts/sync_movies.py

# Full sync (genres + movies + TV + people)
py scripts/sync_all.py
```

---

## Tips

- Use **forward slashes** in Git Bash: `py scripts/sync_movies_detail.py`
- **Rate limit:** `RATE_LIMIT_SLEEP=0.25` = 4 req/sec **per key**. With `TMDB_API_KEYS` + `SYNC_WORKERS`, detail/seasons run ~N× faster.
- **Check progress:** scripts log `API CALL ->` and `API OK <-` for every TMDB request
- **Resume:** re-run the same script after interruption — checkpoint picks up where it left off
- **API reads `.env`** from project root — same DB settings as Python sync
