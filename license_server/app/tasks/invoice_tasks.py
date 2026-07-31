from __future__ import annotations

from pathlib import Path
from uuid import UUID

from app.celery_app import celery_app
from app.database.session import SessionLocal
from app.repositories.invoice_repository import get_invoice_by_id
from app.services.invoice_pdf_service import generate_invoice_pdf
from app.services.notification_service import create_notification_record

from app.tasks.base import db_session

from app.repositories.invoice_repository import (
    get_invoice,
)

from app.services.invoice_pdf_service import (
    generate_invoice_pdf,
)

from app.tasks.email_tasks import (
    send_email_task,
)

INVOICE_DIR = Path("storage") / "invoices"


@celery_app.task(
    queue="reports",
    autoretry_for=(Exception,),
    retry_backoff=True,
    retry_kwargs={"max_retries":5},
)
def generate_invoice_pdf_task(
    invoice_id: str,
):

    with db_session() as db:

        invoice = get_invoice(
            db,
            UUID(invoice_id),
        )

        if invoice is None:

            return

        path = generate_invoice_pdf(
            invoice,
        )

        invoice.pdf_path = str(path)

        db.add(invoice)

        return str(path)

@celery_app.task(
    queue="emails",
)
def send_invoice_task(
    invoice_id: str,
):

    generate_invoice_pdf_task.delay(
        invoice_id,
    )

    send_email_task.delay(
        invoice_id,
    )