from sqlalchemy import select
from sqlalchemy.orm import Session

from app.models.webhook_event import WebhookEvent


def get_by_event_id(
    db: Session,
    event_id: str,
):

    statement = (
        select(WebhookEvent)
        .where(
            WebhookEvent.event_id == event_id
        )
    )

    return db.scalar(statement)


def create(
    db: Session,
    event: WebhookEvent,
):

    db.add(event)

    db.flush()

    return event