from datetime import date, datetime

from sqlalchemy import (
    BigInteger,
    Boolean,
    Date,
    DateTime,
    Float,
    ForeignKey,
    Index,
    Integer,
    String,
    Text,
    UniqueConstraint,
)
from sqlalchemy.orm import Mapped, mapped_column

from db import Base


class Movie(Base):
    __tablename__ = "movies"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    tmdb_id: Mapped[int] = mapped_column(Integer, unique=True, nullable=False, index=True)
    title: Mapped[str | None] = mapped_column(String(512))
    original_title: Mapped[str | None] = mapped_column(String(512))
    overview: Mapped[str | None] = mapped_column(Text)
    tagline: Mapped[str | None] = mapped_column(String(1024))
    status: Mapped[str | None] = mapped_column(String(50))
    release_date: Mapped[date | None] = mapped_column(Date)
    runtime: Mapped[int | None] = mapped_column(Integer)
    budget: Mapped[int | None] = mapped_column(BigInteger)
    revenue: Mapped[int | None] = mapped_column(BigInteger)
    homepage: Mapped[str | None] = mapped_column(String(1024))
    imdb_id: Mapped[str | None] = mapped_column(String(20))
    popularity: Mapped[float | None] = mapped_column(Float)
    vote_average: Mapped[float | None] = mapped_column(Float)
    vote_count: Mapped[int | None] = mapped_column(Integer)
    adult: Mapped[bool] = mapped_column(Boolean, default=False)
    video: Mapped[bool] = mapped_column(Boolean, default=False)
    original_language: Mapped[str | None] = mapped_column(String(10))
    poster_path: Mapped[str | None] = mapped_column(String(512))
    backdrop_path: Mapped[str | None] = mapped_column(String(512))
    collection_id: Mapped[int | None] = mapped_column(Integer, nullable=True)
    data_hash: Mapped[str | None] = mapped_column(String(64))
    last_synced_at: Mapped[datetime | None] = mapped_column(DateTime)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=datetime.utcnow)
    updated_at: Mapped[datetime] = mapped_column(
        DateTime, default=datetime.utcnow, onupdate=datetime.utcnow
    )
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    deleted_at: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)


class Collection(Base):
    __tablename__ = "collections"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    tmdb_id: Mapped[int] = mapped_column(Integer, unique=True, nullable=False, index=True)
    name: Mapped[str | None] = mapped_column(String(512))
    overview: Mapped[str | None] = mapped_column(Text)
    poster_path: Mapped[str | None] = mapped_column(String(512))
    backdrop_path: Mapped[str | None] = mapped_column(String(512))
    data_hash: Mapped[str | None] = mapped_column(String(64))
    last_synced_at: Mapped[datetime | None] = mapped_column(DateTime)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=datetime.utcnow)
    updated_at: Mapped[datetime] = mapped_column(
        DateTime, default=datetime.utcnow, onupdate=datetime.utcnow
    )


class Keyword(Base):
    __tablename__ = "keywords"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    tmdb_id: Mapped[int] = mapped_column(Integer, unique=True, nullable=False, index=True)
    name: Mapped[str] = mapped_column(String(255), nullable=False)
    data_hash: Mapped[str | None] = mapped_column(String(64))
    last_synced_at: Mapped[datetime | None] = mapped_column(DateTime)


class MediaKeyword(Base):
    __tablename__ = "media_keywords"
    __table_args__ = (
        UniqueConstraint("media_type", "media_id", "keyword_id", name="uq_media_keyword"),
    )

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    media_type: Mapped[str] = mapped_column(String(10), nullable=False)
    media_id: Mapped[int] = mapped_column(Integer, nullable=False, index=True)
    keyword_id: Mapped[int] = mapped_column(ForeignKey("keywords.id"), nullable=False)


