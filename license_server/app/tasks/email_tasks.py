from app.celery_app import celery_app


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
    """
    Load the notification from the database,
    send the email, update its status,
    and record success or failure.
    """