from datetime import datetime, timezone
from uuid import UUID

from sqlalchemy import delete, select
from sqlalchemy.orm import Session
from sqlalchemy import and_
from app.models.outbox_event import OutboxEvent


def create_event(
    db: Session,
    event: OutboxEvent,
) -> OutboxEvent:
    db.add(event)
    db.flush()
    return event


def get_event(
    db: Session,
    event_id: UUID,
) -> OutboxEvent | None:
    return db.get(OutboxEvent, event_id)


def get_pending_events(
    db: Session,
    limit: int = 100,
) -> list[OutboxEvent]:
    statement = (
        select(OutboxEvent)
        .where(OutboxEvent.processed.is_(False))
        .order_by(OutboxEvent.created_at.asc())
        .limit(limit)
    )

    return db.scalars(statement).all()


def mark_processed(
    db: Session,
    event: OutboxEvent,
) -> OutboxEvent:

    event.processed = True
    event.processed_at = datetime.now(timezone.utc)
    event.error_message = None

    db.add(event)
    db.flush()

    return event


def increment_retry(
    db: Session,
    event: OutboxEvent,
    error_message: str,
) -> OutboxEvent:

    event.retry_count += 1
    event.error_message = error_message

    db.add(event)
    db.flush()

    return event


def get_failed_events(
    db: Session,
    minimum_retry: int = 3,
) -> list[OutboxEvent]:

    statement = (
        select(OutboxEvent)
        .where(
            OutboxEvent.processed.is_(False),
            OutboxEvent.retry_count >= minimum_retry,
        )
        .order_by(OutboxEvent.created_at.asc())
    )

    return db.scalars(statement).all()


def delete_processed_events(
    db: Session,
) -> int:

    result = db.execute(
        delete(OutboxEvent)
        .where(OutboxEvent.processed.is_(True))
    )

    db.flush()

    return result.rowcount or 0

def save_event(
    db: Session,
    event: OutboxEvent,
) -> OutboxEvent:

    db.add(event)
    db.flush()

    return event

def get_unprocessed_events(
    db: Session,
    limit: int = 100,
) -> list[OutboxEvent]:

    statement = (
        select(OutboxEvent)
        .where(
            OutboxEvent.processed.is_(False)
        )
        .order_by(
            OutboxEvent.created_at.asc()
        )
        .limit(limit)
    )

    return db.scalars(statement).all()

def get_retryable_events(
    db: Session,
    limit: int = 100,
) -> list[OutboxEvent]:

    now = datetime.now(timezone.utc)

    statement = (
        select(OutboxEvent)
        .where(
            and_(
                OutboxEvent.processed.is_(False),
                OutboxEvent.retry_count < OutboxEvent.max_retry_count,
                (
                    OutboxEvent.next_retry_at.is_(None)
                    | (OutboxEvent.next_retry_at <= now)
                ),
            )
        )
        .order_by(
            OutboxEvent.created_at.asc()
        )
        .limit(limit)
    )

    return db.scalars(statement).all()

def get_events_by_type(
    db: Session,
    event_type: str,
) -> list[OutboxEvent]:

    statement = (
        select(OutboxEvent)
        .where(
            OutboxEvent.event_type == event_type
        )
    )

    return db.scalars(statement).all()

def delete_event(
    db: Session,
    event: OutboxEvent,
):

    db.delete(event)
    db.flush()