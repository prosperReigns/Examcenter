from __future__ import annotations
from uuid import UUID

import hmac
import json
import logging
import secrets
from datetime import datetime, timezone
from decimal import Decimal, InvalidOperation
from typing import Any

import httpx
from fastapi import HTTPException, Request, status
from sqlalchemy import extract, func, select
from sqlalchemy.orm import Session

from app.core.config import get_settings
from app.enums.purchase_status import PurchaseStatus
from app.models.payment import Payment
from app.models.invoice import Invoice
from app.repositories.customer_repository import get_customer_by_id
from app.repositories.invoice_repository import (
    get_invoice_by_id,
    persist_invoice,
    list_invoices
)
from app.repositories.payment_repository import (
    create_payment,
    get_payment,
    list_payments,
    create_payment_record,
    get_payment_by_transaction_id,
    get_payment_by_tx_ref,
    persist_payment,
)
from app.repositories.school_repository import get_school_by_id
from app.schemas.license import LicenseCreateRequest
from app.schemas.payment import FlutterwaveWebhookPayload, PaymentInitializeRequest, PaymentInitializationResponse, PaymentRead, PaymentVerifyRequest, PaymentCreateRequest
from app.services.audit_service import record_audit_event
from app.services.license_management_service import issue_license, renew_license
from app.services.invoice_service import (
    mark_invoice_paid,
)
from app.services.receipt_service import (
    generate_receipt,
)
from app.utils.invoice import build_invoice_payload, save_invoice_document

from app.repositories.purchase_session_repository import get_purchase_session_by_reference
from app.repositories.purchase_session_repository import save_purchase_session
from app.services.purchase_state_machine import validate_transition
from app.tasks.purchase_tasks import orchestrate_purchase
settings = get_settings()
logger = logging.getLogger(__name__)

LICENSE_PRICE_MAP = {
    "demo": 0,
    "monthly": int(settings.monthly_price),
    "quarterly": int(settings.quarterly_price),
    "annual": int(settings.annual_price),
    "lifetime": int(settings.lifetime_price),
}


def normalize_payment_type(payment_type: str) -> str:
    normalized = payment_type.strip().lower()
    if normalized not in LICENSE_PRICE_MAP:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="Unsupported payment type")
    return normalized


def get_payment_amount(payment_type: str) -> int:
    return LICENSE_PRICE_MAP[normalize_payment_type(payment_type)]


def build_flutterwave_tx_ref() -> str:
    return f"LS-{datetime.now(timezone.utc):%Y%m%d%H%M%S}-{secrets.token_hex(4).upper()}"


def _authorized_client() -> httpx.Client:
    headers = {
        "Authorization": f"Bearer {settings.flutterwave_secret_key}",
        "Content-Type": "application/json",
    }
    return httpx.Client(base_url=settings.flutterwave_base_url, headers=headers, timeout=30.0)


def initialize_payment(db: Session, payload: PaymentInitializeRequest, *, admin=None, request: Request | None = None) -> PaymentInitializationResponse:
    customer = get_customer_by_id(db, payload.customer_id)
    if customer is None or customer.deleted_at is not None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Customer not found")

    school = get_school_by_id(db, payload.school_id)
    if school is None or school.deleted_at is not None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="School not found")

    payment_type = normalize_payment_type(payload.payment_type)
    amount = get_payment_amount(payment_type)
    tx_ref = build_flutterwave_tx_ref()

    payment = create_payment_record(
        db,
        customer_id=payload.customer_id,
        school_id=payload.school_id,
        flutterwave_tx_ref=tx_ref,
        amount=amount,
        currency=settings.license_currency,
        payment_type=payment_type,
    )

    authorization_url: str | None = None
    try:
        response = _authorized_client().post(
            "/payments",
            json={
                "tx_ref": tx_ref,
                "amount": amount,
                "currency": settings.license_currency,
                "redirect_url": None,
                "customer": {
                    "email": customer.email,
                    "name": customer.name,
                },
                "customizations": {
                    "title": settings.company_name,
                    "description": f"{payment_type.title()} license purchase",
                },
            },
        )
        response.raise_for_status()
        data = response.json().get("data", {})
        authorization_url = data.get("link")
    except Exception:
        db.rollback()
        raise

    db.commit()
    db.refresh(payment)
    record_audit_event(
        db,
        admin=admin,
        action="payment_initialized",
        entity_type="payment",
        entity_id=str(payment.id),
        description=f"Initialized {payment_type} payment for customer {customer.id}",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )
    db.commit()
    return PaymentInitializationResponse(payment=PaymentRead.model_validate(payment), authorization_url=authorization_url, tx_ref=tx_ref)


