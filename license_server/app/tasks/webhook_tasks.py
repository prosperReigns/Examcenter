from __future__ import annotations

import logging
from datetime import datetime, timedelta, timezone

from sqlalchemy import delete

from app.celery_app import celery_app
from app.database.session import SessionLocal
from app.models.webhook_event import WebhookEvent

logger = logging.getLogger(__name__)


@celery_app.task(
    bind=True,
    name="webhooks.cleanup",
    max_retries=3,
)
def cleanup_webhook_events(
    self,
    *,
    retention_days: int = 180,
) -> dict[str, int]:
    """
    Delete processed webhook events older than the
    configured retention period.

    This keeps the webhook_events table from growing
    indefinitely while preserving enough history for
    auditing and troubleshooting.
    """

    db = SessionLocal()

    try:

        cutoff = (
            datetime.now(timezone.utc)
            - timedelta(days=retention_days)
        )

        result = db.execute(

            delete(WebhookEvent).where(

                WebhookEvent.processed_at < cutoff

            )

        )

        deleted = result.rowcount or 0

        db.commit()

        logger.info(

            "Deleted %s expired webhook events",

            deleted,

        )

        return {

            "deleted": deleted,

            "retention_days": retention_days,

        }

    except Exception as exc:

        db.rollback()

        logger.exception(

            "Webhook cleanup failed"

        )

        raise self.retry(

            exc=exc,

            countdown=min(

                300,

                60 * (self.request.retries + 1),

            ),

        )

    finally:

        db.close()