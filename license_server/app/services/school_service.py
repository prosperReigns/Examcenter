from uuid import UUID

from fastapi import HTTPException, status
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from app.repositories.customer_repository import get_customer_by_id
from app.repositories.school_repository import create_school, get_school_by_id, list_schools, soft_delete_school, persist_school
from app.schemas.school import SchoolCreate, SchoolUpdate
from app.services.audit_service import record_audit_event


def get_schools(db: Session, *, search: str | None, page: int, page_size: int):
    offset = (page - 1) * page_size
    return list_schools(db, search=search, offset=offset, limit=page_size)


def get_school(db: Session, school_id: UUID):
    school = get_school_by_id(db, school_id)
    if school is None or school.deleted_at is not None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="School not found")
    return school


def create_school_record(db: Session, payload: SchoolCreate, *, admin=None, request=None):
    if get_customer_by_id(db, payload.customer_id) is None:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="Customer does not exist")

    try:
        school = create_school(
            db,
            customer_id=payload.customer_id,
            name=payload.name,
            code=payload.code,
            address=payload.address,
            contact_email=payload.contact_email,
            contact_phone=payload.contact_phone,
            is_active=payload.is_active,
        )
        db.commit()
        db.refresh(school)
        record_audit_event(
            db,
            admin=admin,
            action="school_created",
            entity_type="school",
            entity_id=str(school.id),
            description=f"Created school {school.name}",
            ip_address=request.client.host if request and request.client else None,
            user_agent=request.headers.get("user-agent") if request else None,
        )
        db.commit()
        return school
    except IntegrityError as exc:
        db.rollback()
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="School already exists or code is duplicated") from exc


def update_school_record(db: Session, school_id: UUID, payload: SchoolUpdate, *, admin=None, request=None):
    school = get_school(db, school_id)
    if payload.customer_id is not None and get_customer_by_id(db, payload.customer_id) is None:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="Customer does not exist")

    data = payload.model_dump(exclude_unset=True)
    if "code" in data and data["code"] is not None:
        data["code"] = data["code"].strip()
    if "name" in data and data["name"] is not None:
        data["name"] = data["name"].strip()
    if "address" in data and data["address"] is not None:
        data["address"] = data["address"].strip()
    if "contact_email" in data and data["contact_email"] is not None:
        data["contact_email"] = data["contact_email"].lower().strip()
    if "contact_phone" in data and data["contact_phone"] is not None:
        data["contact_phone"] = data["contact_phone"].strip()

    for field, value in data.items():
        setattr(school, field, value)

    try:
        db.add(school)
        db.commit()
        db.refresh(school)
        record_audit_event(
            db,
            admin=admin,
            action="school_updated",
            entity_type="school",
            entity_id=str(school.id),
            description=f"Updated school {school.name}",
            ip_address=request.client.host if request and request.client else None,
            user_agent=request.headers.get("user-agent") if request else None,
        )
        db.commit()
        return school
    except IntegrityError as exc:
        db.rollback()
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="School update violates unique constraints") from exc


def delete_school_record(db: Session, school_id: UUID, *, admin=None, request=None) -> None:
    school = get_school(db, school_id)
    if school.deleted_at is None:
        soft_delete_school(db, school)
    db.commit()
    record_audit_event(
        db,
        admin=admin,
        action="school_deleted",
        entity_type="school",
        entity_id=str(school.id),
        description=f"Soft deleted school {school.name}",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )
    db.commit()

def activate_school(
    db: Session,
    school_id: UUID,
    *,
    admin=None,
    request=None,
):

    school = get_school(
        db,
        school_id,
    )

    if school.is_active:

        return school

    school.is_active = True

    persist_school(
        db,
        school,
    )

    record_audit_event(
        db,
        admin=admin,
        action="school_activated",
        entity_type="school",
        entity_id=str(school.id),
        description=f"Activated school {school.name}",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )

    db.commit()

    db.refresh(school)

    return school

def deactivate_school(
    db: Session,
    school_id: UUID,
    *,
    admin=None,
    request=None,
):

    school = get_school(
        db,
        school_id,
    )

    if not school.is_active:
        return school

    school.is_active = False

    persist_school(
        db,
        school,
    )

    record_audit_event(
        db,
        admin=admin,
        action="school_deactivated",
        entity_type="school",
        entity_id=str(school.id),
        description=f"Deactivated school {school.name}",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )

    db.commit()

    db.refresh(school)

    return school