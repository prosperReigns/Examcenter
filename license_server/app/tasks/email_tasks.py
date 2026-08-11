from uuid import UUID

from app.celery_app import celery_app
from app.database.session import SessionLocal
from app.services.receipt_service import email_receipt
from app.tasks.receipt_tasks import generate_receipt_pdf_task
from app.tasks.notification_tasks import queue_notification
from app.services.receipt_pdf_service import (
    generate_receipt_pdf,
)

@celery_app.task(queue="emails",
    bind=True,
    autoretry_for=(Exception,),
    retry_backoff=True,
    retry_kwargs={"max_retries": 5},
)
def send_email_task(
    self,
    notification_id,
):
    """Send an email notification through the common notification worker."""

    return queue_notification(notification_id)


@celery_app.task(queue="emails")
def send_receipt_email_task(
    receipt_id: str,
):
    db = SessionLocal()
    try:
        receipt = email_receipt(
            db,
            UUID(str(receipt_id)),
        )
        return {
            "status": "queued",
            "receipt_id": str(receipt.id),
        }
    finally:
        db.close()

@celery_app.task(
    queue="emails",
)
def email_receipt_task(
    receipt_id: str,
):

    generate_receipt_pdf_task.delay(
        receipt_id,
    )

    from app.tasks.email_tasks import send_receipt_email_task

    send_receipt_email_task.delay(
        receipt_id,
    )
