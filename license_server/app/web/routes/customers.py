from uuid import UUID

from fastapi import APIRouter, Depends, Request
from fastapi.responses import HTMLResponse
from sqlalchemy.orm import Session
from app.database.session import SessionLocal

from app.core.roles import Roles
from app.auth.dependencies import require_roles
from app.database.session import get_db

from app.services.customer_service import (
    get_customers,
    get_customer,
)
from app.repositories.customer_repository import list_customers

from app.web.templates import templates
from app.core.config import get_settings

router = APIRouter(
    prefix="/customers", 
    tags=["Customer"],
)
settings = get_settings()

@router.get("/",response_class=HTMLResponse,)
def customer_list_page(
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):

    customers, _ = get_customers(
        db,
        page=1,
        page_size=100,
    )

    return templates.TemplateResponse(
        "customers.html",
        {
            "request": request,
            "customers": customers,
        },
    )


@router.get("/{customer_id}", response_class=HTMLResponse,)
def customer_details_page(
    customer_id: UUID,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):

    return templates.TemplateResponse(
        "customer_details.html",
        {
            "request": request,
            "customer": get_customer(
                db,
                customer_id,
            ),
        },
    )

@router.get("/customer", response_class=HTMLResponse)
def customers_page(request: Request, admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF))) -> HTMLResponse:
    with SessionLocal() as db:
        customers, _ = list_customers(db, offset=0, limit=100)

    return templates.TemplateResponse(
        "customers.html",
        {
            "request": request,
            "settings": settings,
            "title": "Customers",
            "admin": admin,
            "customers": customers,
        },
    )