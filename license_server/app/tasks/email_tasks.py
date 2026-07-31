from app.celery_app import celery_app
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