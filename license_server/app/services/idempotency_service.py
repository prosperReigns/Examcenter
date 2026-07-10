from __future__ import annotations

import hashlib
import json
from datetime import datetime, timedelta, timezone

from fastapi import HTTPException, status
from sqlalchemy.orm import Session

from app.models.idempotency_key import IdempotencyKey

from app.repositories.idempotency_repository import (
    delete_expired_keys,
    get_valid_key,
    persist_key,
    get_key,
    create_key,
    list_keys
)

DEFAULT_EXPIRY_HOURS = 24
PROCESSING = "processing"
COMPLETED = "completed"
FAILED = "failed"

def build_request_hash(
    *,
    method: str,
    path: str,
    body: dict | list | None,
) -> str:

    payload = json.dumps(
        body or {},
        sort_keys=True,
        separators=(",", ":"),
    )

    raw = f"{method.upper()}:{path}:{payload}"

    return hashlib.sha256(
        raw.encode()
    ).hexdigest()


def get_cached_response(
    db: Session,
    *,
    key: str,
):

    return get_valid_key(
        db,
        key,
    )


def store_response(
    db: Session,
    *,
    key: str,
    method: str,
    path: str,
    request_hash: str,
    response_status: int,
    response_body: dict,
    expires_in_hours: int = DEFAULT_EXPIRY_HOURS,
):

    record = IdempotencyKey(

        key=key,

        request_method=method,

        request_path=path,

        request_hash=request_hash,

        response_status=response_status,

        response_body=json.dumps(
            response_body,
            default=str,
        ),

        expires_at=datetime.now(
            timezone.utc,
        ) + timedelta(
            hours=expires_in_hours,
        ),
    )

    persist_key(
        db,
        record,
    )

    db.commit()

    db.refresh(record)

    return record


def execute_once(
    db: Session,
    *,
    key: str,
    method: str,
    path: str,
    body: dict | list | None,
    callback,
):

    request_hash = build_request_hash(
        method=method,
        path=path,
        body=body,
    )

    cached = get_cached_response(
        db,
        key=key,
    )

    if cached:

        if cached.request_hash != request_hash:

            raise HTTPException(
                status_code=status.HTTP_409_CONFLICT,
                detail=(
                    "Idempotency key already used "
                    "for a different request."
                ),
            )

        return {
            "cached": True,
            "status": cached.response_status,
            "body": json.loads(
                cached.response_body,
            ),
        }

    response = callback()

    status_code = (
        response.get("status_code", 200)
        if isinstance(response, dict)
        else 200
    )

    store_response(
        db,
        key=key,
        method=method,
        path=path,
        request_hash=request_hash,
        response_status=status_code,
        response_body=response,
    )

    return {
        "cached": False,
        "status": status_code,
        "body": response,
    }


def cleanup_expired_keys(
    db: Session,
):

    return delete_expired_keys(
        db,
    )

def begin_request(
    db: Session,
    *,
    key: str,
    method: str,
    path: str,
    body: dict | list | None,
):
    request_hash = build_request_hash(
        method=method,
        path=path,
        body=body,
    )

    record = get_valid_key(
        db,
        key,
    )

    if record:

        if record.request_hash != request_hash:

            raise HTTPException(
                status_code=409,
                detail="Idempotency key already used.",
            )

        return record

    record = IdempotencyKey(

        key=key,

        request_method=method,

        request_path=path,

        request_hash=request_hash,

        state=PROCESSING,

        response_status=0,

        response_body="",

        expires_at=datetime.now(
            timezone.utc,
        ) + timedelta(hours=24),

    )

    create_key(
        db,
        record,
    )

    db.commit()

    db.refresh(record)

    return record

def complete_request(
    db: Session,
    *,
    record: IdempotencyKey,
    response_status: int,
    response_body: dict,
):
    record.state = COMPLETED

    record.response_status = response_status

    record.response_body = json.dumps(
        response_body,
        default=str,
    )

    persist_key(
        db,
        record,
    )

    db.commit()

    db.refresh(record)

    return record

def fail_request(
    db: Session,
    *,
    record: IdempotencyKey,
    response_status: int,
    error: str,
):
    record.state = FAILED

    record.response_status = response_status

    record.response_body = json.dumps(

        {

            "error": error,

        }

    )

    persist_key(
        db,
        record,
    )

    db.commit()

    db.refresh(record)

    return record

def get_idempotency_key(
    db,
    key: str,
):
    return get_key(
        db,
        key,
    )


def delete_expired_idempotency_keys(
    db,
):
    return delete_expired_keys(
        db,
    )

def list_idempotency_keys(
    db,
    page: int = 1,
    page_size: int = 20,
):
    return list_keys(
        db,
        offset=(page - 1) * page_size,
        limit=page_size,
    )