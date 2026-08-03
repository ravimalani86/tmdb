import os
from dotenv import load_dotenv

load_dotenv()

DB_HOST = os.getenv("DB_HOST", "localhost")
DB_PORT = int(os.getenv("DB_PORT", "3306"))
DB_USER = os.getenv("DB_USER", "root")
DB_PASSWORD = os.getenv("DB_PASSWORD", "")
DB_NAME = os.getenv("DB_NAME", "tmdbdata")

TMDB_BASE_URL = os.getenv("TMDB_BASE_URL", "https://api.themoviedb.org/3")


def _split_csv(value: str | None) -> list[str]:
    if not value:
        return []
    return [part.strip() for part in value.split(",") if part.strip()]


def _load_tmdb_credentials() -> list[tuple[str, str]]:
    """[(api_key, read_access_token), ...] — tokens may be empty (api_key-only auth)."""
    keys = _split_csv(os.getenv("TMDB_API_KEYS", ""))
    tokens = _split_csv(os.getenv("TMDB_API_READ_ACCESS_TOKENS", ""))

    if not keys:
        single_key = os.getenv("TMDB_API_KEY", "").strip()
        single_token = os.getenv("TMDB_API_READ_ACCESS_TOKEN", "").strip()
        if single_key:
            return [(single_key, single_token)]
        return []

    creds: list[tuple[str, str]] = []
    for i, key in enumerate(keys):
        token = tokens[i] if i < len(tokens) else ""
        creds.append((key, token))
    return creds


TMDB_CREDENTIALS = _load_tmdb_credentials()
# Backward-compatible singles (first credential / env override)
TMDB_API_KEY = os.getenv("TMDB_API_KEY", "").strip() or (
    TMDB_CREDENTIALS[0][0] if TMDB_CREDENTIALS else ""
)
TMDB_API_READ_ACCESS_TOKEN = os.getenv("TMDB_API_READ_ACCESS_TOKEN", "").strip() or (
    TMDB_CREDENTIALS[0][1] if TMDB_CREDENTIALS else ""
)

# Optional: pick one key from the pool for individual phase scripts (0-based)
_SYNC_KEY_INDEX_RAW = os.getenv("SYNC_KEY_INDEX", "").strip()
SYNC_KEY_INDEX = int(_SYNC_KEY_INDEX_RAW) if _SYNC_KEY_INDEX_RAW != "" else None

# Seasons sync rotates only these credential indexes (leave 4+ for other phases).
_raw_season_keys = os.getenv("SYNC_SEASON_KEY_INDEXES", "0,1,2,3").strip()
SYNC_SEASON_KEY_INDEXES = [
    int(part.strip()) for part in _raw_season_keys.split(",") if part.strip() != ""
] or [0, 1, 2, 3]

# Seasons parallel DB writers (default 2). Set 1 to disable parallelism.
_raw_season_workers = os.getenv("SYNC_SEASON_WORKERS", "2").strip()
SYNC_SEASON_WORKERS = max(1, int(_raw_season_workers or "2"))

CHUNK_SIZE = int(os.getenv("CHUNK_SIZE", "100"))
RATE_LIMIT_SLEEP = float(os.getenv("RATE_LIMIT_SLEEP", "0.25"))
MAX_RETRIES = int(os.getenv("MAX_RETRIES", "5"))
SYNC_MAX_PAGES = int(os.getenv("SYNC_MAX_PAGES", "0")) or None
SYNC_MOVIE_LITE = os.getenv("SYNC_MOVIE_LITE", "false").lower() in ("1", "true", "yes")

# Workers for detail + seasons (capped by number of credentials)
_raw_workers = os.getenv("SYNC_WORKERS", "").strip()
if _raw_workers:
    SYNC_WORKERS = max(1, int(_raw_workers))
else:
    SYNC_WORKERS = max(1, len(TMDB_CREDENTIALS) or 1)

# Filter movies by streaming provider (discover API). Example: 8|119 = Netflix OR Prime
SYNC_PROVIDER_IDS = os.getenv("SYNC_PROVIDER_IDS", "").strip() or None
WATCH_REGION = os.getenv("WATCH_REGION", "IN").strip().upper()
WITH_WATCH_MONETIZATION_TYPES = os.getenv("WITH_WATCH_MONETIZATION_TYPES", "flatrate").strip()
SYNC_PROVIDERS_FILE = os.getenv("SYNC_PROVIDERS_FILE", "").strip() or None
# Optional: sync only specific regions e.g. US,IN (default all from providers_config.json)
SYNC_REGIONS = [r.strip().upper() for r in os.getenv("SYNC_REGIONS", "").split(",") if r.strip()] or None

DATABASE_URL = (
    f"mysql+pymysql://{DB_USER}:{DB_PASSWORD}@{DB_HOST}:{DB_PORT}/{DB_NAME}"
    "?charset=utf8mb4"
)


def resolve_credential(index: int | None = None) -> tuple[str, str]:
    """Resolve (api_key, token) for a client. index overrides SYNC_KEY_INDEX."""
    if not TMDB_CREDENTIALS:
        return (TMDB_API_KEY, TMDB_API_READ_ACCESS_TOKEN)

    if index is None:
        index = SYNC_KEY_INDEX
    if index is None:
        index = 0

    if index < 0 or index >= len(TMDB_CREDENTIALS):
        raise ValueError(
            f"SYNC_KEY_INDEX/credential index {index} out of range "
            f"(have {len(TMDB_CREDENTIALS)} keys)"
        )
    return TMDB_CREDENTIALS[index]


def effective_workers(item_count: int | None = None) -> int:
    n = min(SYNC_WORKERS, max(1, len(TMDB_CREDENTIALS) or 1))
    if item_count is not None:
        n = min(n, max(1, item_count))
    return max(1, n)
