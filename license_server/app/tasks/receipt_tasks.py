from __future__ import annotations

from uuid import UUID

from app.celery_app import celery_app
from app.database.session import SessionLocal
from app.repositories.receipt_repository import get_receipt, persist_receipt
from app.services.notification_service import create_notification_record
from app.services.receipt_pdf_service import generate_receipt_pdf


@celery_app.task(name="receipts.generate_pdf")
def generate_receipt_pdf_task(receipt_id: str) -> dict[str, str]:
    """Generate or regenerate the receipt PDF and persist its path."""

    db = SessionLocal()
    try:
        receipt = get_receipt(db, UUID(str(receipt_id)))
        if receipt is None:
            return {"status": "missing", "receipt_id": receipt_id}
        receipt.pdf_path = generate_receipt_pdf(receipt)
        persist_receipt(db, receipt)
        db.commit()
        return {"status": "generated", "receipt_id": str(receipt.id), "pdf_path": receipt.pdf_path}
    except Exception:
        db.rollback()
        raise
    finally:
        db.close()


@celery_app.task(name="receipts.email")
def email_receipt_task(receipt_id: str) -> dict[str, str]:
    """Queue the receipt email after ensuring the PDF exists."""

    db = SessionLocal()
    try:
        receipt = get_receipt(db, UUID(str(receipt_id)))
        if receipt is None:
            return {"status": "missing", "receipt_id": receipt_id}
        if not receipt.pdf_path:
            receipt.pdf_path = generate_receipt_pdf(receipt)
            persist_receipt(db, receipt)
            db.commit()

        recipient = receipt.customer.email if receipt.customer else None
        if not recipient:
            return {"status": "skipped", "reason": "missing_recipient", "receipt_id": str(receipt.id)}

        notification = create_notification_record(
            db,
            customer_id=receipt.customer_id,
            school_id=receipt.school_id,
            channel="email",
            recipient=recipient,
            subject=f"Receipt {receipt.receipt_number}",
            message=f"Your payment receipt {receipt.receipt_number} has been generated.",
        )
        return {"status": "queued", "notification_id": str(notification.id)}
    finally:
        db.close()
