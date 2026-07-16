from datetime import datetime, timedelta, timezone
import secrets

from fastapi import HTTPException
from sqlalchemy.orm import Session

from app.models.activation_token import ActivationToken
from app.repositories import activation_token_repository


def generate_token() -> str:
    return secrets.token_urlsafe(48)


def _as_aware(value: datetime) -> datetime:
    if value.tzinfo is None:
        return value.replace(tzinfo=timezone.utc)
    return value


def create_activation_token(
    db: Session,
    *,
    purchase_session,
    license,
    fingerprint: str,
    ttl_minutes: int = 15,
) -> ActivationToken:
    existing = activation_token_repository.get_active_for_purchase(
        db,
        purchase_session.id,
    )
    if existing is not None:
        return existing

    activation_token = ActivationToken(
        token=generate_token(),
        purchase_session_id=purchase_session.id,
        license_id=license.id,
        machine_fingerprint=fingerprint.strip(),
        expires_at=datetime.now(timezone.utc) + timedelta(minutes=ttl_minutes),
    )
    return activation_token_repository.create(db, activation_token)


def validate_token(activation_token: ActivationToken | None) -> None:
    if activation_token is None:
        raise HTTPException(404, "Activation token not found.")
    if activation_token.revoked_at:
        raise HTTPException(403, "Activation token revoked.")
    if activation_token.used_at:
        raise HTTPException(403, "Activation token already used.")
    if _as_aware(activation_token.expires_at) < datetime.now(timezone.utc):
        raise HTTPException(403, "Activation token expired.")


def validate_machine(activation_token: ActivationToken, fingerprint: str) -> None:
    if activation_token.machine_fingerprint != fingerprint.strip():
        raise HTTPException(403, "Machine fingerprint mismatch.")


def consume_token(db: Session, activation_token: ActivationToken) -> ActivationToken:
    return activation_token_repository.mark_used(db, activation_token)
