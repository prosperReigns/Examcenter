from uuid import UUID

from fastapi import HTTPException, status
from sqlalchemy import func, select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from app.models.customer import Customer
from app.repositories.customer_repository import create_customer, get_customer_by_email, get_customer_by_id, list_customers, soft_delete_customer
from app.schemas.customer import CustomerCreate, CustomerUpdate
from app.services.audit_service import record_audit_event


def get_customers(db: Session, *, search: str | None, page: int, page_size: int) -> tuple[list[Customer], int]:
    offset = (page - 1) * page_size
    return list_customers(db, search=search, offset=offset, limit=page_size)


def get_customer(db: Session, customer_id: UUID) -> Customer:
    customer = get_customer_by_id(db, customer_id)
    if customer is None or customer.deleted_at is not None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Customer not found")
    return customer


def create_customer_record(db: Session, payload: CustomerCreate, *, admin=None, request=None) -> Customer:
    if payload.email and get_customer_by_email(db, payload.email.lower().strip()) is not None:
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="Customer email already exists")

    try:
        customer = create_customer(
            db,
            name=payload.name,
            email=payload.email,
            phone=payload.phone,
            country=payload.country,
            is_active=payload.is_active,
        )
        record_audit_event(
            db,
            admin=admin,
            action="customer_created",
            entity_type="customer",
            entity_id=str(customer.id),
            description=f"Created customer {customer.name}",
            ip_address=request.client.host if request and request.client else None,
            user_agent=request.headers.get("user-agent") if request else None,
        )
        db.commit()
        db.refresh(customer)
        
        return customer
    except IntegrityError as exc:
        db.rollback()
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="Customer already exists") from exc


def update_customer_record(db: Session, customer_id: UUID, payload: CustomerUpdate, *, admin=None, request=None) -> Customer:
    customer = get_customer(db, customer_id)
    if payload.email and payload.email.lower().strip() != customer.email and get_customer_by_email(db, payload.email.lower().strip()) is not None:
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="Customer email already exists")

    data = payload.model_dump(exclude_unset=True)
    if "email" in data and data["email"] is not None:
        data["email"] = data["email"].lower().strip()
    if "name" in data and data["name"] is not None:
        data["name"] = data["name"].strip()
    if "phone" in data and data["phone"] is not None:
        data["phone"] = data["phone"].strip()
    if "country" in data and data["country"] is not None:
        data["country"] = data["country"].strip()

    for field, value in data.items():
        if hasattr(customer, field):
            setattr(customer, field, value)

    try:
        db.add(customer)

        record_audit_event(
            db,
            admin=admin,
            action="customer_updated",
            entity_type="customer",
            entity_id=str(customer.id),
            description=f"Updated customer {customer.name}",
            ip_address=request.client.host if request and request.client else None,
            user_agent=request.headers.get("user-agent") if request else None,
        )

        db.commit()
        db.refresh(customer)
        
        return customer
    except IntegrityError as exc:
        db.rollback()
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="Customer update violates unique constraints") from exc


def delete_customer_record(db: Session, customer_id: UUID, *, admin=None, request=None) -> None:
    customer = get_customer(db, customer_id)
    soft_delete_customer(db, customer)

    record_audit_event(
        db,
        admin=admin,
        action="customer_deleted",
        entity_type="customer",
        entity_id=str(customer.id),
        description=f"Soft deleted customer {customer.name}",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )
    db.commit()

def customer_statistics(db: Session) -> dict:
    total = db.scalar(
        select(func.count())
        .select_from(Customer)
        .where(Customer.deleted_at.is_(None))
    ) or 0
    active = db.scalar(
        select(func.count())
        .select_from(Customer)
        .where(Customer.deleted_at.is_(None), Customer.is_active.is_(True))
    ) or 0
    deleted = db.scalar(
        select(func.count())
        .select_from(Customer)
        .where(Customer.deleted_at.is_not(None))
    ) or 0

    return {
        "total": total,
        "active": active,
        "inactive": total - active,
        "deleted": deleted,
    }
