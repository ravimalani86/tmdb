import os
from dotenv import load_dotenv

load_dotenv()

DB_HOST = os.getenv("DB_HOST", "localhost")
DB_PORT = int(os.getenv("DB_PORT", "3306"))
DB_USER = os.getenv("DB_USER", "root")
DB_PASSWORD = os.getenv("DB_PASSWORD", "")
DB_NAME = os.getenv("DB_NAME", "tmdbdata")

TMDB_API_KEY = os.getenv("TMDB_API_KEY", "")
TMDB_API_READ_ACCESS_TOKEN = os.getenv("TMDB_API_READ_ACCESS_TOKEN", "")
TMDB_BASE_URL = os.getenv("TMDB_BASE_URL", "https://api.themoviedb.org/3")

CHUNK_SIZE = int(os.getenv("CHUNK_SIZE", "100"))
RATE_LIMIT_SLEEP = float(os.getenv("RATE_LIMIT_SLEEP", "0.25"))
MAX_RETRIES = int(os.getenv("MAX_RETRIES", "5"))
SYNC_MAX_PAGES = int(os.getenv("SYNC_MAX_PAGES", "0")) or None
SYNC_MOVIE_LITE = os.getenv("SYNC_MOVIE_LITE", "false").lower() in ("1", "true", "yes")

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
