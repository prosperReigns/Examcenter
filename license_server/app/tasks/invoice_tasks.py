from app.celery_app import celery_app

@celery_app.task
def generate_invoice_pdf_task(invoice_id: str):
    """
    Generate the invoice PDF
    and store its path.
    """


@celery_app.task
def send_invoice_task(invoice_id: str):
    """
    Queue invoice delivery
    to the customer.
    """