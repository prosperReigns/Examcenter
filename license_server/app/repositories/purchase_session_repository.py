from datetime import datetime, timezone
from uuid import UUID

from sqlalchemy import desc, select
from sqlalchemy.orm import Session

from app.models.purchase_session import PurchaseSession


def create_purchase_session(db: Session, purchase_session: PurchaseSession) -> PurchaseSession:
    db.add(purchase_session)
    db.flush()
    return purchase_session


def save_purchase_session(db: Session, purchase_session: PurchaseSession) -> PurchaseSession:
    purchase_session.updated_at = datetime.now(timezone.utc)
    db.add(purchase_session)
    db.flush()
    return purchase_session


def get_purchase_session_by_id(db: Session, session_id: UUID) -> PurchaseSession | None:
    return db.get(PurchaseSession, session_id)


def get_purchase_session_by_reference(db: Session, payment_reference: str) -> PurchaseSession | None:
    statement = select(PurchaseSession).where(PurchaseSession.payment_reference == payment_reference)
    return db.scalar(statement)


def find_resumable_purchase(
    db: Session,
    *,
    fingerprint: str,
    product_code: str,
    plan_code: str,
) -> PurchaseSession | None:
    statement = (
        select(PurchaseSession)
        .where(
            PurchaseSession.fingerprint == fingerprint,
            PurchaseSession.product_code == product_code,
            PurchaseSession.plan_code == plan_code,
            PurchaseSession.completed.is_(False),
            PurchaseSession.status.notin_(("cancelled", "failed", "expired")),
            PurchaseSession.expires_at >= datetime.now(timezone.utc),
        )
        .order_by(desc(PurchaseSession.created_at))
    )
    return db.scalar(statement)


def list_purchase_session_records(
    db: Session,
    *,
    offset: int = 0,
    limit: int = 50,
) -> tuple[list[PurchaseSession], int]:
    statement = select(PurchaseSession).order_by(desc(PurchaseSession.created_at))
    total = len(db.scalars(select(PurchaseSession.id)).all())
    sessions = db.scalars(statement.offset(offset).limit(limit)).all()
    return sessions, total


def mark_purchase_completed(db: Session, purchase_session: PurchaseSession) -> PurchaseSession:
    purchase_session.status = "completed"
    purchase_session.completed = True
    purchase_session.completed_at = datetime.now(timezone.utc)
    return save_purchase_session(db, purchase_session)


def increment_retry_count(db: Session, purchase_session: PurchaseSession) -> PurchaseSession:
    purchase_session.retry_count += 1
    return save_purchase_session(db, purchase_session)


def list_recoverable_purchase_sessions(
    db: Session,
    *,
    limit: int = 50,
) -> list[PurchaseSession]:
    now = datetime.now(timezone.utc)
    statement = (
        select(PurchaseSession)
        .where(
            PurchaseSession.completed.is_(False),
            PurchaseSession.expires_at >= now,
            PurchaseSession.payment_reference.is_not(None),
            PurchaseSession.status.in_(
                (
                    "payment_verified",
                    "customer_created",
                    "school_created",
                    "license_created",
                    "invoice_created",
                    "payment_recorded",
                    "device_registered",
                    "activated",
                    "receipt_created",
                    "failed",
                )
            ),
        )
        .order_by(PurchaseSession.updated_at.asc())
        .limit(limit)
    )
    return db.scalars(statement).all()


def expire_stale_purchase_sessions(
    db: Session,
) -> int:
    now = datetime.now(timezone.utc)
    sessions = db.scalars(
        select(PurchaseSession).where(
            PurchaseSession.completed.is_(False),
            PurchaseSession.status.notin_(("cancelled", "expired")),
            PurchaseSession.expires_at < now,
        )
    ).all()
    for purchase_session in sessions:
        purchase_session.status = "expired"
        save_purchase_session(db, purchase_session)
    return len(sessions)
