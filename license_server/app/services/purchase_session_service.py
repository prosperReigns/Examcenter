from datetime import datetime, timedelta, timezone
from uuid import UUID
import secrets

from fastapi import HTTPException, status
from sqlalchemy.orm import Session
from app.core.config import get_settings
from app.core.pricing import (
    get_plan,
)

from app.enums.purchase_status import PurchaseStatus
from app.models.purchase_session import PurchaseSession
from app.repositories import activation_token_repository
from app.repositories.purchase_session_repository import (
    create_purchase_session,
    expire_stale_purchase_sessions,
    find_resumable_purchase,
    get_purchase_session_by_id,
    get_purchase_session_by_reference,
    list_recoverable_purchase_sessions,
    list_purchase_session_records,
    save_purchase_session,
)
from app.schemas.purchase_session import CompletePaymentRequest, PurchaseSessionCreate
from app.services.audit_service import record_audit_event
from app.services.purchase_orchestration_service import complete_purchase, resume_purchase as resume_orchestration


def _utcnow() -> datetime:
    return datetime.now(timezone.utc)


def _is_expired(purchase_session: PurchaseSession) -> bool:
    expires_at = purchase_session.expires_at
    if expires_at.tzinfo is None:
        expires_at = expires_at.replace(tzinfo=timezone.utc)
    return expires_at < _utcnow()


def _is_token_deliverable(token) -> bool:
    if token is None or token.used_at is not None or token.revoked_at is not None:
        return False
    expires_at = token.expires_at
    if expires_at.tzinfo is None:
        expires_at = expires_at.replace(tzinfo=timezone.utc)
    return expires_at >= _utcnow()


def _read_with_token(db: Session, purchase_session: PurchaseSession) -> PurchaseSession:
    token = (
        activation_token_repository.get_by_id(db, purchase_session.activation_token_id)
        if purchase_session.activation_token_id
        else None
    )
    setattr(purchase_session, "activation_token", token.token if _is_token_deliverable(token) else None)
    return purchase_session


def start_purchase(db: Session, payload: PurchaseSessionCreate) -> PurchaseSession:
    existing = None
    if payload.payment_reference:
        existing = get_purchase_session_by_reference(db, payload.payment_reference)
    if existing is None:
        existing = find_resumable_purchase(
            db,
            fingerprint=payload.fingerprint,
            product_code=payload.product_code,
            plan_code=payload.plan_code,
        )
    if existing is not None:
        if existing.status == PurchaseStatus.CANCELLED.value:
            raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="Purchase session has been cancelled.")
        if _is_expired(existing):
            existing.status = PurchaseStatus.EXPIRED.value
            save_purchase_session(db, existing)
            db.commit()
            raise HTTPException(status_code=status.HTTP_410_GONE, detail="Purchase session has expired.")
        return _read_with_token(db, existing)

    checkout_token = secrets.token_urlsafe(32)
    poll_token = secrets.token_urlsafe(32)

    plan = get_plan(
                payload.plan_code
            )
    purchase_session = PurchaseSession(
        fingerprint=payload.fingerprint.strip(),
        product_code=payload.product_code.strip(),
        version=payload.version.strip(),
        plan_code=payload.plan_code.strip().lower(),
        duration_months=plan.duration_months,
        amount=plan.price,
        currency=plan.currency,
        customer_name=payload.customer_name.strip(),
        customer_email=str(payload.customer_email).lower().strip(),
        customer_phone=payload.customer_phone.strip() if payload.customer_phone else None,
        school_name=payload.school_name.strip(),
        gateway=payload.gateway.strip().lower() if payload.gateway else None,
        payment_reference=payload.payment_reference.strip() if payload.payment_reference else None,
        status=PurchaseStatus.PENDING.value,
        completed=False,
        retry_count=0,
        expires_at=_utcnow() + timedelta(hours=24),
        checkout_token=checkout_token,
        poll_token=secrets.token_urlsafe(32),
    )
    create_purchase_session(db, purchase_session)
    record_audit_event(
        db,
        action="purchase_session_started",
        entity_type="purchase_session",
        entity_id=str(purchase_session.id),
        description=("Self-service purchase session started.",
        f"Started {plan.name} purchase "
        f"({plan.price} {plan.currency})")
    )
    db.commit()
    db.refresh(purchase_session)
    # poll_token is now available on the returned model
    return purchase_session


