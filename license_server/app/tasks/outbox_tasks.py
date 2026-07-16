from __future__ import annotations

from app.celery_app import celery_app
from app.database.session import SessionLocal
from app.services.outbox_service import cleanup_processed_events, process_pending_outbox_events


@celery_app.task(
    name="outbox.process_pending",
    autoretry_for=(Exception,),
    retry_backoff=True,
    retry_kwargs={"max_retries": 5},
)
def process_pending_outbox_events_task(limit: int = 100) -> dict[str, int]:
    """Process pending outbox events with bounded batch size."""

    db = SessionLocal()
    try:
        processed = process_pending_outbox_events(db, limit=limit)
        return {"processed": processed}
    finally:
        db.close()


@celery_app.task(name="outbox.cleanup_processed")
def cleanup_processed_outbox_events_task() -> dict[str, int]:
    """Remove outbox events that have already been processed."""

    db = SessionLocal()
    try:
        deleted = cleanup_processed_events(db)
        return {"deleted": deleted}
    finally:
        db.close()
