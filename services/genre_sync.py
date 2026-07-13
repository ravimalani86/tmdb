import time
from datetime import datetime, timezone

from sqlalchemy.orm import Session

from models.genres import Genre
from services.tmdb_client import TMDBClient
from utils.checkpoint import record_metrics, save_checkpoint
from utils.hash_utils import compute_hash
from utils.logger import get_logger

logger = get_logger(__name__)


def sync_genres(session: Session, client: TMDBClient) -> dict:
    start = time.time()
    stats = {"inserted": 0, "updated": 0, "skipped": 0, "failed": 0, "total": 0}
    existing_count = session.query(Genre).count()

    for media_type, endpoint in [("movie", "genre/movie/list"), ("tv", "genre/tv/list")]:
        logger.info("Syncing %s genres...", media_type)
        try:
            data = client.get(endpoint)
        except RuntimeError as exc:
            if existing_count > 0:
                logger.warning("Genre API unavailable, using %s cached genres: %s", existing_count, exc)
                save_checkpoint(session, "genres", 1, status="completed")
                session.commit()
                return stats
            raise
        if not data:
            continue
        for item in data.get("genres", []):
            stats["total"] += 1
            try:
                action = _upsert_genre(session, item, media_type)
                stats[action] += 1
            except Exception as exc:
                stats["failed"] += 1
                logger.error("Genre %s failed: %s", item.get("id"), exc)
        session.commit()
        logger.info(
            "%s genres — inserted %s, updated %s, skipped %s",
            media_type,
            stats["inserted"],
            stats["updated"],
            stats["skipped"],
        )

    elapsed = time.time() - start
    save_checkpoint(session, "genres", 1, status="completed")
    record_metrics(
        session, "genres", stats["total"],
        stats["inserted"], stats["updated"], stats["skipped"],
        stats["failed"], elapsed,
    )
    session.commit()
    return stats


def _upsert_genre(session: Session, data: dict, media_type: str) -> str:
    tmdb_id = data["id"]
    now = datetime.now(timezone.utc)
    data_hash = compute_hash(data)
    existing = (
        session.query(Genre)
        .filter(Genre.tmdb_id == tmdb_id, Genre.media_type == media_type)
        .first()
    )
    if existing is None:
        session.add(
            Genre(
                tmdb_id=tmdb_id,
                name=data["name"],
                media_type=media_type,
                data_hash=data_hash,
                last_synced_at=now,
            )
        )
        return "inserted"
    if existing.data_hash == data_hash:
        return "skipped"
    existing.name = data["name"]
    existing.data_hash = data_hash
    existing.last_synced_at = now
    existing.updated_at = now
    return "updated"
