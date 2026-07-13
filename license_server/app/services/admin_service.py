from __future__ import annotations

from fastapi import HTTPException, status
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session
from app.auth.security import get_password_hash
from app.models.admin import Admin
from app.repositories.admin_repository import (
    create_admin,
    delete_admin,
    get_admin_by_email,
    get_admin_by_id,
    list_admins,
    save_admin,
)
from app.schemas.admin import (
    AdminCreate,
    AdminUpdate,
)
from app.services.audit_service import (
    record_audit_event,
)

def get_admin(
    db: Session,
    admin_id: int,
):
    admin = get_admin_by_id(
        db,
        admin_id,
    )

    if admin is None:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Admin not found",
        )
    return admin

def get_admin_list(
    db: Session,
    *,
    search: str | None = None,
    page: int = 1,
    page_size: int = 20,
):
    offset = (page - 1) * page_size
    return list_admins(
        db,
        search=search,
        offset=offset,
        limit=page_size,
    )

def create_admin_record(
    db: Session,
    payload: AdminCreate,
    *,
    current_admin=None,
    request=None,

):
    if get_admin_by_email(
        db,
        payload.email,
    ):

        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail="Email already exists",
        )

    try:
        admin = create_admin(
            db,
            full_name=payload.full_name.strip(),
            email=payload.email,
            password_hash=get_password_hash(
                payload.password,
            ),
            role=payload.role,
        )

        record_audit_event(
            db,
            admin=current_admin,
            action="admin_created",
            entity_type="admin",
            entity_id=str(admin.id),
            description=f"Created admin {admin.email}",
            ip_address=request.client.host if request and request.client else None,
            user_agent=request.headers.get("user-agent") if request else None,
        )

        db.commit()
        db.refresh(admin)
        return admin

    except IntegrityError:
        db.rollback()
        raise HTTPException(
            status_code=409,
            detail="Unable to create admin",
        )
    
def update_admin_record(
    db: Session,
    admin_id: int,
    payload: AdminUpdate,
    *,
    current_admin=None,
    request=None,
):
    admin = get_admin(
        db,
        admin_id,
    )

    data = payload.model_dump(
        exclude_unset=True,
    )

    if "email" in data:
        existing = get_admin_by_email(
            db,
            data["email"],
        )

        if existing and existing.id != admin.id:
            raise HTTPException(
                status_code=409,
                detail="Email already exists",
            )

    for field, value in data.items():
        if field == "password":
            admin.password_hash = get_password_hash(value)
        else:
            setattr(
                admin,
                field,
                value,
            )
    save_admin(
        db,
        admin,
    )

    record_audit_event(
        db,
        admin=current_admin,
        action="admin_updated",
        entity_type="admin",
        entity_id=str(admin.id),
        description=f"Updated admin {admin.email}",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )

    db.commit()
    db.refresh(admin)

    return admin

def activate_admin(
    db: Session,
    admin_id: int,
):
    admin = get_admin(
        db,
        admin_id,
    )
    admin.is_active = True

    save_admin(
        db,
        admin,
    )
    db.commit()
    return admin

def deactivate_admin(
    db: Session,
    admin_id: int,
):
    admin = get_admin(
        db,
        admin_id,
    )

    admin.is_active = False
    save_admin(
        db,
        admin,
    )
    db.commit()
    return admin

def delete_admin_record(
    db: Session,
    admin_id: int,
):

    admin = get_admin(
        db,
        admin_id,
    )

    delete_admin(
        db,
        admin,
    )

    db.commit()

def change_admin_password():
    pass
def  reset_admin_password():
    pass
def admin_statistics():
    pass
def get_admins():
    pass