from datetime import datetime

from sqlalchemy import DateTime, Float, Integer, String, Text
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
