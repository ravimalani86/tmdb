from datetime import date, datetime, timezone

from sqlalchemy.orm import Session

from models.genres import Genre, MediaGenre
from models.movie import Movie
from repositories.language_repo import sync_spoken_languages
from utils.hash_utils import compute_hash


def _parse_date(value: str | None) -> date | None:
    if not value:
        return None
    try:
        return date.fromisoformat(value)
    except ValueError:
        return None


def find_movie_by_tmdb_id(session: Session, tmdb_id: int) -> Movie | None:
    return session.query(Movie).filter(Movie.tmdb_id == tmdb_id).first()


def upsert_movie(session: Session, data: dict) -> str:
    """Returns action: inserted, updated, or skipped."""
    tmdb_id = data["id"]
    now = datetime.now(timezone.utc)
    data_hash = compute_hash(data)
    existing = find_movie_by_tmdb_id(session, tmdb_id)

    collection = data.get("belongs_to_collection") or {}
    collection_id = collection.get("id") if collection else None

    fields = {
        "title": data.get("title"),
        "original_title": data.get("original_title"),
        "overview": data.get("overview"),
        "tagline": data.get("tagline"),
        "status": data.get("status"),
        "release_date": _parse_date(data.get("release_date")),
        "runtime": data.get("runtime"),
        "budget": data.get("budget"),
        "revenue": data.get("revenue"),
        "homepage": data.get("homepage"),
        "imdb_id": data.get("imdb_id"),
        "popularity": data.get("popularity"),
        "vote_average": data.get("vote_average"),
        "vote_count": data.get("vote_count"),
        "adult": bool(data.get("adult", False)),
        "video": bool(data.get("video", False)),
        "original_language": data.get("original_language"),
        "poster_path": data.get("poster_path"),
        "backdrop_path": data.get("backdrop_path"),
        "collection_id": collection_id,
        "data_hash": data_hash,
        "last_synced_at": now,
        "is_active": True,
        "deleted_at": None,
    }

    if existing is None:
        movie = Movie(tmdb_id=tmdb_id, **fields)
        session.add(movie)
        session.flush()
        media_id = movie.id
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

    _sync_movie_genres(session, media_id, data.get("genre_ids") or data.get("genres", []))
    sync_spoken_languages(session, "movie", media_id, data.get("spoken_languages", []))
    return action


def _sync_movie_genres(session: Session, movie_id: int, genres: list) -> None:
    session.query(MediaGenre).filter(
        MediaGenre.media_type == "movie", MediaGenre.media_id == movie_id
    ).delete(synchronize_session=False)

    for g in genres:
        if isinstance(g, dict):
            genre = session.query(Genre).filter(Genre.tmdb_id == g["id"]).first()
        else:
            genre = session.query(Genre).filter(
                Genre.tmdb_id == g, Genre.media_type == "movie"
            ).first()
        if genre:
            session.add(
                MediaGenre(media_type="movie", media_id=movie_id, genre_id=genre.id)
            )
