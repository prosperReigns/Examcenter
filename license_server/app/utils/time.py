from __future__ import annotations

from datetime import datetime, timezone


def utcnow() -> datetime:
    return datetime.now(timezone.utc)


def as_aware(value: datetime) -> datetime:
    if value.tzinfo is None:
        return value.replace(tzinfo=timezone.utc)
    return value


def is_expired(value: datetime, *, now: datetime | None = None) -> bool:
    current_time = now or utcnow()
    return as_aware(value) < current_time


def is_token_deliverable(token, *, now: datetime | None = None) -> bool:
    if token is None or token.used_at is not None or token.revoked_at is not None:
        return False
    return as_aware(token.expires_at) >= (now or utcnow())