class Credit(Base):
    __tablename__ = "credits"
    __table_args__ = (
        # Prefix lengths avoid MySQL "key too long" (utf8mb4 × VARCHAR(512))
        Index(
            "uq_credit",
            "media_type",
            "media_id",
            "person_id",
            "credit_type",
            "job",
            "character",
            unique=True,
            mysql_length={"job": 255, "character": 255},
        ),
    )

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    media_type: Mapped[str] = mapped_column(String(10), nullable=False)
    media_id: Mapped[int] = mapped_column(Integer, nullable=False, index=True)
    person_id: Mapped[int] = mapped_column(ForeignKey("people.id"), nullable=False)
    credit_type: Mapped[str] = mapped_column(String(10), nullable=False)
    character: Mapped[str | None] = mapped_column(String(512))
    job: Mapped[str | None] = mapped_column(String(255))
    department: Mapped[str | None] = mapped_column(String(255))
    order_index: Mapped[int | None] = mapped_column(Integer)
    data_hash: Mapped[str | None] = mapped_column(String(64))
    last_synced_at: Mapped[datetime | None] = mapped_column(DateTime)


class Video(Base):
    __tablename__ = "videos"
    __table_args__ = (
        UniqueConstraint("media_type", "media_id", "tmdb_video_id", name="uq_video"),
    )

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    media_type: Mapped[str] = mapped_column(String(10), nullable=False)
    media_id: Mapped[int] = mapped_column(Integer, nullable=False, index=True)
    tmdb_video_id: Mapped[str] = mapped_column(String(64), nullable=False)
    name: Mapped[str | None] = mapped_column(String(512))
    key: Mapped[str | None] = mapped_column(String(128))
    site: Mapped[str | None] = mapped_column(String(64))
    size: Mapped[int | None] = mapped_column(Integer)
    video_type: Mapped[str | None] = mapped_column(String(64))
    official: Mapped[bool] = mapped_column(Boolean, default=False)
    published_at: Mapped[datetime | None] = mapped_column(DateTime)
    data_hash: Mapped[str | None] = mapped_column(String(64))
    last_synced_at: Mapped[datetime | None] = mapped_column(DateTime)


class Image(Base):
    __tablename__ = "images"
    __table_args__ = (
        UniqueConstraint(
            "media_type", "media_id", "file_path", "image_type", name="uq_image"
        ),
    )

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    media_type: Mapped[str] = mapped_column(String(10), nullable=False)
    media_id: Mapped[int] = mapped_column(Integer, nullable=False, index=True)
    file_path: Mapped[str] = mapped_column(String(512), nullable=False)
    width: Mapped[int | None] = mapped_column(Integer)
    height: Mapped[int | None] = mapped_column(Integer)
    aspect_ratio: Mapped[float | None] = mapped_column(Float)
    vote_average: Mapped[float | None] = mapped_column(Float)
    vote_count: Mapped[int | None] = mapped_column(Integer)
    image_type: Mapped[str] = mapped_column(String(32), nullable=False)
    iso_639_1: Mapped[str | None] = mapped_column(String(10))
    data_hash: Mapped[str | None] = mapped_column(String(64))
    last_synced_at: Mapped[datetime | None] = mapped_column(DateTime)


class WatchProvider(Base):
    __tablename__ = "watch_providers"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    tmdb_id: Mapped[int] = mapped_column(Integer, unique=True, nullable=False, index=True)
    provider_name: Mapped[str] = mapped_column(String(255), nullable=False)
    logo_path: Mapped[str | None] = mapped_column(String(512))
    display_priority: Mapped[int | None] = mapped_column(Integer)
    provider_type: Mapped[str | None] = mapped_column(String(32))
    country_code: Mapped[str | None] = mapped_column(String(10))
    data_hash: Mapped[str | None] = mapped_column(String(64))
    last_synced_at: Mapped[datetime | None] = mapped_column(DateTime)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=datetime.utcnow)
    updated_at: Mapped[datetime] = mapped_column(
        DateTime, default=datetime.utcnow, onupdate=datetime.utcnow
    )


