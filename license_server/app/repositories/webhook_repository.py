from datetime import datetime, timezone

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.models.payment_webhook import PaymentWebhook


def save_webhook(
    db: Session,
    webhook: PaymentWebhook,
) -> PaymentWebhook:

    db.add(webhook)
    db.flush()

    return webhook


def get_by_reference(
    db: Session,
    gateway_reference: str,
) -> PaymentWebhook | None:

    statement = (
        select(PaymentWebhook)
        .where(
            PaymentWebhook.gateway_reference == gateway_reference
        )
    )

    return db.scalar(statement)


def mark_processed(
    db: Session,
    webhook: PaymentWebhook,
) -> PaymentWebhook:

    webhook.processed = True
    webhook.processed_at = datetime.now(timezone.utc)
    webhook.error_message = None

    db.add(webhook)
    db.flush()

    return webhook


def list_failed_webhooks(
    db: Session,
):

    statement = (
        select(PaymentWebhook)
        .where(
            PaymentWebhook.processed.is_(False),
            PaymentWebhook.error_message.is_not(None),
        )
        .order_by(PaymentWebhook.created_at.asc())
    )

    return db.scalars(statement).all()


def retry_failed_webhook(
    db: Session,
    webhook: PaymentWebhook,
) -> PaymentWebhook:

    webhook.error_message = None

    db.add(webhook)
    db.flush()

    return webhook