from app.celery_app import celery_app
from app.tasks.notification_tasks import queue_notification


@celery_app.task(
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