def verify_payment(db: Session, payload: PaymentVerifyRequest, *, admin=None, request: Request | None = None) -> Payment:
    payment = get_payment_by_tx_ref(db, payload.tx_ref)
    if payment is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Payment not found")

    if payload.transaction_id and payment.flutterwave_transaction_id and payment.flutterwave_transaction_id != payload.transaction_id:
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="Transaction mismatch")

    verification_data: dict[str, Any] = {}
    try:
        if payload.transaction_id:
            response = _authorized_client().get(f"/transactions/{payload.transaction_id}/verify")
            response.raise_for_status()
            verification_data = response.json().get("data", {})
        else:
            response = _authorized_client().get(f"/transactions/verify_by_reference?tx_ref={payload.tx_ref}")
            response.raise_for_status()
            verification_data = response.json().get("data", {})
    except Exception as exc:
        raise HTTPException(status_code=status.HTTP_502_BAD_GATEWAY, detail=f"Flutterwave verification failed: {exc}") from exc

    status_value = str(verification_data.get("status", "")).lower()
    expected_amount = int(payment.amount)
    expected_currency = payment.currency.upper()
    amount_value = int(float(verification_data.get("amount", 0)))
    currency_value = str(verification_data.get("currency", "")).upper()

    if status_value != "successful" or amount_value != expected_amount or currency_value != expected_currency:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="Payment verification failed")

    payment.flutterwave_transaction_id = str(verification_data.get("id")) if verification_data.get("id") is not None else payment.flutterwave_transaction_id
    payment.status = "successful"
    payment.verified_at = datetime.now(timezone.utc)
    payment.raw_payload = str(verification_data)

    customer = get_customer_by_id(db, payment.customer_id)
    school = get_school_by_id(db, payment.school_id) if payment.school_id is not None else None
    if customer is None or customer.deleted_at is not None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Customer not found")
    if payment.school_id is not None and (school is None or school.deleted_at is not None):
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="School not found")

    license_obj = None
    if payment.license_id is None:
        license_obj = issue_license(
            db,
            payload=LicenseCreateRequest(
                school_id=payment.school_id or school.id,
                machine_fingerprint=f"payment:{payment.flutterwave_tx_ref}",
                license_type=payment.payment_type,
                version=1,
            ),
            admin=admin,
            request=request,
        )

    if license_obj is not None:
        payment.license_id = license_obj.id
        invoice_payload = build_invoice_payload(
            payment_ref=payment.flutterwave_tx_ref,
            amount=payment.amount,
            currency=payment.currency,
            payment_type=payment.payment_type,
            customer_name=customer.name,
            school_name=school.name if school else "",
            license_payload={"license_id": str(license_obj.id), "signed_license": license_obj.signed_license},
        )
        payment.invoice_path = save_invoice_document(f"{payment.flutterwave_tx_ref}.json", invoice_payload)

    persist_payment(db, payment)
    db.commit()
    db.refresh(payment)

    record_audit_event(
        db,
        admin=admin,
        action="payment_verified",
        entity_type="payment",
        entity_id=str(payment.id),
        description=f"Verified payment {payment.flutterwave_tx_ref}",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )
    db.commit()
    return payment

