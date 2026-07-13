from uuid import UUID

from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.orm import Session

from app.auth.dependencies import require_roles
from app.database.session import get_db

from app.core.roles import Roles
from app.services.idempotency_service import (
    get_idempotency_key,
    list_idempotency_keys,
    delete_expired_idempotency_keys,
)

router = APIRouter(
    prefix="/api/idempotency",
    tags=["Idempotency"],
)

@router.get("")
def list_idempotency_endpoint(
    page: int = 1,
    page_size: int = 20,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):
    keys, total = list_idempotency_keys(
        db,
        page=page,
        page_size=page_size,
    )

    return {
        "items": keys,
        "total": total,
        "page": page,
        "page_size": page_size,
    }

@router.get("/{key}")
def get_idempotency_key_endpoint(
    key: str,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):

    record = get_idempotency_key(
        db,
        key,
    )

    if record is None:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Idempotency key not found",
        )

    return record

@router.delete("/expired")
def cleanup_expired_keys_endpoint(
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):

    deleted = delete_expired_idempotency_keys(
        db,
    )

    return {
        "deleted": deleted,
        "message": "Expired idempotency keys removed successfully.",
    }