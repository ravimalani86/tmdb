from datetime import date, datetime

from sqlalchemy import (
    Boolean,
    Date,
    DateTime,
    Float,
    ForeignKey,
    Integer,
    String,
    Text,
    UniqueConstraint,
)
from sqlalchemy.orm import Mapped, mapped_column

from db import Base


class TvShow(Base):
    __tablename__ = "tv_shows"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    tmdb_id: Mapped[int] = mapped_column(Integer, unique=True, nullable=False, index=True)
    name: Mapped[str | None] = mapped_column(String(512))
    original_name: Mapped[str | None] = mapped_column(String(512))
    overview: Mapped[str | None] = mapped_column(Text)
    status: Mapped[str | None] = mapped_column(String(50))
    first_air_date: Mapped[date | None] = mapped_column(Date)
    last_air_date: Mapped[date | None] = mapped_column(Date)
    number_of_seasons: Mapped[int | None] = mapped_column(Integer)
    number_of_episodes: Mapped[int | None] = mapped_column(Integer)
    homepage: Mapped[str | None] = mapped_column(String(1024))
    in_production: Mapped[bool] = mapped_column(Boolean, default=False)
    popularity: Mapped[float | None] = mapped_column(Float)
    vote_average: Mapped[float | None] = mapped_column(Float)
    vote_count: Mapped[int | None] = mapped_column(Integer)
    show_type: Mapped[str | None] = mapped_column(String(50))
    original_language: Mapped[str | None] = mapped_column(String(10))
    poster_path: Mapped[str | None] = mapped_column(String(512))
    backdrop_path: Mapped[str | None] = mapped_column(String(512))
    data_hash: Mapped[str | None] = mapped_column(String(64))
    last_synced_at: Mapped[datetime | None] = mapped_column(DateTime)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=datetime.utcnow)
    updated_at: Mapped[datetime] = mapped_column(
        DateTime, default=datetime.utcnow, onupdate=datetime.utcnow
    )
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    deleted_at: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)


class TvSeason(Base):
    __tablename__ = "tv_seasons"
    __table_args__ = (UniqueConstraint("tv_show_id", "season_number", name="uq_tv_season"),)

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    tmdb_id: Mapped[int] = mapped_column(Integer, unique=True, nullable=False, index=True)
    tv_show_id: Mapped[int] = mapped_column(ForeignKey("tv_shows.id"), nullable=False)
    season_number: Mapped[int] = mapped_column(Integer, nullable=False)
    name: Mapped[str | None] = mapped_column(String(512))
    overview: Mapped[str | None] = mapped_column(Text)
    air_date: Mapped[date | None] = mapped_column(Date)
    episode_count: Mapped[int | None] = mapped_column(Integer)
    poster_path: Mapped[str | None] = mapped_column(String(512))
    vote_average: Mapped[float | None] = mapped_column(Float)
    data_hash: Mapped[str | None] = mapped_column(String(64))
    last_synced_at: Mapped[datetime | None] = mapped_column(DateTime)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=datetime.utcnow)
    updated_at: Mapped[datetime] = mapped_column(
        DateTime, default=datetime.utcnow, onupdate=datetime.utcnow
    )


class TvEpisode(Base):
    __tablename__ = "tv_episodes"
    __table_args__ = (
        UniqueConstraint("season_id", "episode_number", name="uq_tv_episode"),
    )

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    tmdb_id: Mapped[int] = mapped_column(Integer, unique=True, nullable=False, index=True)
    tv_show_id: Mapped[int] = mapped_column(ForeignKey("tv_shows.id"), nullable=False)
    season_id: Mapped[int] = mapped_column(ForeignKey("tv_seasons.id"), nullable=False)
    episode_number: Mapped[int] = mapped_column(Integer, nullable=False)
    name: Mapped[str | None] = mapped_column(String(512))
    overview: Mapped[str | None] = mapped_column(Text)
    air_date: Mapped[date | None] = mapped_column(Date)
    runtime: Mapped[int | None] = mapped_column(Integer)
    still_path: Mapped[str | None] = mapped_column(String(512))
    vote_average: Mapped[float | None] = mapped_column(Float)
    vote_count: Mapped[int | None] = mapped_column(Integer)
    production_code: Mapped[str | None] = mapped_column(String(64))
    data_hash: Mapped[str | None] = mapped_column(String(64))
    last_synced_at: Mapped[datetime | None] = mapped_column(DateTime)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=datetime.utcnow)
    updated_at: Mapped[datetime] = mapped_column(
        DateTime, default=datetime.utcnow, onupdate=datetime.utcnow
    )