def mark_payment_successful(
    db: Session,
    *,
    payment_reference: str,
    gateway_reference: str | None = None,
    gateway_response: str | None = None,
    admin=None,
    request=None,
) -> Payment:

    payment = verify_payment(
        db,
        payment_reference,
    )

    if payment.status == "successful":
        return payment

    if payment.status == "cancelled":
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Cancelled payment cannot be completed.",
        )

    payment.status = "successful"

    payment.gateway_reference = gateway_reference

    payment.gateway_response = gateway_response

    payment.verified_at = datetime.now(timezone.utc)

    payment.paid_at = datetime.now(timezone.utc)

    persist_payment(
        db,
        payment,
    )

    mark_invoice_paid(
        db,
        invoice_id=payment.invoice_id,
        admin=admin,
        request=request,
    )

    renew_license(
        db,
        invoice_id=payment.invoice_id,
        admin=admin,
        request=request,
    )

    generate_receipt(
        db,
        payment,
    )

    record_audit_event(
        db,
        admin=admin,
        action="payment_successful",
        entity_type="payment",
        entity_id=str(payment.id),
        description=f"Payment {payment.payment_reference} marked successful.",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )

    db.commit()

    db.refresh(payment)

    return payment

def mark_payment_failed(
    db: Session,
    *,
    payment_reference: str,
    gateway_response: str | None = None,
    admin=None,
    request=None,
) -> Payment:

    payment = verify_payment(
        db,
        payment_reference,
    )

    if payment.status == "successful":
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Successful payment cannot be marked as failed.",
        )

    payment.status = "failed"

    payment.gateway_response = gateway_response

    payment.verified_at = datetime.now(timezone.utc)

    persist_payment(
        db,
        payment,
    )

    record_audit_event(
        db,
        admin=admin,
        action="payment_failed",
        entity_type="payment",
        entity_id=str(payment.id),
        description=f"Payment {payment.payment_reference} failed.",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )

    db.commit()

    db.refresh(payment)

    return payment

def cancel_payment(
    db: Session,
    *,
    payment_reference: str,
    admin=None,
    request=None,
) -> Payment:

    payment = verify_payment(
        db,
        payment_reference,
    )

    if payment.status == "successful":
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Successful payment cannot be cancelled.",
        )

    payment.status = "cancelled"

    persist_payment(
        db,
        payment,
    )

    record_audit_event(
        db,
        admin=admin,
        action="payment_cancelled",
        entity_type="payment",
        entity_id=str(payment.id),
        description=f"Payment {payment.payment_reference} cancelled.",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )

    db.commit()

    db.refresh(payment)

    return payment

def payment_is_processable(
    payment: Payment,
) -> bool:

    return payment.status == "pending"


def queue_purchase_orchestration(
    *,
    session_id: UUID,
    payment_reference: str,
) -> str:
    try:
        async_result = orchestrate_purchase.apply_async(
            kwargs={
                "session_id": str(session_id),
                "payment_reference": payment_reference,
            },
        )
    except Exception as exc:
        logger.exception("Failed to enqueue purchase orchestration for session %s", session_id)
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail="Purchase orchestration queue unavailable",
        ) from exc

    return str(async_result.id)


async def handle_flutterwave_webhook(db: Session, request: Request, payload: FlutterwaveWebhookPayload) -> dict[str, Any]:
    header_hash = request.headers.get(settings.flutterwave_webhook_secret_header, "")
    if not header_hash or not hmac.compare_digest(header_hash, settings.flutterwave_hash):
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid webhook signature")

    event = (payload.event or "").lower()
    if event != "charge.completed":
        return {"status": "ignored", "event": event}

    data = payload.data
    tx_ref = str(data.get("tx_ref") or data.get("payment_reference") or "").strip()
    if not tx_ref:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="Missing payment reference")

    purchase_session = get_purchase_session_by_reference(db, tx_ref)
    if purchase_session is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Purchase session not found")
    if purchase_session.completed:
        return {
            "status": "processed",
            "session_id": str(purchase_session.id),
            "license_id": str(purchase_session.license_id) if purchase_session.license_id else None,
        }

    payment_status = str(data.get("status") or "").lower()
    if payment_status != "successful":
        if purchase_session.status != PurchaseStatus.PAYMENT_PENDING.value:
            validate_transition(purchase_session.status, PurchaseStatus.PAYMENT_PENDING)
            purchase_session.status = PurchaseStatus.PAYMENT_PENDING.value
        save_purchase_session(db, purchase_session)
        db.commit()
        return {"status": "ignored", "event": event, "payment_status": payment_status}

    try:
        paid_amount = Decimal(str(data.get("amount", "0")))
    except (InvalidOperation, ValueError) as exc:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="Invalid payment amount") from exc

    paid_currency = str(data.get("currency") or "").upper()
    if paid_amount != Decimal(purchase_session.amount) or paid_currency != purchase_session.currency.upper():
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="Payment verification failed")

    transaction_id = str(data.get("id") or "").strip() or None
    gateway_reference = str(data.get("flw_ref") or data.get("reference") or "").strip() or None

    if purchase_session.status != PurchaseStatus.PAYMENT_VERIFIED.value:
        validate_transition(purchase_session.status, PurchaseStatus.PAYMENT_VERIFIED)
        purchase_session.status = PurchaseStatus.PAYMENT_VERIFIED.value
    purchase_session.payment_reference = tx_ref
    purchase_session.gateway = "flutterwave"
    purchase_session.gateway_reference = gateway_reference
    purchase_session.gateway_transaction_id = transaction_id
    purchase_session.gateway_response = json.dumps(data, default=str)
    save_purchase_session(db, purchase_session)
    record_audit_event(
        db,
        action="purchase_payment_verified",
        entity_type="purchase_session",
        entity_id=str(purchase_session.id),
        description="Flutterwave webhook verified payment and queued orchestration.",
    )
    db.commit()

    task_id = queue_purchase_orchestration(
        session_id=purchase_session.id,
        payment_reference=tx_ref,
    )
    return {
        "status": "queued",
        "session_id": str(purchase_session.id),
        "task_id": task_id,
    }