class MediaWatchProvider(Base):
    __tablename__ = "media_watch_providers"
    __table_args__ = (
        UniqueConstraint(
            "media_type", "media_id", "provider_id", "country_code", "provider_type",
            name="uq_media_provider",
        ),
    )

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    media_type: Mapped[str] = mapped_column(String(10), nullable=False)
    media_id: Mapped[int] = mapped_column(Integer, nullable=False, index=True)
    provider_id: Mapped[int] = mapped_column(ForeignKey("watch_providers.id"), nullable=False)
    country_code: Mapped[str] = mapped_column(String(10), nullable=False)
    provider_type: Mapped[str] = mapped_column(String(32), nullable=False)


class ExternalId(Base):
    __tablename__ = "external_ids"
    __table_args__ = (
        UniqueConstraint("media_type", "media_id", name="uq_external_id"),
    )

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    media_type: Mapped[str] = mapped_column(String(10), nullable=False)
    media_id: Mapped[int] = mapped_column(Integer, nullable=False, index=True)
    imdb_id: Mapped[str | None] = mapped_column(String(20))
    facebook_id: Mapped[str | None] = mapped_column(String(128))
    instagram_id: Mapped[str | None] = mapped_column(String(128))
    twitter_id: Mapped[str | None] = mapped_column(String(128))
    wikidata_id: Mapped[str | None] = mapped_column(String(64))
    youtube_id: Mapped[str | None] = mapped_column(String(128))
    last_synced_at: Mapped[datetime | None] = mapped_column(DateTime)


class Translation(Base):
    __tablename__ = "translations"
    __table_args__ = (
        UniqueConstraint(
            "media_type", "media_id", "language_code", "region_code",
            name="uq_translation",
        ),
    )

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    media_type: Mapped[str] = mapped_column(String(10), nullable=False)
    media_id: Mapped[int] = mapped_column(Integer, nullable=False, index=True)
    language_code: Mapped[str] = mapped_column(String(10), nullable=False)
    region_code: Mapped[str] = mapped_column(String(10), nullable=False, default="")
    title: Mapped[str | None] = mapped_column(String(512))
    overview: Mapped[str | None] = mapped_column(Text)
    homepage: Mapped[str | None] = mapped_column(String(1024))
    data_hash: Mapped[str | None] = mapped_column(String(64))
    last_synced_at: Mapped[datetime | None] = mapped_column(DateTime)


class Recommendation(Base):
    __tablename__ = "recommendations"
    __table_args__ = (
        UniqueConstraint(
            "media_type", "media_id", "recommended_media_id", name="uq_recommendation"
        ),
    )

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    media_type: Mapped[str] = mapped_column(String(10), nullable=False)
    media_id: Mapped[int] = mapped_column(Integer, nullable=False, index=True)
    recommended_media_id: Mapped[int] = mapped_column(Integer, nullable=False)
    recommendation_score: Mapped[float | None] = mapped_column(Float)
    last_synced_at: Mapped[datetime | None] = mapped_column(DateTime)


class SimilarMedia(Base):
    __tablename__ = "similar_media"
    __table_args__ = (
        UniqueConstraint(
            "media_type", "media_id", "similar_media_id", name="uq_similar"
        ),
    )

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    media_type: Mapped[str] = mapped_column(String(10), nullable=False)
    media_id: Mapped[int] = mapped_column(Integer, nullable=False, index=True)
    similar_media_id: Mapped[int] = mapped_column(Integer, nullable=False)
    similarity_score: Mapped[float | None] = mapped_column(Float)
    last_synced_at: Mapped[datetime | None] = mapped_column(DateTime)


class Certification(Base):
    __tablename__ = "certifications"
    __table_args__ = (
        UniqueConstraint(
            "media_type", "media_id", "country_code", "release_type",
            name="uq_cert",
        ),
    )

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    media_type: Mapped[str] = mapped_column(String(10), nullable=False)
    media_id: Mapped[int] = mapped_column(Integer, nullable=False, index=True)
    country_code: Mapped[str] = mapped_column(String(10), nullable=False)
    release_type: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    rating: Mapped[str | None] = mapped_column(String(32))
    meaning: Mapped[str | None] = mapped_column(String(512))
    last_synced_at: Mapped[datetime | None] = mapped_column(DateTime)
