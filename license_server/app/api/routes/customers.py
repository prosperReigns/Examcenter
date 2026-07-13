from uuid import UUID

from fastapi import APIRouter, Depends, Query, Request, status
from sqlalchemy.orm import Session

from app.auth.dependencies import require_roles
from app.database.session import get_db
from app.schemas.customer import CustomerCreate, CustomerRead, CustomerUpdate
from app.services.customer_service import create_customer_record, delete_customer_record, get_customer, get_customers, update_customer_record
from app.core.roles import Roles

router = APIRouter(prefix="/api/customers", tags=["customers"])


@router.get("", response_model=list[CustomerRead])
def list_customers_endpoint(
    search: str | None = Query(default=None, max_length=150),
    page: int = Query(default=1, ge=1),
    page_size: int = Query(default=20, ge=1, le=100),
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):
    items, _ = get_customers(db, search=search, page=page, page_size=page_size)
    return items


@router.get("/{customer_id}", response_model=CustomerRead)
def get_customer_endpoint(customer_id: UUID, db: Session = Depends(get_db), admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF))):
    return get_customer(db, customer_id)


@router.post("", response_model=CustomerRead, status_code=status.HTTP_201_CREATED)
def create_customer_endpoint(payload: CustomerCreate, request: Request, db: Session = Depends(get_db), admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF))):
    return create_customer_record(db, payload, admin=admin, request=request)


@router.patch("/{customer_id}", response_model=CustomerRead)
def update_customer_endpoint(customer_id: UUID, payload: CustomerUpdate, request: Request, db: Session = Depends(get_db), admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF))):
    return update_customer_record(db, customer_id, payload, admin=admin, request=request)


@router.delete("/{customer_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_customer_endpoint(customer_id: UUID, request: Request, db: Session = Depends(get_db), admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF))):
    delete_customer_record(db, customer_id, admin=admin, request=request)
