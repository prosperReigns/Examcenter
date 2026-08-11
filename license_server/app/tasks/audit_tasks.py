from __future__ import annotations

from uuid import UUID

from app.celery_app import celery_app
from app.database.session import SessionLocal
from app.repositories.payment_repository import get_payment_by_id
from app.services.audit_service import record_audit_event


@celery_app.task(name="audit_logs.create_payment_audit", queue="audit")
def create_audit_log_task(*, payment_id: str) -> str | None:
    db = SessionLocal()
    try:
        payment = get_payment_by_id(db, UUID(payment_id))
        if payment is None:
            return None

        record_audit_event(
            db,
            action="payment_completed",
            entity_type="payment",
            entity_id=str(payment.id),
            description=f"Payment {payment.payment_reference} completed successfully.",
        )
        db.commit()
        return str(payment.id)
    finally:
        db.close()