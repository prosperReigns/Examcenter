from __future__ import annotations

import hmac
import secrets
from datetime import datetime, timezone
from typing import Any

import httpx
from fastapi import HTTPException, Request, status
from sqlalchemy.orm import Session

from app.core.config import get_settings
from app.models.payment import Payment
from app.repositories.customer_repository import get_customer_by_id
from app.repositories.payment_repository import (
    create_payment_record,
    get_payment_by_id,
    get_payment_by_transaction_id,
    get_payment_by_tx_ref,
    persist_payment,
)
from app.repositories.school_repository import get_school_by_id
from app.schemas.license import LicenseCreateRequest
from app.schemas.payment import FlutterwaveWebhookPayload, PaymentInitializeRequest, PaymentInitializationResponse, PaymentRead, PaymentVerifyRequest
from app.services.audit_service import record_audit_event
from app.services.license_management_service import issue_license
from app.services.license_service import normalize_license_type
from app.utils.invoice import build_invoice_payload, save_invoice_document

settings = get_settings()

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


def handle_flutterwave_webhook(db: Session, request: Request, payload: FlutterwaveWebhookPayload) -> dict[str, Any]:
    header_hash = request.headers.get(settings.flutterwave_webhook_secret_header, "")
    if not header_hash or not hmac.compare_digest(header_hash, settings.flutterwave_hash):
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid webhook signature")

    event = (payload.event or "").lower()
    if event not in {"charge.completed", "transfer.completed", "refund.completed"}:
        return {"status": "ignored", "event": event}

    data = payload.data
    tx_ref = str(data.get("tx_ref") or "")
    transaction_id = str(data.get("id") or data.get("flw_ref") or "")
    payment = get_payment_by_tx_ref(db, tx_ref) if tx_ref else None
    if payment is None and transaction_id:
        payment = get_payment_by_transaction_id(db, transaction_id)
    if payment is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Payment not found")

    verify_payload = PaymentVerifyRequest(tx_ref=payment.flutterwave_tx_ref, transaction_id=transaction_id or payment.flutterwave_transaction_id)
    verified_payment = verify_payment(db, verify_payload, admin=None, request=request)
    return {"status": "processed", "payment_id": str(verified_payment.id)}
