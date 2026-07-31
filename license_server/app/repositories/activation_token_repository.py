from datetime import datetime, timedelta, timezone
from uuid import UUID
import secrets

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

"""
IMPORTANT

This query intentionally acquires a row lock.

It prevents two concurrent download requests
from consuming the same activation token.

Do not remove with_for_update().

This guarantees one-time downloads.
"""
def get_valid_download_token(
    db: Session,
    token: str,
) -> ActivationToken | None:
    """
    Returns a token only if it is still valid
    for license download.
    """

    now = datetime.now(timezone.utc)

    statement = (
        select(ActivationToken)
        .where(
            ActivationToken.token == token,
            ActivationToken.used_at.is_(None),
            ActivationToken.revoked_at.is_(None),
            ActivationToken.expires_at >= now,
        )
        .with_for_update()
    )

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

def get_latest_for_purchase(
    db: Session,
    purchase_session_id: UUID,
) -> ActivationToken | None:

    statement = (
        select(ActivationToken)
        .where(
            ActivationToken.purchase_session_id == purchase_session_id,
        )
        .order_by(
            ActivationToken.created_at.desc()
        )
    )

    return db.scalar(statement)

def mark_used(db: Session, activation_token: ActivationToken) -> ActivationToken:
    activation_token.used_at = datetime.now(timezone.utc)
    db.add(activation_token)
    db.flush()
    return activation_token


def consume_download_token(
    db: Session,
    activation_token: ActivationToken,
) -> ActivationToken:
    """
    Marks a download token as consumed.
    """

    activation_token.used_at = datetime.now(
        timezone.utc
    )

    db.add(
        activation_token
    )

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

def is_valid(
    db: Session,
    token: ActivationToken,
) -> bool:

    now = datetime.now(timezone.utc)

    if token.used_at is not None:
        return False

    if token.revoked_at is not None:
        return False

    if token.expires_at < now:
        return False

    return True

def generate_download_nonce(
    db: Session,
    token: ActivationToken,
) -> ActivationToken:

    token.download_nonce = secrets.token_urlsafe(48)

    token.download_nonce_expires_at = (

        datetime.now(timezone.utc)

        + timedelta(minutes=5)

    )

    token.download_nonce_used_at = None

    db.add(token)

    db.flush()

    return token

def validate_download_nonce(

    token: ActivationToken,

    nonce: str,

) -> bool:

    now = datetime.now(timezone.utc)

    if token.download_nonce != nonce:

        return False

    if token.download_nonce_used_at:

        return False

    if token.download_nonce_expires_at is None:

        return False

    if token.download_nonce_expires_at < now:

        return False

    return True

def consume_download_nonce(

    db: Session,

    token: ActivationToken,

):

    token.download_nonce_used_at = (

        datetime.now(timezone.utc)

    )

    db.add(token)

    db.flush()

def create_download_token(
    db: Session,
    *,
    purchase_session,
) -> ActivationToken:
    """
    Creates a short-lived one-time download token
    for a completed purchase.

    Existing active download tokens for the same
    purchase are revoked first.
    """

    #
    # A completed purchase must already have
    # a generated license.
    #

    if purchase_session.license_id is None:

        raise ValueError(
            "Purchase session has no license."
        )

    #
    # Revoke any previously-issued active token.
    #

    existing = get_active_for_purchase(
        db,
        purchase_session.id,
    )

    if existing:

        revoke(
            db,
            existing,
        )

    token = ActivationToken(

        token=secrets.token_urlsafe(48),

        purchase_session_id=purchase_session.id,

        license_id=purchase_session.license_id,

        machine_fingerprint=purchase_session.fingerprint,

        expires_at=datetime.now(
            timezone.utc,
        ) + timedelta(
            minutes=5,
        ),

    )

    create(
        db,
        token,
    )

    db.commit()

    db.refresh(token)

    return token