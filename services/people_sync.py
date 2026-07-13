import time

from sqlalchemy.orm import Session

from models.movie import Image
from models.people import Person
from repositories.people_repo import upsert_person
from services.tmdb_client import TMDBClient
from utils.checkpoint import get_checkpoint, record_metrics, save_checkpoint
from utils.hash_utils import compute_hash
from utils.logger import get_logger
from datetime import datetime, timezone

logger = get_logger(__name__)


def sync_people(session: Session, client: TMDBClient) -> dict:
    start = time.time()
    stats = {"inserted": 0, "updated": 0, "skipped": 0, "failed": 0, "total": 0}
    checkpoint = get_checkpoint(session, "people")
    start_page = (checkpoint.last_processed_page + 1) if checkpoint else 1

    logger.info("Syncing people from page %s...", start_page)

    for page, data in client.paginate("person/popular", start_page=start_page):
        logger.info("Page %s fetched — %s results", page, len(data.get("results", [])))
        for item in data["results"]:
            tmdb_id = item["id"]
            stats["total"] += 1
            try:
                detail = client.get(f"person/{tmdb_id}")
                if not detail:
                    stats["failed"] += 1
                    continue
                action = upsert_person(session, detail)
                stats[action] += 1
                person = session.query(Person).filter(Person.tmdb_id == tmdb_id).first()
                if person:
                    _sync_person_images(session, client, person, tmdb_id)
                    _sync_person_external_ids(session, client, person, tmdb_id)
            except Exception as exc:
                stats["failed"] += 1
                logger.error("Person %s failed: %s", tmdb_id, exc)

        save_checkpoint(session, "people", page, status="running")
        session.commit()
        logger.info(
            "Chunk page %s — inserted %s, updated %s, skipped %s",
            page, stats["inserted"], stats["updated"], stats["skipped"],
        )

    save_checkpoint(session, "people", 0, status="completed")
    elapsed = time.time() - start
    record_metrics(
        session, "people", stats["total"],
        stats["inserted"], stats["updated"], stats["skipped"],
        stats["failed"], elapsed,
    )
    session.commit()
    return stats


def _sync_person_images(session, client, person, tmdb_id):
    data = client.get(f"person/{tmdb_id}/images")
    if not data:
        return
    now = datetime.now(timezone.utc)
    for img in data.get("profiles", []):
        fp = img.get("file_path", "")
        existing = session.query(Image).filter(
            Image.media_type == "person",
            Image.media_id == person.id,
            Image.file_path == fp,
            Image.image_type == "profile",
        ).first()
        fields = {
            "width": img.get("width"),
            "height": img.get("height"),
            "aspect_ratio": img.get("aspect_ratio"),
            "vote_average": img.get("vote_average"),
            "vote_count": img.get("vote_count"),
            "iso_639_1": img.get("iso_639_1"),
            "data_hash": compute_hash(img),
            "last_synced_at": now,
        }
        if existing is None:
            session.add(Image(
                media_type="person", media_id=person.id,
                file_path=fp, image_type="profile", **fields,
            ))
        elif existing.data_hash != fields["data_hash"]:
            for k, v in fields.items():
                setattr(existing, k, v)


def _sync_person_external_ids(session, client, person, tmdb_id):
    from models.movie import ExternalId
    data = client.get(f"person/{tmdb_id}/external_ids")
    if not data:
        return
    now = datetime.now(timezone.utc)
    existing = session.query(ExternalId).filter(
        ExternalId.media_type == "person", ExternalId.media_id == person.id
    ).first()
    fields = {
        "imdb_id": data.get("imdb_id"),
        "facebook_id": str(data.get("facebook_id") or "") or None,
        "instagram_id": str(data.get("instagram_id") or "") or None,
        "twitter_id": str(data.get("twitter_id") or "") or None,
        "wikidata_id": data.get("wikidata_id"),
        "youtube_id": None,
        "last_synced_at": now,
    }
    if existing is None:
        session.add(ExternalId(media_type="person", media_id=person.id, **fields))
    else:
        for k, v in fields.items():
            setattr(existing, k, v)
