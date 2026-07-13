from datetime import date, datetime, timezone

from sqlalchemy.orm import Session

from models.genres import Genre, MediaGenre
from models.tv import TvEpisode, TvSeason, TvShow
from repositories.language_repo import sync_spoken_languages
from utils.hash_utils import compute_hash


def _parse_date(value: str | None) -> date | None:
    if not value:
        return None
    try:
        return date.fromisoformat(value)
    except ValueError:
        return None


def find_tv_by_tmdb_id(session: Session, tmdb_id: int) -> TvShow | None:
    return session.query(TvShow).filter(TvShow.tmdb_id == tmdb_id).first()


def upsert_tv_show(session: Session, data: dict) -> str:
    tmdb_id = data["id"]
    now = datetime.now(timezone.utc)
    data_hash = compute_hash(data)
    existing = find_tv_by_tmdb_id(session, tmdb_id)

    fields = {
        "name": data.get("name"),
        "original_name": data.get("original_name"),
        "overview": data.get("overview"),
        "status": data.get("status"),
        "first_air_date": _parse_date(data.get("first_air_date")),
        "last_air_date": _parse_date(data.get("last_air_date")),
        "number_of_seasons": data.get("number_of_seasons"),
        "number_of_episodes": data.get("number_of_episodes"),
        "homepage": data.get("homepage"),
        "in_production": bool(data.get("in_production", False)),
        "popularity": data.get("popularity"),
        "vote_average": data.get("vote_average"),
        "vote_count": data.get("vote_count"),
        "show_type": data.get("type"),
        "original_language": data.get("original_language"),
        "poster_path": data.get("poster_path"),
        "backdrop_path": data.get("backdrop_path"),
        "data_hash": data_hash,
        "last_synced_at": now,
        "is_active": True,
        "deleted_at": None,
    }

    if existing is None:
        show = TvShow(tmdb_id=tmdb_id, **fields)
        session.add(show)
        session.flush()
        media_id = show.id
        action = "inserted"
    else:
        media_id = existing.id
        if existing.data_hash == data_hash:
            action = "skipped"
        else:
            for key, value in fields.items():
                setattr(existing, key, value)
            existing.updated_at = now
            session.flush()
            action = "updated"

    _sync_tv_genres(session, media_id, data.get("genre_ids") or data.get("genres", []))
    sync_spoken_languages(session, "tv", media_id, data.get("spoken_languages", []))
    return action


def _sync_tv_genres(session: Session, tv_id: int, genres: list) -> None:
    session.query(MediaGenre).filter(
        MediaGenre.media_type == "tv", MediaGenre.media_id == tv_id
    ).delete(synchronize_session=False)

    for g in genres:
        if isinstance(g, dict):
            genre = session.query(Genre).filter(Genre.tmdb_id == g["id"]).first()
        else:
            genre = session.query(Genre).filter(
                Genre.tmdb_id == g, Genre.media_type == "tv"
            ).first()
        if genre:
            session.add(MediaGenre(media_type="tv", media_id=tv_id, genre_id=genre.id))


def upsert_tv_season(session: Session, tv_show: TvShow, data: dict) -> str:
    tmdb_id = data["id"]
    now = datetime.now(timezone.utc)
    data_hash = compute_hash(data)
    existing = session.query(TvSeason).filter(TvSeason.tmdb_id == tmdb_id).first()

    fields = {
        "tv_show_id": tv_show.id,
        "season_number": data.get("season_number", 0),
        "name": data.get("name"),
        "overview": data.get("overview"),
        "air_date": _parse_date(data.get("air_date")),
        "episode_count": data.get("episode_count"),
        "poster_path": data.get("poster_path"),
        "vote_average": data.get("vote_average"),
        "data_hash": data_hash,
        "last_synced_at": now,
    }

    if existing is None:
        session.add(TvSeason(tmdb_id=tmdb_id, **fields))
        return "inserted"
    if existing.data_hash == data_hash:
        return "skipped"
    for key, value in fields.items():
        setattr(existing, key, value)
    existing.updated_at = now
    return "updated"


def upsert_tv_episode(
    session: Session, tv_show: TvShow, season: TvSeason, data: dict
) -> str:
    tmdb_id = data["id"]
    now = datetime.now(timezone.utc)
    data_hash = compute_hash(data)
    existing = session.query(TvEpisode).filter(TvEpisode.tmdb_id == tmdb_id).first()

    fields = {
        "tv_show_id": tv_show.id,
        "season_id": season.id,
        "episode_number": data.get("episode_number", 0),
        "name": data.get("name"),
        "overview": data.get("overview"),
        "air_date": _parse_date(data.get("air_date")),
        "runtime": data.get("runtime"),
        "still_path": data.get("still_path"),
        "vote_average": data.get("vote_average"),
        "vote_count": data.get("vote_count"),
        "production_code": data.get("production_code"),
        "data_hash": data_hash,
        "last_synced_at": now,
    }

    if existing is None:
        session.add(TvEpisode(tmdb_id=tmdb_id, **fields))
        return "inserted"
    if existing.data_hash == data_hash:
        return "skipped"
    for key, value in fields.items():
        setattr(existing, key, value)
    existing.updated_at = now
    return "updated"
