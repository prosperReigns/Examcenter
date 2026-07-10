from datetime import timedelta
from fastapi import HTTPException, status
from sqlalchemy.orm import Session

from app.auth.security import create_access_token, verify_password, get_password_hash
from app.models.admin import Admin
from app.repositories.admin_repository import (
    create_admin as create_admin_record,
    get_admin_by_email,
    persist_admin,
)
from app.schemas.admin import AdminCreate

def authenticate_admin(db: Session, email: str, password: str) -> Admin | None:
    admin = get_admin_by_email(db, email.lower().strip())
    if admin is None:
        return None
    if not verify_password(password, admin.password_hash):
        return None
    if not admin.is_active:
        return None
    return admin


def issue_admin_token(admin: Admin, expires_delta: timedelta | None = None) -> str:
    return create_access_token(subject=str(admin.id), expires_delta=expires_delta, extra_claims={"role": admin.role})

def create_admin(
    db: Session,
    payload: AdminCreate,
) -> Admin:

    existing = get_admin_by_email(
        db,
        payload.email,
    )

    if existing is not None:

        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail="Email already exists.",
        )

    password_hash = get_password_hash(
        payload.password,
    )

    admin = create_admin_record(
        db,
        full_name=payload.full_name,
        email=payload.email,
        password_hash=password_hash,
        role=payload.role,
    )

    db.commit()

    db.refresh(admin)

    return admin

def change_password(
    db: Session,
    admin: Admin,
    current_password: str,
    new_password: str,
) -> Admin:

    if not verify_password(
        current_password,
        admin.password_hash,
    ):

        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Current password is incorrect.",
        )

    if current_password == new_password:

        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="New password must be different.",
        )

    admin.password_hash = get_password_hash(
        new_password,
    )

    persist_admin(
        db,
        admin,
    )

    db.commit()

    db.refresh(admin)

    return admin

def reset_password(
    db: Session,
    admin: Admin,
    new_password: str,
) -> Admin:

    admin.password_hash = get_password_hash(
        new_password,
    )

    persist_admin(
        db,
        admin,
    )

    db.commit()

    db.refresh(admin)

    return admin