def generate_payment_reference(
    db: Session,
) -> str:

    year = datetime.now().year

    count = db.scalar(
        select(func.count())
        .select_from(Payment)
        .where(
            extract("year", Payment.created_at) == year
        )
    ) or 0

    return f"PAY-{year}-{count+1:06d}"

def get_payment_record(
    db: Session,
    payment_id: UUID,
) -> Payment:

    payment = get_payment(
        db,
        payment_id,
    )

    if payment is None:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Payment not found",
        )

    return payment

def get_payment_list(
    db: Session,
    *,
    search: str | None = None,
    status_filter: str | None = None,
    school_id: UUID | None = None,
    invoice_id: UUID | None = None,
    page: int = 1,
    page_size: int = 20,
):

    offset = (page - 1) * page_size

    return list_payments(
        db,
        search=search,
        status=status_filter,
        school_id=school_id,
        invoice_id=invoice_id,
        offset=offset,
        limit=page_size,
    )

def create_payment_record(
    db: Session,
    payload: PaymentCreateRequest,
    *,
    gateway: str | None = None,
    admin=None,
    request=None,
) -> Payment:

    invoice = get_invoice_by_id(
        db,
        payload.invoice_id,
    )

    if invoice is None:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Invoice not found",
        )

    if invoice.status != "pending":
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Invoice is not awaiting payment",
        )

    payment = Payment(

        customer_id=invoice.customer_id,

        school_id=invoice.school_id,

        invoice_id=invoice.id,

        payment_reference=generate_payment_reference(db),

        gateway=gateway,

        gateway_reference=None,

        amount=invoice.amount,

        currency=invoice.currency,

        payment_method=payload.payment_method,

        status="pending",

    )

    create_payment(
        db,
        payment,
    )

    db.commit()

    db.refresh(payment)

    record_audit_event(
        db,
        admin=admin,
        action="payment_created",
        entity_type="payment",
        entity_id=str(payment.id),
        description=f"Created payment {payment.payment_reference}",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )

    db.commit()

    return payment

def total_revenue(
    db: Session,
):

    statement = (
        select(
            func.sum(
                Payment.amount
            )
        )
        .where(
            Payment.status == "successful"
        )
    )

    return db.scalar(statement) or 0

def successful_payment_count(
    db: Session,
):

    statement = (
        select(func.count())
        .select_from(Payment)
        .where(
            Payment.status == "successful"
        )
    )

    return db.scalar(statement) or 0

def pending_payment_count(
    db: Session,
):

    statement = (
        select(func.count())
        .select_from(Payment)
        .where(
            Payment.status == "pending"
        )
    )

    return db.scalar(statement) or 0

def failed_payment_count(
    db: Session,
):

    statement = (
        select(func.count())
        .select_from(Payment)
        .where(
            Payment.status == "failed"
        )
    )

    return db.scalar(statement) or 0

