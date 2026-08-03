import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from db import Base, ensure_database_exists, get_engine, test_connection
from models.genres import Genre, MediaGenre, ProductionCountry, MediaProductionCountry, SpokenLanguage, MediaSpokenLanguage
from models.movie import (
    Movie, Collection, Keyword, MediaKeyword, Credit, Video, Image,
    WatchProvider, MediaWatchProvider, ExternalId, Translation,
    Recommendation, SimilarMedia, Certification, UserMediaState,
)
from models.tv import TvShow, TvSeason, TvEpisode
from models.people import Person
from models.sync_state import (
    SyncCheckpoint,
    SyncMetrics,
    RateLimitTracker,
    RetryQueue,
    TvSeasonSyncJob,
    MediaSyncQueue,
)
from utils.logger import get_logger

logger = get_logger(__name__)


def create_all_tables():
    logger.info("Connecting database...")
    ensure_database_exists()
    test_connection()
    logger.info("Database connected.")

    logger.info("Creating tables...")
    engine = get_engine()
    Base.metadata.create_all(bind=engine)
    logger.info("Tables created.")


if __name__ == "__main__":
    create_all_tables()
