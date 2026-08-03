from datetime import datetime

from sqlalchemy import DateTime, Float, ForeignKey, Integer, String, Text, UniqueConstraint
from sqlalchemy.orm import Mapped, mapped_column

from db import Base


class SyncCheckpoint(Base):
    __tablename__ = "sync_checkpoint"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    entity_type: Mapped[str] = mapped_column(String(64), unique=True, nullable=False)
    last_processed_page: Mapped[int] = mapped_column(Integer, default=0)
    last_processed_id: Mapped[int | None] = mapped_column(Integer, nullable=True)
    last_processed_season: Mapped[int | None] = mapped_column(Integer, nullable=True)
    status: Mapped[str] = mapped_column(String(20), default="running")
    updated_at: Mapped[datetime] = mapped_column(DateTime, default=datetime.utcnow)


class TvSeasonSyncJob(Base):
    """Per-show TV seasons sync progress (one row per tv_shows.id)."""

    __tablename__ = "tv_season_sync_jobs"
    __table_args__ = (UniqueConstraint("tv_show_id", name="uq_tv_season_sync_job_show"),)

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    tv_show_id: Mapped[int] = mapped_column(
        ForeignKey("tv_shows.id"), nullable=False, index=True
    )
    tmdb_id: Mapped[int] = mapped_column(Integer, nullable=False, index=True)
    status: Mapped[str] = mapped_column(String(20), default="pending", index=True)
    # Seasons with number <= this value are done. NULL = not started.
    last_processed_season: Mapped[int | None] = mapped_column(Integer, nullable=True)
    error_message: Mapped[str | None] = mapped_column(Text)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=datetime.utcnow)
    updated_at: Mapped[datetime] = mapped_column(
        DateTime, default=datetime.utcnow, onupdate=datetime.utcnow
    )


class SyncMetrics(Base):
    __tablename__ = "sync_metrics"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    entity_type: Mapped[str] = mapped_column(String(64), nullable=False)
    total_processed: Mapped[int] = mapped_column(Integer, default=0)
    inserted_count: Mapped[int] = mapped_column(Integer, default=0)
    updated_count: Mapped[int] = mapped_column(Integer, default=0)
    skipped_count: Mapped[int] = mapped_column(Integer, default=0)
    failed_count: Mapped[int] = mapped_column(Integer, default=0)
    execution_time: Mapped[float] = mapped_column(Float, default=0.0)
    sync_date: Mapped[datetime] = mapped_column(DateTime, default=datetime.utcnow)


class RateLimitTracker(Base):
    __tablename__ = "rate_limit_tracker"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    requests_count: Mapped[int] = mapped_column(Integer, default=0)
    window_start: Mapped[datetime | None] = mapped_column(DateTime)
    window_end: Mapped[datetime | None] = mapped_column(DateTime)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=datetime.utcnow)


class RetryQueue(Base):
    __tablename__ = "retry_queue"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    entity_type: Mapped[str] = mapped_column(String(64), nullable=False)
    tmdb_id: Mapped[int] = mapped_column(Integer, nullable=False)
    payload: Mapped[str | None] = mapped_column(Text)
    retry_count: Mapped[int] = mapped_column(Integer, default=0)
    next_retry_at: Mapped[datetime | None] = mapped_column(DateTime)
    error_message: Mapped[str | None] = mapped_column(Text)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=datetime.utcnow)


class MediaSyncQueue(Base):
    """Daily cron queue: movie / tv / person IDs to fully refresh."""

    __tablename__ = "media_sync_queue"
    __table_args__ = (
        UniqueConstraint(
            "media_type", "tmdb_id", "sync_day", name="uq_media_sync_queue_day_item"
        ),
    )

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    media_type: Mapped[str] = mapped_column(String(16), nullable=False, index=True)
    tmdb_id: Mapped[int] = mapped_column(Integer, nullable=False, index=True)
    sync_day: Mapped[str] = mapped_column(String(10), nullable=False, index=True)
    source: Mapped[str] = mapped_column(String(32), default="changes")
    status: Mapped[str] = mapped_column(String(20), default="pending", index=True)
    attempts: Mapped[int] = mapped_column(Integer, default=0)
    last_error: Mapped[str | None] = mapped_column(Text)
    worker_id: Mapped[int | None] = mapped_column(Integer, nullable=True)
    enqueued_at: Mapped[datetime] = mapped_column(DateTime, default=datetime.utcnow)
    started_at: Mapped[datetime | None] = mapped_column(DateTime)
    finished_at: Mapped[datetime | None] = mapped_column(DateTime)

