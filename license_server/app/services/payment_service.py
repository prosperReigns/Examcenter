from __future__ import annotations
from uuid import UUID

import hmac
import json
import hashlib
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
from app.core.pricing import (
    get_plan,
    get_price,
    LICENSE_PRICES
)
from app.enums.purchase_status import PurchaseStatus
from app.models.payment import Payment
from app.models.webhook_event import WebhookEvent

from app.repositories.invoice_repository import (
    list_invoices
)
from app.repositories.payment_repository import (
    get_payment,
    get_payment_by_reference,
    get_payment_by_transaction_id,
    list_payments,
)
from app.repositories.webhook_event_repository import get_by_event_id, create

from app.schemas.payment import FlutterwaveWebhookPayload
from app.services.audit_service import record_audit_event

from app.repositories.purchase_session_repository import get_purchase_session_for_update
from app.repositories.purchase_session_repository import save_purchase_session
from app.services.purchase_state_machine import validate_transition
from app.tasks.purchase_tasks import orchestrate_purchase
settings = get_settings()
logger = logging.getLogger(__name__)



def normalize_payment_type(
    payment_type: str,
) -> str:

    normalized = payment_type.strip().lower()

    try:

        get_plan(normalized)

    except ValueError:

        raise HTTPException(

            status_code=status.HTTP_400_BAD_REQUEST,

            detail="Unsupported payment plan",

        )

    return normalized


def get_payment_amount(
    payment_type: str,
) -> int:

    return get_price(

        normalize_payment_type(
            payment_type
        )

    )


def _authorized_client() -> httpx.Client:
    headers = {
        "Authorization": f"Bearer {settings.flutterwave_secret_key}",
        "Content-Type": "application/json",
    }
    return httpx.Client(base_url=settings.flutterwave_base_url, headers=headers, timeout=30.0)

def payment_is_processable(
    payment: Payment,
) -> bool:

    return payment.status == "pending"

async def verify_flutterwave_transaction(
    transaction_id: str,
) -> dict[str, Any]:

    url = (
        f"/transactions/"
        f"{transaction_id}"
        f"/verify"
    )

    async with httpx.AsyncClient(
        base_url=settings.flutterwave_base_url,
        headers={
            "Authorization":
            f"Bearer {settings.flutterwave_secret_key}"
        },
        timeout=30,
    ) as client:

        response = await client.get(url)

    if response.status_code != 200:
        raise HTTPException(
            status_code=400,
            detail="Flutterwave verification failed",
        )

    result = response.json()

    if result.get("status") != "success":
        raise HTTPException(
            status_code=400,
            detail="Invalid Flutterwave transaction",
        )

    return result

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

    header_hash = (
        request.headers.get(
            settings.flutterwave_webhook_secret_header,
            ""
        )
        .strip()
    )

    expected_hash = (
        settings.flutterwave_hash
        .strip()
    )

    if (
        not header_hash
        or not hmac.compare_digest(
            header_hash,
            expected_hash,
        )
    ):
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid webhook signature")

    event = (payload.event or "").lower()
    if event != "charge.completed":
        return {"status": "ignored", "event": event}

    data = payload.data

    event_id = str(
        data.get("id")
        or data.get("tx_ref")
        or secrets.token_hex(16)
    ).strip()

    tx_ref = str(data.get("tx_ref") or data.get("payment_reference") or "").strip()

    if not tx_ref:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="Missing payment reference")

    existing = get_by_event_id(
            db,
            event_id,
        )
    
    if existing:

        return {

            "status": "duplicate",

            "event_id": event_id,

        }
    
    purchase_session = get_purchase_session_for_update(db, tx_ref)

    if purchase_session is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Purchase session not found")

    if purchase_session.completed:
        return {
            "status": "already processed",
            "session_id": str(purchase_session.id),
            "license_id": str(purchase_session.license_id) if purchase_session.license_id else None,
        }

    payment_status = str(data.get("status") or "").lower()

    logger.info(

        "flutterwave_webhook",

        extra={

            "tx_ref": tx_ref,

            "status": payment_status,

        },

    )
    
    if payment_status != "successful":

        validate_transition(
            purchase_session.status,
            PurchaseStatus.PAYMENT_FAILED,
        )

        purchase_session.status = (
            PurchaseStatus.PAYMENT_FAILED.value
        )

        save_purchase_session(
            db,
            purchase_session,
        )

        record_audit_event(
            db,
            action="payment_failed",
            entity_type="purchase_session",
            entity_id=str(
                purchase_session.id
            ),
            description="Flutterwave reported unsuccessful payment.",
        )

        db.commit()

        return {
            "status":"failed",
            "payment_status":payment_status,
        }

    transaction_id = (
        str(data.get("id") or "")
        .strip()
        or None
    )

    if not transaction_id:
        raise HTTPException(
            status_code=400,
            detail="Missing Flutterwave transaction ID",
        )

    flutterwave_result = await verify_flutterwave_transaction(
        transaction_id
    )

    verified_data = (
        flutterwave_result
        .get("data", {})
    )

    try:
        paid_amount = Decimal(
            str(
                verified_data.get(
                    "amount",
                    "0"
                )
            )
        )
    except (InvalidOperation, ValueError) as exc:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="Invalid payment amount") from exc

    paid_currency = (
        str(
            verified_data.get(
                "currency"
            )
            or ""
        )
        .upper()
    )

    if paid_amount != Decimal(purchase_session.amount) or paid_currency != purchase_session.currency.upper():
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="Payment verification failed")

    gateway_reference = str(data.get("flw_ref") or data.get("reference") or "").strip() or None

    if purchase_session.status == PurchaseStatus.PAYMENT_VERIFIED.value:
        return {
            "status": "already_verified",
            "session_id": str(purchase_session.id),
        }

    validate_transition(
        purchase_session.status,
        PurchaseStatus.PAYMENT_VERIFIED,
    )

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

    payload_hash = hashlib.sha256(
    
            json.dumps(
                data,
                sort_keys=True,
            ).encode()
    
        ).hexdigest()
    
    create(
    
            db,
    
            WebhookEvent(
    
                provider="flutterwave",
    
                event_id=event_id,
    
                tx_ref=tx_ref,
    
                payload_hash=payload_hash,
    
                payload=json.dumps(data),
    
            ),
    
        )

    record_audit_event(
        db,
        action="flutterwave_webhook_received",
        entity_type="webhook_event",
        entity_id=event_id,
        description="Flutterwave payment webhook stored successfully.",
    )
    
    db.commit()

    if purchase_session.status != PurchaseStatus.PAYMENT_VERIFIED.value:
        return {
            "status": "already processed",
            "session_id": str(purchase_session.id),
        }


    task_id = queue_purchase_orchestration(
        session_id=purchase_session.id,
        payment_reference=tx_ref,
    )

    return {
        "status": "queued",
        "message": "Payment verified. Purchase processing started.",
        "session_id": str(
            purchase_session.id
        ),
        "task_id": task_id,
    }

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

def failed_payment_count(db: Session):
    statement = (
        select(func.count())
        .select_from(Payment)
        .where(
            Payment.status == "failed"
        )
    )

    return db.scalar(statement) or 0

def revenue_today(db: Session):
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

def revenue_this_month(db: Session):

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

def revenue_this_year(db: Session):

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

def outstanding_invoice_total(db: Session):

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

def get_payment_dashboard_stats(db: Session):

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

def revenue_by_payment_method(db: Session):

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

def monthly_revenue(db: Session, year: int):

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

def top_paying_schools(db: Session, limit: int = 10):

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
