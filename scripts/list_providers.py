"""List TMDB watch provider IDs for a country (pick IDs for SYNC_PROVIDER_IDS in .env)."""

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

import config
from services.tmdb_client import TMDBClient
from utils.logger import get_logger

logger = get_logger(__name__)


def main():
    region = config.WATCH_REGION
    client = TMDBClient()
    data = client.get("watch/providers/movie", {"watch_region": region})
    if not data:
        logger.error("No provider data for region %s", region)
        return

    logger.info("Movie providers in region %s:", region)
    for p in data.get("results", []):
        logger.info("  ID %-4s  %s", p.get("provider_id"), p.get("provider_name"))

    logger.info("")
    logger.info("Add to .env example (Netflix only):")
    logger.info("  SYNC_PROVIDER_IDS=8")
    logger.info("Netflix OR Prime:")
    logger.info("  SYNC_PROVIDER_IDS=8|119")


if __name__ == "__main__":
    main()
