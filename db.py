from contextlib import contextmanager
import time

import pymysql
from sqlalchemy import create_engine, text
from sqlalchemy.orm import Session, declarative_base, sessionmaker
from sqlalchemy.exc import OperationalError

import config

Base = declarative_base()

_engine = None
_SessionLocal = None


def get_server_url() -> str:
    return (
        f"mysql+pymysql://{config.DB_USER}:{config.DB_PASSWORD}"
        f"@{config.DB_HOST}:{config.DB_PORT}/?charset=utf8mb4"
    )


def ensure_database_exists(retries: int = 5) -> None:
    last_error = None
    for attempt in range(1, retries + 1):
        try:
            conn = pymysql.connect(
                host=config.DB_HOST,
                port=config.DB_PORT,
                user=config.DB_USER,
                password=config.DB_PASSWORD or None,
                charset="utf8mb4",
                connect_timeout=10,
            )
            try:
                with conn.cursor() as cursor:
                    cursor.execute(
                        f"CREATE DATABASE IF NOT EXISTS `{config.DB_NAME}` "
                        "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
                    )
                conn.commit()
            finally:
                conn.close()
            return
        except pymysql.err.OperationalError as exc:
            last_error = exc
            time.sleep(min(2 ** attempt, 30))
    raise last_error


def get_engine():
    global _engine
    if _engine is None:
        ensure_database_exists()
        _engine = create_engine(
            config.DATABASE_URL,
            pool_pre_ping=True,
            pool_recycle=1800,
            pool_size=5,
            max_overflow=10,
        )
    return _engine


def reset_engine() -> None:
    global _engine, _SessionLocal
    if _engine is not None:
        _engine.dispose()
    _engine = None
    _SessionLocal = None


def get_session_factory():
    global _SessionLocal
    if _SessionLocal is None:
        _SessionLocal = sessionmaker(
            bind=get_engine(), autocommit=False, autoflush=False
        )
    return _SessionLocal


def get_session() -> Session:
    return get_session_factory()()


@contextmanager
def session_scope():
    session = get_session()
    try:
        yield session
        session.commit()
    except Exception:
        session.rollback()
        raise
    finally:
        session.close()


def test_connection(retries: int = 5) -> bool:
    last_error = None
    for attempt in range(1, retries + 1):
        try:
            with get_engine().connect() as conn:
                conn.execute(text("SELECT 1"))
            return True
        except OperationalError as exc:
            last_error = exc
            reset_engine()
            time.sleep(min(2 ** attempt, 30))
    raise last_error
