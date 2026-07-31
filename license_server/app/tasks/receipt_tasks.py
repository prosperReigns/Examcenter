from uuid import UUID

from app.celery_app import celery_app
from app.tasks.base import db_session

from app.repositories.receipt_repository import (
    get_receipt,
)

from app.services.receipt_pdf_service import (
    generate_receipt_pdf,
)


@celery_app.task(
    queue="reports",
)
def generate_receipt_pdf_task(
    receipt_id: str,
):

    with db_session() as db:

        receipt = get_receipt(
            db,
            UUID(receipt_id),
        )

        if receipt is None:

            return

        path = generate_receipt_pdf(
            receipt,
        )

        receipt.pdf_path = str(path)

        db.add(receipt)

        return str(path)
    
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