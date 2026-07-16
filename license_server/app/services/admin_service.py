from __future__ import annotations

from uuid import UUID

from fastapi import HTTPException, status
from sqlalchemy import func, select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from app.auth.security import get_password_hash, verify_password
from app.models.admin import Admin
from app.repositories.admin_repository import (
    create_admin,
    delete_admin,
    get_active_admin_count,
    get_admin_by_email,
    get_admin_by_id,
    get_admin_count,
    list_admins,
    save_admin,
)
from app.schemas.admin import AdminCreate, AdminUpdate
from app.services.audit_service import record_audit_event


def _request_ip(request) -> str | None:
    return request.client.host if request and request.client else None


def _request_user_agent(request) -> str | None:
    return request.headers.get("user-agent") if request else None


def get_admin(db: Session, admin_id: UUID) -> Admin:
    admin = get_admin_by_id(db, admin_id)
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
    return list_admins(db, search=search, offset=offset, limit=page_size)


def get_admins(
    db: Session,
    *,
    search: str | None = None,
    page: int = 1,
    page_size: int = 20,
) -> tuple[list[Admin], int]:
    items = get_admin_list(db, search=search, page=page, page_size=page_size)
    total = get_admin_count(db)
    return items, total


def create_admin_record(
    db: Session,
    payload: AdminCreate,
    *,
    current_admin=None,
    admin=None,
    request=None,
) -> Admin:
    actor = current_admin if current_admin is not None else admin

    if get_admin_by_email(db, payload.email):
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail="Email already exists",
        )

    try:
        created = create_admin(
            db,
            full_name=payload.full_name.strip(),
            email=payload.email,
            password_hash=get_password_hash(payload.password),
            role=payload.role,
        )
        record_audit_event(
            db,
            admin=actor,
            action="admin_created",
            entity_type="admin",
            entity_id=str(created.id),
            description=f"Created admin {created.email}",
            ip_address=_request_ip(request),
            user_agent=_request_user_agent(request),
        )
        db.commit()
        db.refresh(created)
        return created
    except IntegrityError as exc:
        db.rollback()
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail="Unable to create admin",
        ) from exc


def update_admin_record(
    db: Session,
    admin_id: UUID,
    payload: AdminUpdate,
    *,
    current_admin=None,
    admin=None,
    request=None,
) -> Admin:
    actor = current_admin if current_admin is not None else admin
    target = get_admin(db, admin_id)
    data = payload.model_dump(exclude_unset=True)

    if "email" in data:
        existing = get_admin_by_email(db, data["email"])
        if existing and existing.id != target.id:
            raise HTTPException(
                status_code=status.HTTP_409_CONFLICT,
                detail="Email already exists",
            )

    for field, value in data.items():
        if field == "password":
            target.password_hash = get_password_hash(value)
        elif value is not None:
            setattr(target, field, value)

    save_admin(db, target)
    record_audit_event(
        db,
        admin=actor,
        action="admin_updated",
        entity_type="admin",
        entity_id=str(target.id),
        description=f"Updated admin {target.email}",
        ip_address=_request_ip(request),
        user_agent=_request_user_agent(request),
    )
    db.commit()
    db.refresh(target)
    return target


def activate_admin(
    db: Session,
    admin_id: UUID,
    *,
    admin=None,
    request=None,
) -> Admin:
    target = get_admin(db, admin_id)
    target.is_active = True
    save_admin(db, target)
    record_audit_event(
        db,
        admin=admin,
        action="admin_activated",
        entity_type="admin",
        entity_id=str(target.id),
        description=f"Activated admin {target.email}",
        ip_address=_request_ip(request),
        user_agent=_request_user_agent(request),
    )
    db.commit()
    db.refresh(target)
    return target


def deactivate_admin(
    db: Session,
    admin_id: UUID,
    *,
    admin=None,
    request=None,
) -> Admin:
    target = get_admin(db, admin_id)
    target.is_active = False
    save_admin(db, target)
    record_audit_event(
        db,
        admin=admin,
        action="admin_deactivated",
        entity_type="admin",
        entity_id=str(target.id),
        description=f"Deactivated admin {target.email}",
        ip_address=_request_ip(request),
        user_agent=_request_user_agent(request),
    )
    db.commit()
    db.refresh(target)
    return target


def delete_admin_record(
    db: Session,
    admin_id: UUID,
    *,
    admin=None,
    request=None,
) -> None:
    target = get_admin(db, admin_id)
    delete_admin(db, target)
    record_audit_event(
        db,
        admin=admin,
        action="admin_deleted",
        entity_type="admin",
        entity_id=str(target.id),
        description=f"Deleted admin {target.email}",
        ip_address=_request_ip(request),
        user_agent=_request_user_agent(request),
    )
    db.commit()


def change_admin_password(
    db: Session,
    admin_id: UUID,
    old_password: str,
    new_password: str,
    *,
    admin=None,
    request=None,
) -> dict[str, str]:
    target = get_admin(db, admin_id)

    if not verify_password(old_password, target.password_hash):
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Current password is incorrect.",
        )
    if old_password == new_password:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="New password must be different.",
        )

    target.password_hash = get_password_hash(new_password)
    save_admin(db, target)
    record_audit_event(
        db,
        admin=admin,
        action="admin_password_changed",
        entity_type="admin",
        entity_id=str(target.id),
        description=f"Changed password for admin {target.email}",
        ip_address=_request_ip(request),
        user_agent=_request_user_agent(request),
    )
    db.commit()
    return {"message": "Password changed successfully."}


def reset_admin_password(
    db: Session,
    admin_id: UUID,
    new_password: str,
    *,
    admin=None,
    request=None,
) -> dict[str, str]:
    target = get_admin(db, admin_id)
    target.password_hash = get_password_hash(new_password)
    save_admin(db, target)
    record_audit_event(
        db,
        admin=admin,
        action="admin_password_reset",
        entity_type="admin",
        entity_id=str(target.id),
        description=f"Reset password for admin {target.email}",
        ip_address=_request_ip(request),
        user_agent=_request_user_agent(request),
    )
    db.commit()
    return {"message": "Password reset successfully."}


def admin_statistics(db: Session) -> dict:
    total = get_admin_count(db)
    active = get_active_admin_count(db)
    by_role = db.execute(
        select(Admin.role, func.count()).select_from(Admin).group_by(Admin.role)
    ).all()

    return {
        "total": total,
        "active": active,
        "inactive": total - active,
        "by_role": {role: count for role, count in by_role},
    }
