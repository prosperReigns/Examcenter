from app.celery_app import celery_app

@celery_app.task
def expire_licenses():
    """
    Find licenses whose expiry date
    has passed and mark them expired.
    """


@celery_app.task
def send_expiry_reminders():
    """
    Notify customers whose licenses
    expire within the configured
    reminder window.
    """