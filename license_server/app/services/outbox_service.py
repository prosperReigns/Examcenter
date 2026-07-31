from __future__ import annotations

import json
from datetime import datetime, timedelta, timezone
from uuid import UUID

from fastapi import HTTPException, status
from sqlalchemy.orm import Session
from app.models.outbox_event import OutboxEvent
from app.repositories.outbox_repository import (
    create_event,
    delete_event,
    get_event,
    get_failed_events,
    get_retryable_events,
    get_unprocessed_events,
    increment_retry,
    mark_processed,
    save_event,
    get_pending_events,
    delete_processed_events,

)
from app.services.audit_service import record_audit_event

def create_outbox_event(
    db: Session,
    *,
    event_type: str,
    aggregate_type: str,
    aggregate_id: UUID,
    payload: dict,
) -> OutboxEvent:

    event = OutboxEvent(
        event_type=event_type,
        aggregate_type=aggregate_type,
        aggregate_id=aggregate_id,
        payload=json.dumps(payload),
        processed=False,
        retry_count=0,
    )

    create_event(db, event)
    db.commit()
    db.refresh(event)

    return event


def get_outbox_event(
    db: Session,
    event_id: UUID,
):

    event = get_event(
        db,
        event_id,
    )

    if event is None:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Outbox event not found",
        )

    return event


def mark_event_processed(
    db: Session,
    event: OutboxEvent,
):
    mark_processed(
        db,
        event,
    )

    db.commit()

    return event


def retry_event(
    db: Session,
    event: OutboxEvent,
    error: str,
):
    increment_retry(
        db,
        event,
        error,
    )

    minutes = 2 ** event.retry_count

    event.next_retry_at = (
        datetime.now(timezone.utc)
        + timedelta(minutes=minutes)
    )

    save_event(db, event)
    db.commit()

    return event


def get_pending_outbox_events(db, limit: int = 100,):
    return get_pending_events(db, limit=limit)


def retry_outbox_event(db,event_id: UUID,):
    event = get_event(
        db,
        event_id,
    )

    if event is None:
        raise HTTPException(
            status_code=404,
            detail="Event not found",
        )

    event.retry_count = 0
    event.error_message = None

    db.add(event)
    db.commit()
    db.refresh(event)

    return event


def delete_processed_event(db: Session, event: OutboxEvent,):
    if not event.processed:
        raise HTTPException(
            status_code=400,
            detail="Event has not been processed.",
        )
    delete_event(
        db,
        event,
    )

    db.commit()


def process_pending_outbox_events(
    db,
    limit: int = 100,
):

    events = get_pending_events(
        db,
        limit,
    )

    processed = 0

    for event in events:

        try:

            # TODO:
            # Publish event to RabbitMQ/Kafka/Redis/etc.

            mark_processed(
                db,
                event,
            )

            processed += 1

        except Exception as ex:

            increment_retry(
                db,
                event,
                str(ex),
            )

    db.commit()

    return processed

def cleanup_processed_events(db,):
    deleted = delete_processed_events(db,)

    db.commit()

    return deleted

def get_outbox_list():
    pass
def get_outbox_message():
    pass