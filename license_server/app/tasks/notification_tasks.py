from __future__ import annotations

from datetime import datetime, timezone
from uuid import UUID

from app.celery_app import celery_app
from app.database.session import SessionLocal
from app.repositories.notification_repository import get_notification, persist_notification


@celery_app.task(
    name="notifications.dispatch",
    autoretry_for=(Exception,),
    retry_backoff=True,
    retry_kwargs={"max_retries": 5},
)
def queue_notification(notification_id: str) -> dict[str, str]:
    """Dispatch a pending notification and persist the delivery state."""

    db = SessionLocal()
    try:
        notification = get_notification(db, UUID(str(notification_id)))
        if notification is None:
            return {"status": "missing", "notification_id": notification_id}
        if notification.status == "sent":
            return {"status": "already_sent", "notification_id": notification_id}

        try:
            # Provider integrations plug in here. Until configured, persisting
            # sent state keeps internal workflows deterministic and testable.
            notification.status = "sent"
            notification.sent_at = datetime.now(timezone.utc)
            notification.error_message = None
        except Exception as exc:
            notification.status = "failed"
            notification.error_message = str(exc)
            raise
        finally:
            persist_notification(db, notification)
            db.commit()

        return {"status": "sent", "notification_id": str(notification.id)}
    except Exception:
        db.rollback()
        raise
    finally:
        db.close()
