from datetime import datetime, timedelta, timezone
from uuid import UUID

from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.models.activation_token import ActivationToken


def create(db: Session, activation_token: ActivationToken) -> ActivationToken:
    db.add(activation_token)
    db.flush()
    return activation_token


def get_by_token(db: Session, token: str) -> ActivationToken | None:
    statement = select(ActivationToken).where(ActivationToken.token == token)
    return db.scalar(statement)


def get_by_id(db: Session, token_id: UUID) -> ActivationToken | None:
    return db.get(ActivationToken, token_id)


def get_active_for_purchase(db: Session, purchase_session_id: UUID) -> ActivationToken | None:
    now = datetime.now(timezone.utc)
    statement = (
        select(ActivationToken)
        .where(
            ActivationToken.purchase_session_id == purchase_session_id,
            ActivationToken.used_at.is_(None),
            ActivationToken.revoked_at.is_(None),
            ActivationToken.expires_at >= now,
        )
        .order_by(ActivationToken.created_at.desc())
    )
    return db.scalar(statement)


def mark_used(db: Session, activation_token: ActivationToken) -> ActivationToken:
    activation_token.used_at = datetime.now(timezone.utc)
    db.add(activation_token)
    db.flush()
    return activation_token


def revoke(db: Session, activation_token: ActivationToken) -> ActivationToken:
    activation_token.revoked_at = datetime.now(timezone.utc)
    db.add(activation_token)
    db.flush()
    return activation_token


def delete_expired(db: Session) -> int:
    return (
        db.query(ActivationToken)
        .filter(ActivationToken.expires_at < datetime.now(timezone.utc))
        .delete(synchronize_session=False)
    )


def get_stale_tokens(db: Session, older_than_minutes: int = 60) -> list[ActivationToken]:
    cutoff = datetime.now(timezone.utc) - timedelta(minutes=older_than_minutes)
    statement = select(ActivationToken).where(
        ActivationToken.created_at < cutoff,
        ActivationToken.used_at.is_(None),
        ActivationToken.revoked_at.is_(None),
    )
    return list(db.scalars(statement).all())


def delete_used_before(db: Session, cutoff: datetime) -> int:
    return (
        db.query(ActivationToken)
        .filter(ActivationToken.used_at.is_not(None), ActivationToken.used_at < cutoff)
        .delete(synchronize_session=False)
    )


def count_active(db: Session) -> int:
    now = datetime.now(timezone.utc)
    return db.scalar(
        select(func.count()).select_from(ActivationToken).where(
            ActivationToken.used_at.is_(None),
            ActivationToken.revoked_at.is_(None),
            ActivationToken.expires_at >= now,
        )
    ) or 0


def count_expired(db: Session) -> int:
    return db.scalar(
        select(func.count()).select_from(ActivationToken).where(
            ActivationToken.expires_at < datetime.now(timezone.utc)
        )
    ) or 0


def count_revoked(db: Session) -> int:
    return db.scalar(
        select(func.count()).select_from(ActivationToken).where(
            ActivationToken.revoked_at.is_not(None)
        )
    ) or 0


def count_used(db: Session) -> int:
    return db.scalar(
        select(func.count()).select_from(ActivationToken).where(
            ActivationToken.used_at.is_not(None)
        )
    ) or 0
