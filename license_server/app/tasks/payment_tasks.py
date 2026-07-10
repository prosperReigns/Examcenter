from app.celery_app import celery_app

@celery_app.task
def verify_flutterwave_payment(transaction_id: str):
    """
    Verify a Flutterwave transaction,
    update the payment status,
    renew the license if successful,
    create a receipt,
    and trigger notifications.
    """


@celery_app.task
def verify_paystack_payment(reference: str):
    """
    Verify a Paystack transaction
    and process it using the same
    business workflow.
    """