def refund_payment(
    db: Session,
    *,
    payment_reference: str,
    reason: str | None = None,
    admin=None,
    request=None,
) -> Payment:

    payment = verify_payment(
        db,
        payment_reference,
    )

    if payment.status != "successful":
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Only successful payments can be refunded.",
        )

    payment.status = "refunded"

    response = {
        "refund_reason": reason,
        "refunded_at": datetime.now(timezone.utc).isoformat(),
    }

    payment.gateway_response = json.dumps(response)

    persist_payment(
        db,
        payment,
    )

    record_audit_event(
        db,
        admin=admin,
        action="payment_refunded",
        entity_type="payment",
        entity_id=str(payment.id),
        description=f"Refunded payment {payment.payment_reference}",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )

    db.commit()

    db.refresh(payment)

    return payment

def revenue_today(
    db: Session,
):

    today = datetime.now(timezone.utc).date()

    statement = (
        select(
            func.sum(
                Payment.amount
            )
        )
        .where(
            Payment.status == "successful",
            func.date(
                Payment.paid_at
            ) == today,
        )
    )

    return db.scalar(statement) or 0

def revenue_this_month(
    db: Session,
):

    now = datetime.now(timezone.utc)

    statement = (
        select(
            func.sum(Payment.amount)
        )
        .where(
            Payment.status == "successful",
            extract(
                "year",
                Payment.paid_at,
            ) == now.year,
            extract(
                "month",
                Payment.paid_at,
            ) == now.month,
        )
    )

    return db.scalar(statement) or 0

def revenue_this_year(
    db: Session,
):

    year = datetime.now(timezone.utc).year

    statement = (
        select(
            func.sum(Payment.amount)
        )
        .where(
            Payment.status == "successful",
            extract(
                "year",
                Payment.paid_at,
            ) == year,
        )
    )

    return db.scalar(statement) or 0

def outstanding_invoice_total(
    db: Session,
):

    invoices, _ = list_invoices(
        db,
        status="pending",
        offset=0,
        limit=100000,
    )

    return sum(
        invoice.amount
        for invoice in invoices
    )

def get_payment_dashboard_stats(
    db: Session,
):

    return {

        "successful_payments":
            successful_payment_count(db),

        "pending_payments":
            pending_payment_count(db),

        "failed_payments":
            failed_payment_count(db),

        "revenue_today":
            revenue_today(db),

        "revenue_month":
            revenue_this_month(db),

        "revenue_year":
            revenue_this_year(db),

        "total_revenue":
            total_revenue(db),

        "outstanding_invoices":
            outstanding_invoice_total(db),

    }

def revenue_by_payment_method(
    db: Session,
):

    statement = (
        select(
            Payment.payment_method,
            func.sum(Payment.amount),
        )
        .where(
            Payment.status == "successful"
        )
        .group_by(
            Payment.payment_method
        )
    )

    return db.execute(statement).all()

def monthly_revenue(
    db: Session,
    year: int,
):

    statement = (
        select(
            extract(
                "month",
                Payment.paid_at,
            ),
            func.sum(
                Payment.amount,
            ),
        )
        .where(
            Payment.status == "successful",
            extract(
                "year",
                Payment.paid_at,
            ) == year,
        )
        .group_by(
            extract(
                "month",
                Payment.paid_at,
            )
        )
        .order_by(
            extract(
                "month",
                Payment.paid_at,
            )
        )
    )

    return db.execute(statement).all()

def top_paying_schools(
    db: Session,
    limit: int = 10,
):

    statement = (
        select(
            Payment.school_id,
            func.sum(
                Payment.amount,
            ).label("total"),
        )
        .where(
            Payment.status == "successful"
        )
        .group_by(
            Payment.school_id,
        )
        .order_by(
            func.sum(
                Payment.amount,
            ).desc()
        )
        .limit(limit)
    )

    return db.execute(statement).all()

def initialize_invoice_payment(
    db,
    invoice,
    customer,
):
    payment = Payment(

        invoice_id=invoice.id,

        customer_id=customer.id,

        school_id=invoice.school_id,

        amount=invoice.amount,

        currency=invoice.currency,

        status="pending",

        payment_type="flutterwave",

    )

    gateway.initialize_payment(

        invoice,

        customer,

    )
