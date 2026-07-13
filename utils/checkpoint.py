from datetime import datetime, timezone

from sqlalchemy.orm import Session

from models.sync_state import SyncCheckpoint, SyncMetrics
from utils.logger import get_logger

logger = get_logger(__name__)


def get_checkpoint(session: Session, entity_type: str) -> SyncCheckpoint | None:
    return (
        session.query(SyncCheckpoint)
        .filter(SyncCheckpoint.entity_type == entity_type)
        .first()
    )


def save_checkpoint(
    session: Session,
    entity_type: str,
    last_page: int,
    last_id: int | None = None,
    last_season: int | None = None,
    status: str = "running",
) -> SyncCheckpoint:
    checkpoint = get_checkpoint(session, entity_type)
    now = datetime.now(timezone.utc)
    if checkpoint is None:
        checkpoint = SyncCheckpoint(
            entity_type=entity_type,
            last_processed_page=last_page,
            last_processed_id=last_id,
            last_processed_season=last_season,
            status=status,
            updated_at=now,
        )
        session.add(checkpoint)
    else:
        checkpoint.last_processed_page = last_page
        checkpoint.last_processed_id = last_id
        checkpoint.last_processed_season = last_season
        checkpoint.status = status
        checkpoint.updated_at = now
    session.flush()
    logger.info(
        "Checkpoint saved -> entity_type=%s last_page=%s last_id=%s last_season=%s status=%s",
        entity_type,
        last_page,
        last_id,
        last_season,
        status,
    )
    return checkpoint


def record_metrics(
    session: Session,
    entity_type: str,
    total_processed: int,
    inserted: int,
    updated: int,
    skipped: int,
    failed: int,
    execution_time: float,
) -> None:
    metrics = SyncMetrics(
        entity_type=entity_type,
        total_processed=total_processed,
        inserted_count=inserted,
        updated_count=updated,
        skipped_count=skipped,
        failed_count=failed,
        execution_time=execution_time,
        sync_date=datetime.now(timezone.utc),
    )
    session.add(metrics)
