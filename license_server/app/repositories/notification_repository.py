from sqlalchemy import desc, func, select, or_
from sqlalchemy.orm import Session
from uuid import UUID
from app.models.notification import Notification


def create_notification(
    db: Session,
    notification: Notification,
):
    db.add(notification)
    db.flush()
    return notification


def persist_notification(
    db: Session,
    notification: Notification,
):
    db.add(notification)
    db.flush()
    return notification

def get_notification(
    db: Session,
    notification_id: UUID,
) -> Notification | None:

    return db.get(
        Notification,
        notification_id,
    )


def list_notifications(
    db: Session,
    *,
    customer_id=None,
    school_id=None,
    channel: str | None = None,
    status: str | None = None,
    search: str | None = None,
    offset: int = 0,
    limit: int = 20,
):

    statement = select(Notification)

    if customer_id:
        statement = statement.where(
            Notification.customer_id == customer_id,
        )

    if school_id:
        statement = statement.where(
            Notification.school_id == school_id,
        )

    if channel:
        statement = statement.where(
            Notification.channel == channel,
        )

    if status:
        statement = statement.where(
            Notification.status == status,
        )

    if search:
        like = f"%{search}%"

        statement = statement.where(
            or_(
                Notification.recipient.ilike(like),
                Notification.subject.ilike(like),
                Notification.message.ilike(like),
            )
        )

    total = db.scalar(
        select(func.count())
        .select_from(statement.subquery())
    )

    notifications = db.scalars(
        statement.order_by(
            Notification.created_at.desc()
        )
        .offset(offset)
        .limit(limit)
    ).all()

    return notifications, total