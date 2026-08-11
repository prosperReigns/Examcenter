from __future__ import annotations

import json
import logging
from datetime import datetime, timezone
from decimal import Decimal
from uuid import UUID

from sqlalchemy.orm import Session
from fastapi import HTTPException, status
from app.repositories.license_repository import update_license, get_license_by_id
from app.repositories.license_renewal_repository import (
    create_license_renewal,
    get_license_renewal_by_payment,
    renewal_statistics,
)
from app.services.license_management_service import get_license
from app.services.license_service import (
    calculate_renewal_expiry,
    create_signed_license,
    get_license_duration_days,
    normalize_license_type,
)
from app.services.audit_service import record_audit_event
from app.core.config import get_settings
from app.repositories.license_history_repository import (
    create_history_record,
    list_license_history,
)
from app.models.outbox_event import OutboxEvent
from app.repositories.invoice_repository import get_invoice_by_id, mark_invoice_paid
from app.repositories.payment_repository import get_payment_by_id, get_payment_by_reference, persist_payment
from app.services.receipt_service import create_receipt_record
from app.core.pricing import LICENSE_PRICES
settings = get_settings()
logger = logging.getLogger(__name__)

PLAN_DURATION_MAP = {
    "trial": 7,
    "monthly": 30,
    "quarterly": 90,
    "6_month": 180,
    "6_months": 180,
    "annual": 365,
    "12_month": 365,
    "24_month": 730,
    "24_months": 730,
    "lifetime": None,
}

PLAN_PRICE_MAP = {
    "trial": 0,
    "monthly": 10000,
    "quarterly": 25000,
    "6_month": 50000,
    "6_months": 50000,
    "annual": 90000,
    "24_month": 170000,
    "24_months": 170000,
    "lifetime": 350000,
}

def get_plan_duration(plan: str):

    normalized = normalize_renewal_plan(plan)

    if normalized not in PLAN_DURATION_MAP:

        raise HTTPException(
            400,
            "Invalid renewal plan",
        )

    return PLAN_DURATION_MAP[normalized]


def normalize_renewal_plan(plan: str) -> str:
    normalized = plan.strip().lower().replace("-", "_")
    aliases = {
        "6": "6_month",
        "6_months": "6_month",
        "12": "annual",
        "12_month": "annual",
        "12_months": "annual",
        "24": "24_month",
        "24_months": "24_month",
    }
    normalized = aliases.get(normalized, normalized)
    return normalize_license_type(normalized)


def _queue_renewal_outbox_event(db: Session, *, license_obj, renewal, payment=None) -> None:
    existing = None
    if payment is not None:
        existing = get_license_renewal_by_payment(db, payment.id)
    payload = {
        "license_id": str(license_obj.id),
        "renewal_id": str(renewal.id),
        "payment_id": str(payment.id) if payment is not None else None,
        "school_id": str(license_obj.school_id),
        "new_expiry": renewal.new_expiry.isoformat() if renewal.new_expiry else None,
    }
    event = OutboxEvent(
        event_type="license.renewed",
        aggregate_type="license",
        aggregate_id=license_obj.id,
        payload=json.dumps(payload),
        processed=False,
        retry_count=0,
    )
    if existing is None or existing.id == renewal.id:
        db.add(event)
        db.flush()

