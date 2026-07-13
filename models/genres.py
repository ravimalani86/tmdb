from datetime import datetime

from sqlalchemy import (
    BigInteger,
    Boolean,
    DateTime,
    Float,
    ForeignKey,
    Index,
    Integer,
    String,
    Text,
    UniqueConstraint,
)
from sqlalchemy.orm import Mapped, mapped_column, relationship

from db import Base


class Genre(Base):
    __tablename__ = "genres"
    __table_args__ = (
        UniqueConstraint("tmdb_id", "media_type", name="uq_genre_tmdb_media"),
    )

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    tmdb_id: Mapped[int] = mapped_column(Integer, nullable=False, index=True)
    name: Mapped[str] = mapped_column(String(255), nullable=False)
    media_type: Mapped[str] = mapped_column(String(10), nullable=False)
    data_hash: Mapped[str | None] = mapped_column(String(64))
    last_synced_at: Mapped[datetime | None] = mapped_column(DateTime)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=datetime.utcnow)
    updated_at: Mapped[datetime] = mapped_column(
        DateTime, default=datetime.utcnow, onupdate=datetime.utcnow
    )
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    deleted_at: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)


class MediaGenre(Base):
    __tablename__ = "media_genres"
    __table_args__ = (
        UniqueConstraint("media_type", "media_id", "genre_id", name="uq_media_genre"),
    )

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    media_type: Mapped[str] = mapped_column(String(10), nullable=False)
    media_id: Mapped[int] = mapped_column(Integer, nullable=False, index=True)
    genre_id: Mapped[int] = mapped_column(ForeignKey("genres.id"), nullable=False)


class ProductionCountry(Base):
    __tablename__ = "production_countries"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    iso_code: Mapped[str] = mapped_column(String(10), unique=True, nullable=False)
    country_name: Mapped[str] = mapped_column(String(255), nullable=False)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=datetime.utcnow)
    updated_at: Mapped[datetime] = mapped_column(
        DateTime, default=datetime.utcnow, onupdate=datetime.utcnow
    )


class MediaProductionCountry(Base):
    __tablename__ = "media_production_countries"
    __table_args__ = (
        UniqueConstraint(
            "media_type", "media_id", "country_id", name="uq_media_country"
        ),
    )

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    media_type: Mapped[str] = mapped_column(String(10), nullable=False)
    media_id: Mapped[int] = mapped_column(Integer, nullable=False, index=True)
    country_id: Mapped[int] = mapped_column(
        ForeignKey("production_countries.id"), nullable=False
    )


class SpokenLanguage(Base):
    __tablename__ = "spoken_languages"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    iso_code: Mapped[str] = mapped_column(String(10), unique=True, nullable=False)
    language_name: Mapped[str] = mapped_column(String(255), nullable=False)
    english_name: Mapped[str | None] = mapped_column(String(255))
    created_at: Mapped[datetime] = mapped_column(DateTime, default=datetime.utcnow)
    updated_at: Mapped[datetime] = mapped_column(
        DateTime, default=datetime.utcnow, onupdate=datetime.utcnow
    )


class MediaSpokenLanguage(Base):
    __tablename__ = "media_spoken_languages"
    __table_args__ = (
        UniqueConstraint(
            "media_type", "media_id", "language_id", name="uq_media_language"
        ),
    )

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    media_type: Mapped[str] = mapped_column(String(10), nullable=False)
    media_id: Mapped[int] = mapped_column(Integer, nullable=False, index=True)
    language_id: Mapped[int] = mapped_column(
        ForeignKey("spoken_languages.id"), nullable=False
    )
