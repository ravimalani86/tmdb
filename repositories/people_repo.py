from datetime import date, datetime, timezone

from sqlalchemy.orm import Session

from models.people import Person
from utils.hash_utils import compute_hash


def _parse_date(value: str | None) -> date | None:
    if not value:
        return None
    try:
        return date.fromisoformat(value)
    except ValueError:
        return None


def find_person_by_tmdb_id(session: Session, tmdb_id: int) -> Person | None:
    return session.query(Person).filter(Person.tmdb_id == tmdb_id).first()


def upsert_person(session: Session, data: dict) -> str:
    tmdb_id = data["id"]
    now = datetime.now(timezone.utc)
    data_hash = compute_hash(data)
    existing = find_person_by_tmdb_id(session, tmdb_id)

    fields = {
        "name": data.get("name"),
        "biography": data.get("biography"),
        "birthday": _parse_date(data.get("birthday")),
        "deathday": _parse_date(data.get("deathday")),
        "place_of_birth": data.get("place_of_birth"),
        "gender": data.get("gender"),
        "known_for_department": data.get("known_for_department"),
        "popularity": data.get("popularity"),
        "imdb_id": data.get("imdb_id"),
        "homepage": data.get("homepage"),
        "profile_path": data.get("profile_path"),
        "adult": bool(data.get("adult", False)),
        "data_hash": data_hash,
        "last_synced_at": now,
        "is_active": True,
        "deleted_at": None,
    }

    if existing is None:
        session.add(Person(tmdb_id=tmdb_id, **fields))
        return "inserted"

    if existing.data_hash == data_hash:
        return "skipped"

    for key, value in fields.items():
        setattr(existing, key, value)
    existing.updated_at = now
    return "updated"
