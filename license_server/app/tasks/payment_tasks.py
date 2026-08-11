from __future__ import annotations

import json
from datetime import datetime, timezone

from app.celery_app import celery_app
from app.database.session import SessionLocal
from app.core.config import get_settings
from app.enums.purchase_status import PurchaseStatus

from app.repositories.payment_repository import (
    get_payment_by_reference,
    get_payment_by_transaction_id,
    persist_payment,
)
from app.repositories.purchase_session_repository import get_purchase_session_by_reference, save_purchase_session

from app.repositories.payment_repository import expire_pending_payments as expire_pending_payment_records
from app.services.license_renewal_service import renew_license_from_payment
from app.services.purchase_state_machine import validate_transition
from app.tasks.purchase_tasks import orchestrate_purchase
from uuid import UUID

settings = get_settings()


def _mark_purchase_session_paid(db, *, reference: str, gateway: str, data: dict) -> str | None:
    purchase_session = get_purchase_session_by_reference(db, reference)
    if purchase_session is None or purchase_session.completed:
        return None

    if purchase_session.status != PurchaseStatus.PAYMENT_VERIFIED.value:
        validate_transition(purchase_session.status, PurchaseStatus.PAYMENT_VERIFIED)
        purchase_session.status = PurchaseStatus.PAYMENT_VERIFIED.value

    purchase_session.gateway = gateway
    purchase_session.gateway_reference = str(data.get("flw_ref") or data.get("reference") or "").strip() or None
    purchase_session.gateway_transaction_id = str(data.get("id") or data.get("transaction_id") or "").strip() or None
    purchase_session.gateway_response = json.dumps(data, default=str)
    save_purchase_session(db, purchase_session)
    db.commit()

    async_result = orchestrate_purchase.apply_async(
        kwargs={
            "session_id": str(purchase_session.id),
            "payment_reference": reference,
        },
        queue="purchase"
    )
    return str(async_result.id)


def _mark_payment_successful(db, *, reference: str, transaction_id: str | None, gateway: str, data: dict) -> str | None:
    payment = (
        get_payment_by_transaction_id(db, transaction_id)
        if transaction_id
        else None
    ) or get_payment_by_reference(db, reference)
    if payment is None:
        return None

    payment.status = "successful"
    payment.gateway = gateway
    payment.gateway_reference = str(data.get("flw_ref") or data.get("reference") or "").strip() or payment.gateway_reference
    payment.gateway_transaction_id = transaction_id or payment.gateway_transaction_id
    payment.verified_at = payment.verified_at or datetime.now(timezone.utc)
    payment.paid_at = payment.paid_at or datetime.now(timezone.utc)
    payment.raw_payload = payment.raw_payload or json.dumps(data, default=str)
    persist_payment(db, payment)
    db.commit()

    renew_license_from_payment(db, payment_id=payment.id)
    return str(payment.id)

@celery_app.task(
    name="payments.expire_pending",
)
def expire_pending_payments():

    db = SessionLocal()

    try:

        return expire_pending_payment_records(db)

    finally:

        db.close()
