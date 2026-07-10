from app.celery_app import celery_app

@celery_app.task
def generate_receipt_pdf_task(receipt_id: str):
    """
    Generate or regenerate the receipt PDF
    and update the stored pdf_path.
    """


@celery_app.task
def email_receipt_task(receipt_id: str):
    """
    Queue the receipt email after
    ensuring the PDF exists.
    """