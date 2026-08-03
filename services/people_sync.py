import time
from datetime import datetime, timezone

from sqlalchemy.orm import Session

import config
from models.movie import Image
from models.people import Person
from repositories.people_repo import find_person_by_tmdb_id, upsert_person
from services.tmdb_client import TMDBClient
from utils.checkpoint import get_checkpoint, record_metrics, save_checkpoint
from utils.hash_utils import compute_hash
from utils.logger import get_logger
from utils.sync_workers import merge_count_stats, run_partitioned

logger = get_logger(__name__)

PEOPLE_DETAIL_ENTITY = "people_detail"


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


def sync_people_detail(session: Session, client: TMDBClient) -> dict:
    """
    Fill stub people rows from credits with full TMDB person detail.

    GET /person/{tmdb_id} → updates biography, birthday, place_of_birth,
    gender, known_for_department, popularity, imdb_id, homepage, profile_path, etc.

    Resumes via sync_checkpoint entity `people_detail` (last_processed_id).
    Only rows with biography IS NULL (never detail-synced). After a successful
    fetch, empty TMDB bio is stored as '' so the row is not re-queued.
    """
    start = time.time()
    stats = {
        "inserted": 0,
        "updated": 0,
        "skipped": 0,
        "failed": 0,
        "total": 0,
    }
    checkpoint = get_checkpoint(session, PEOPLE_DETAIL_ENTITY)
    last_id = (checkpoint.last_processed_id or 0) if checkpoint else 0

    pending = (
        session.query(Person)
        .filter(Person.id > last_id, Person.biography.is_(None))
        .count()
    )
    workers = config.effective_workers()
    batch_size = max(config.CHUNK_SIZE, config.CHUNK_SIZE * workers)
    logger.info(
        "=== People DETAIL — pending=%s resume after id=%s workers=%s batch=%s ===",
        pending,
        last_id,
        workers,
        batch_size,
    )

    while True:
        rows = (
            session.query(Person.id, Person.tmdb_id)
            .filter(Person.id > last_id, Person.biography.is_(None))
            .order_by(Person.id.asc())
            .limit(batch_size)
            .all()
        )
        if not rows:
            break

        work = [(int(r.id), int(r.tmdb_id)) for r in rows]

        def _worker(subset: list, worker_client: TMDBClient, _wid: int) -> dict:
            from db import get_session

            worker_session = get_session()
            local = {
                "inserted": 0,
                "updated": 0,
                "skipped": 0,
                "failed": 0,
                "total": 0,
            }
            try:
                for _pid, tmdb_id in subset:
                    local["total"] += 1
                    try:
                        detail = worker_client.get(f"person/{tmdb_id}")
                        if not detail:
                            local["failed"] += 1
                            continue
                        action = upsert_person(worker_session, detail)
                        person = find_person_by_tmdb_id(worker_session, tmdb_id)
                        # Mark detail-synced even when TMDB has no biography.
                        if person is not None and person.biography is None:
                            person.biography = ""
                        worker_session.commit()
                        local[action] = local.get(action, 0) + 1
                    except Exception as exc:
                        worker_session.rollback()
                        local["failed"] += 1
                        logger.error(
                            "People detail failed tmdb_id=%s: %s", tmdb_id, exc
                        )
            finally:
                worker_session.close()
            return local

        results = run_partitioned(work, _worker, label="People detail")
        merged = merge_count_stats(
            results, ("inserted", "updated", "skipped", "failed", "total")
        )
        for key, value in merged.items():
            stats[key] += value

        last_id = max(pid for pid, _ in work)
        save_checkpoint(
            session,
            PEOPLE_DETAIL_ENTITY,
            last_page=0,
            last_id=last_id,
            status="running",
        )
        session.commit()
        logger.info(
            "People detail — through id=%s | batch total=%s updated=%s "
            "inserted=%s skipped=%s failed=%s | cumulative total=%s",
            last_id,
            merged["total"],
            merged["updated"],
            merged["inserted"],
            merged["skipped"],
            merged["failed"],
            stats["total"],
        )

    save_checkpoint(
        session,
        PEOPLE_DETAIL_ENTITY,
        last_page=0,
        last_id=last_id,
        status="completed",
    )
    elapsed = time.time() - start
    record_metrics(
        session,
        PEOPLE_DETAIL_ENTITY,
        stats["total"],
        stats["inserted"],
        stats["updated"],
        stats["skipped"],
        stats["failed"],
        elapsed,
    )
    session.commit()
    logger.info(
        "People DETAIL DONE — total=%s updated=%s inserted=%s skipped=%s failed=%s",
        stats["total"],
        stats["updated"],
        stats["inserted"],
        stats["skipped"],
        stats["failed"],
    )
    # client unused for API when workers>1; keep signature consistent with other syncs
    _ = client
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
