from app.celery_app import celery_app

@celery_app.task(
    autoretry_for=(Exception,),
    retry_backoff=True,
    retry_kwargs={"max_retries": 5},
)
def queue_notification(notification_id: str):
    """
    Load the notification from the database,
    determine its channel (email, SMS, WhatsApp),
    dispatch it to the appropriate provider,
    update the status, and record any errors.
    """