def renew_license(
    db: Session,
    license_id,
    payload=None,
    *,
    plan: str | None = None,
    payment_id: UUID | None = None,
    amount: Decimal | float | int | None = None,
    currency: str | None = None,
    notes: str | None = None,
    admin=None,
    request=None,
):
    """
    Renew an existing license.
    This service also supports upgrades.
    """

    license_obj = get_license(db, license_id)
    old_type = license_obj.license_type
    old_expiry = license_obj.expiry_at

    requested_plan = plan or getattr(payload, "plan", None) or getattr(payload, "license_type", None)
    if not requested_plan:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="Renewal plan is required.")

    payment_id = payment_id or getattr(payload, "payment_id", None)
    notes = notes if notes is not None else getattr(payload, "notes", None)
    payment = get_payment_by_id(db, payment_id) if payment_id else None
    if payment is not None:
        existing_renewal = get_license_renewal_by_payment(db, payment.id)
        if existing_renewal is not None:
            logger.info("Skipping duplicate renewal for payment %s", payment.id)
            return license_obj
        amount = amount if amount is not None else payment.amount
        currency = currency or payment.currency

    new_type = normalize_renewal_plan(requested_plan)
    now = datetime.now(timezone.utc)
    new_expiry = calculate_renewal_expiry(new_type, old_expiry)
    duration_days = get_license_duration_days(new_type) or 0

    signed_license = create_signed_license(
        license_id=license_obj.id,
        school_id=license_obj.school_id,
        school_code=license_obj.school.code if license_obj.school else None,
        school=license_obj.school.name,
        machine=license_obj.machine_fingerprint,
        product_code="cbt",
        product_name="CBT Examination Software",
        license_type=new_type,
        plan_code=new_type,
        plan_name=license_obj.plan_name,
        duration_months=license_obj.duration_months,
        is_trial=license_obj.is_trial,
        issued_at=now,
        expiry=new_expiry,
        version=license_obj.version + 1,
    )

    update_license(
        db,
        license_obj,
        license_type=new_type,
        expiry_at=new_expiry,
        signed_license=signed_license.model_dump_json(),
        version=license_obj.version + 1,
    )
    license_obj.status = "active"
    license_obj.payment_status = "paid"
    license_obj.last_renewed_at = now
    license_obj.renewal_count += 1

    renewal = create_license_renewal(
        db,
        license_id=license_obj.id,
        payment_id=payment.id if payment is not None else payment_id,
        renewed_by=admin.id if admin is not None and hasattr(admin, "id") else None,
        plan=new_type,
        amount=amount if amount is not None else PLAN_PRICE_MAP.get(new_type, 0),
        currency=currency or settings.license_currency,
        duration_days=duration_days,
        old_expiry=old_expiry,
        new_expiry=new_expiry,
        notes=notes,
    )
    create_history_record(
        db,
        license_id=license_obj.id,
        version=license_obj.version,
        license_type=license_obj.license_type,
        issued_at=now,
        expiry_at=license_obj.expiry_at,
        signed_license=license_obj.signed_license,
    )
    _queue_renewal_outbox_event(db, license_obj=license_obj, renewal=renewal, payment=payment)

    record_audit_event(
        db,
        admin=admin,
        action="license_renewed",
        entity_type="license",
        entity_id=str(license_obj.id),
        description=f"Renewed license for {license_obj.school.name}",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )

    db.commit()
    db.refresh(license_obj)

    return license_obj


def renew_license_from_payment(
    db: Session,
    *,
    payment_id: UUID | None = None,
    payment_reference: str | None = None,
    admin=None,
    request=None,
):
    """
    Idempotently renew a license after a successful renewal payment.
    """

    if payment_id is None and not payment_reference:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="payment_id or payment_reference is required.")

    payment = get_payment_by_id(db, payment_id) if payment_id else get_payment_by_reference(db, payment_reference)
    if payment is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Payment not found.")

    if payment.status != "successful":
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="Payment is not successful.")

    existing_renewal = get_license_renewal_by_payment(db, payment.id)
    if existing_renewal is not None:
        license_obj = get_license(db, existing_renewal.license_id)
        return {
            "status": "already_processed",
            "license_id": str(license_obj.id),
            "renewal_id": str(existing_renewal.id),
        }

    invoice = get_invoice_by_id(db, payment.invoice_id)
    if invoice is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Invoice not found.")

    if invoice.status != "paid":
        mark_invoice_paid(db, invoice)
        invoice.paid_at = payment.paid_at or datetime.now(timezone.utc)

    payment.verified_at = payment.verified_at or datetime.now(timezone.utc)
    payment.paid_at = payment.paid_at or datetime.now(timezone.utc)
    persist_payment(db, payment)

    license_obj = renew_license(
        db,
        invoice.license_id,
        plan=payment.payment_type,
        payment_id=payment.id,
        amount=payment.amount,
        currency=payment.currency,
        notes=f"Automatic renewal from payment {payment.payment_reference}",
        admin=admin,
        request=request,
    )
    receipt = create_receipt_record(db, payment, admin=admin, request=request)
    return {
        "status": "renewed",
        "license_id": str(license_obj.id),
        "receipt_id": str(receipt.id),
    }


def renew_license_after_payment(
    db: Session,
    payment,
    *,
    admin=None,
    request=None,
):
    return renew_license_from_payment(
        db,
        payment_id=payment.id,
        admin=admin,
        request=request,
    )


def get_available_renewal_plans():

    return [
        {
            "plan": "trial",
            "days": 7,
            "price": 0,
        },
        {
            "plan": "monthly",
            "days": 30,
            "price": 10000,
        },
        {
            "plan": "quarterly",
            "days": 90,
            "price": 25000,
        },
        {
            "plan": "6_months",
            "days": 180,
            "price": 50000,
        },
        {
            "plan": "annual",
            "days": 365,
            "price": 90000,
        },
        {
            "plan": "24_months",
            "days": 730,
            "price": 170000,
        },
        {
            "plan": "lifetime",
            "days": None,
            "price": 350000,
        },
    ]

def calculate_plan_price(
    plan: str,
) -> float:

    plan = plan.strip().lower()

    return PLAN_PRICE_MAP.get(normalize_renewal_plan(plan), 0)

def get_license_history(
    db: Session,
    license_id: UUID,
):

    license_obj = get_license_by_id(
        db,
        license_id,
    )

    if license_obj is None:
        raise HTTPException(
            status_code=404,
            detail="License not found.",
        )

    return list_license_history(
        db,
        license_id=license_id,
    )

def get_renewal_statistics(
    db: Session,
):

    return renewal_statistics(db)