def get_purchase_session(db: Session, session_id: UUID | str) -> PurchaseSession:
    purchase_session = get_purchase_session_by_id(db, UUID(str(session_id)))
    if purchase_session is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Purchase session not found.")
    return _read_with_token(db, purchase_session)


def get_purchase_status(db: Session, session_id: UUID | str) -> dict:
    purchase_session = get_purchase_session(db, session_id)
    return build_purchase_status(db, purchase_session)


def get_purchase_status_by_reference(db: Session, payment_reference: str) -> dict:
    purchase_session = get_purchase_session_by_reference(db, payment_reference)
    if purchase_session is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Purchase session not found.")
    return build_purchase_status(db, purchase_session)


def build_purchase_status(db: Session, purchase_session: PurchaseSession) -> dict:
    purchase_session = _read_with_token(db, purchase_session)
    return {
        "session": purchase_session,
        "license_ready": purchase_session.completed,
        "activation_token": getattr(purchase_session, "activation_token", None),
    }


def list_purchase_sessions(db: Session, *, page: int = 1, page_size: int = 50):
    return list_purchase_session_records(
        db,
        offset=(page - 1) * page_size,
        limit=page_size,
    )


def resume_purchase(db: Session, session_id: UUID | str) -> dict:
    purchase_session = get_purchase_session(db, session_id)
    if purchase_session.status == PurchaseStatus.CANCELLED.value:
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="Cancelled purchases cannot be resumed.")
    return resume_orchestration(db, purchase_session)


def cancel_purchase(db: Session, session_id: UUID | str) -> PurchaseSession:
    purchase_session = get_purchase_session(db, session_id)
    if purchase_session.completed:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="Completed purchases cannot be cancelled.")
    purchase_session.status = PurchaseStatus.CANCELLED.value
    save_purchase_session(db, purchase_session)
    record_audit_event(
        db,
        action="purchase_session_cancelled",
        entity_type="purchase_session",
        entity_id=str(purchase_session.id),
        description="Self-service purchase session cancelled.",
    )
    db.commit()
    db.refresh(purchase_session)
    return purchase_session


def complete_purchase_session(
    db: Session,
    session_id: UUID | str | None = None,
    payload: CompletePaymentRequest | None = None,
) -> dict:
    purchase_session = None
    if payload and payload.payment_reference:
        purchase_session = get_purchase_session_by_reference(db, payload.payment_reference)
    if purchase_session is None and payload and payload.session_id:
        purchase_session = get_purchase_session_by_id(db, payload.session_id)
    if purchase_session is None and session_id is not None:
        purchase_session = get_purchase_session_by_id(db, UUID(str(session_id)))
    if purchase_session is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Purchase session not found.")

    if payload:
        if payload.payment_reference:
            purchase_session.payment_reference = payload.payment_reference
        if payload.gateway_reference:
            purchase_session.gateway_reference = payload.gateway_reference
        if payload.gateway_transaction_id:
            purchase_session.gateway_transaction_id = payload.gateway_transaction_id
        if payload.gateway_response:
            purchase_session.gateway_response = payload.gateway_response
        save_purchase_session(db, purchase_session)

    return complete_purchase(db, purchase_session)


def recover_pending_purchases(db: Session, *, limit: int = 50) -> dict[str, int]:
    """
    Resume paid purchase sessions that were interrupted by browser close,
    webhook retry, or worker failure.
    """

    expired = expire_stale_purchase_sessions(db)
    if expired:
        db.commit()
    recovered = 0
    failed = 0
    for purchase_session in list_recoverable_purchase_sessions(db, limit=limit):
        try:
            complete_purchase(db, purchase_session)
            recovered += 1
        except Exception:
            failed += 1
    return {
        "expired": expired,
        "recovered": recovered,
        "failed": failed,
    }
