from __future__ import annotations

from pathlib import Path
from uuid import UUID

from app.celery_app import celery_app
from app.database.session import SessionLocal
from app.repositories.invoice_repository import get_invoice_by_id
from app.services.invoice_pdf_service import generate_invoice_pdf
from app.services.notification_service import create_notification_record

INVOICE_DIR = Path("storage") / "invoices"


@celery_app.task(name="invoices.generate_pdf")
def generate_invoice_pdf_task(invoice_id: str) -> dict[str, str]:
    """Generate the invoice PDF and store it under storage/invoices."""

    db = SessionLocal()
    try:
        invoice = get_invoice_by_id(db, UUID(str(invoice_id)))
        if invoice is None:
            return {"status": "missing", "invoice_id": invoice_id}

        INVOICE_DIR.mkdir(parents=True, exist_ok=True)
        pdf_path = INVOICE_DIR / f"{invoice.invoice_number}.pdf"
        pdf_path.write_bytes(generate_invoice_pdf(invoice))
        return {"status": "generated", "invoice_id": str(invoice.id), "pdf_path": str(pdf_path)}
    finally:
        db.close()


@celery_app.task(name="invoices.send")
def send_invoice_task(invoice_id: str) -> dict[str, str]:
    """Queue invoice delivery to the school's billing contact."""

    db = SessionLocal()
    try:
        invoice = get_invoice_by_id(db, UUID(str(invoice_id)))
        if invoice is None:
            return {"status": "missing", "invoice_id": invoice_id}

        recipient = invoice.school.contact_email if invoice.school else None
        if not recipient:
            return {"status": "skipped", "reason": "missing_recipient", "invoice_id": str(invoice.id)}

        notification = create_notification_record(
            db,
            customer_id=invoice.school.customer_id if invoice.school else None,
            school_id=invoice.school_id,
            channel="email",
            recipient=recipient,
            subject=f"Invoice {invoice.invoice_number}",
            message=f"Invoice {invoice.invoice_number} is ready for payment.",
        )
        return {"status": "queued", "notification_id": str(notification.id)}
    finally:
        db.close()
