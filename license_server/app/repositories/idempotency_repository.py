from datetime import datetime, timezone

from sqlalchemy import delete, select, func
from sqlalchemy.orm import Session

from app.models.idempotency_key import IdempotencyKey


def create_key(
    db: Session,
    record: IdempotencyKey,
) -> IdempotencyKey:

    db.add(record)

    db.flush()

    return record


def persist_key(
    db: Session,
    record: IdempotencyKey,
) -> IdempotencyKey:

    db.add(record)

    db.flush()

    return record


def get_key(
    db: Session,
    key: str,
) -> IdempotencyKey | None:

    statement = (
        select(IdempotencyKey)
        .where(IdempotencyKey.key == key)
    )

    return db.scalar(statement)


def get_valid_key(
    db: Session,
    key: str,
) -> IdempotencyKey | None:

    statement = (
        select(IdempotencyKey)
        .where(
            IdempotencyKey.key == key,
            IdempotencyKey.expires_at > datetime.now(timezone.utc),
        )
    )

    return db.scalar(statement)


def exists(
    db: Session,
    key: str,
) -> bool:

    return get_valid_key(
        db,
        key,
    ) is not None


def delete_expired_keys(
    db: Session,
) -> int:

    result = db.execute(

        delete(IdempotencyKey)

        .where(
            IdempotencyKey.expires_at
            < datetime.now(timezone.utc)
        )

    )

    db.flush()

    return result.rowcount or 0

def list_keys(
    db: Session,
    offset: int = 0,
    limit: int = 20,
):

    statement = (
        select(IdempotencyKey)
        .order_by(IdempotencyKey.created_at.desc())
        .offset(offset)
        .limit(limit)
    )

    items = db.scalars(statement).all()

    total = db.scalar(
        select(func.count()).select_from(IdempotencyKey)
    ) or 0

    return items, total