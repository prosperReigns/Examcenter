from __future__ import annotations

import json
from datetime import datetime, timezone

from app.celery_app import celery_app
from app.database.session import SessionLocal
from app.core.config import get_settings
from app.enums.purchase_status import PurchaseStatus
from app.gateways.flutterwave import FlutterwaveGateway
from app.gateways.paystack import PaystackGateway
from app.repositories.payment_repository import (
    get_payment_by_reference,
    get_payment_by_transaction_id,
    persist_payment,
)
from app.repositories.purchase_session_repository import get_purchase_session_by_reference, save_purchase_session

from app.repositories.payment_repository import PaymentRepository
from app.services.license_renewal_service import renew_license_from_payment
from app.services.purchase_state_machine import validate_transition
from app.tasks.purchase_tasks import orchestrate_purchase
from uuid import UUID

from app.tasks.base import db_session

from app.services.payment_service import (
    verify_payment
)

from app.services.invoice_service import (
    mark_invoice_paid,
)

from app.services.receipt_service import (
    create_receipt_from_payment,
)

from app.services.license_renewal_service import (
    renew_license_after_payment,
)

from app.tasks.notification_tasks import (
    queue_notification,
)
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
        }
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
    queue="payments",
    bind=True,
    autoretry_for=(Exception,),
    retry_backoff=True,
    retry_kwargs={"max_retries":5},
)
def verify_flutterwave_payment(
    self,
    transaction_id: str,
):

    with db_session() as db:

        payment = verify_payment(
            db,
            transaction_id,
        )

        if payment is None:

            return

        invoice = mark_invoice_paid(
            db,
            payment,
        )

        receipt = create_receipt_from_payment(
            db,
            payment,
        )

        renew_license_after_payment(
            db,
            payment,
        )

        queue_notification.delay(
            str(receipt.notification_id)
        )

        return str(receipt.id)
@celery_app.task(
    queue="payments",
    bind=True,
    autoretry_for=(Exception,),
    retry_backoff=True,
    retry_kwargs={"max_retries":5},
)
def verify_paystack_payment(
    self,
    reference: str,
):

    with db_session() as db:

        payment = verify_payment(
            db,
            reference,
        )

        if payment is None:

            return

        invoice = mark_invoice_paid(
            db,
            payment,
        )

        receipt = create_receipt_from_payment(
            db,
            payment,
        )

        renew_license_after_payment(
            db,
            payment,
        )

        queue_notification.delay(
            str(receipt.notification_id)
        )

        return str(receipt.id)

@celery_app.task(
    queue="payments",
)
def retry_payment_verification(
    payment_id: str,
):

    with db_session() as db:

        from app.repositories.payment_repository import (
            get_payment_by_id,
        )

        payment = get_payment_by_id(
            db,
            UUID(payment_id),
        )

        if payment is None:

            return

        if payment.gateway == "flutterwave":

            verify_flutterwave_payment.delay(
                payment.gateway_transaction_id
            )

        elif payment.gateway == "paystack":

            verify_paystack_payment.delay(
                payment.gateway_reference
            )

@celery_app.task(
    queue="payments",
)
def verify_pending_payments():

    with db_session() as db:

        from app.repositories.payment_repository import (
            list_pending_payments,
        )

        payments = list_pending_payments(db)

        for payment in payments:

            if payment.gateway == "flutterwave":

                verify_flutterwave_payment.delay(
                    payment.gateway_transaction_id
                )

            elif payment.gateway == "paystack":

                verify_paystack_payment.delay(
                    payment.gateway_reference
                )

@celery_app.task(
    name="payments.expire_pending",
)
def expire_pending_payments():

    db = SessionLocal()

    try:

        repo = PaymentRepository(db)

        return repo.expire_pending_payments()

    finally:

        db.close()