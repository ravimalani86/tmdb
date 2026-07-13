from datetime import date, datetime

from sqlalchemy import Boolean, Date, DateTime, Float, Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column

from db import Base


class Person(Base):
    __tablename__ = "people"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    tmdb_id: Mapped[int] = mapped_column(Integer, unique=True, nullable=False, index=True)
    name: Mapped[str | None] = mapped_column(String(512))
    biography: Mapped[str | None] = mapped_column(Text)
    birthday: Mapped[date | None] = mapped_column(Date)
    deathday: Mapped[date | None] = mapped_column(Date)
    place_of_birth: Mapped[str | None] = mapped_column(String(512))
    gender: Mapped[int | None] = mapped_column(Integer)
    known_for_department: Mapped[str | None] = mapped_column(String(128))
    popularity: Mapped[float | None] = mapped_column(Float)
    imdb_id: Mapped[str | None] = mapped_column(String(20))
    homepage: Mapped[str | None] = mapped_column(String(1024))
    profile_path: Mapped[str | None] = mapped_column(String(512))
    adult: Mapped[bool] = mapped_column(Boolean, default=False)
    data_hash: Mapped[str | None] = mapped_column(String(64))
    last_synced_at: Mapped[datetime | None] = mapped_column(DateTime)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=datetime.utcnow)
    updated_at: Mapped[datetime] = mapped_column(
        DateTime, default=datetime.utcnow, onupdate=datetime.utcnow
    )
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    deleted_at: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)
