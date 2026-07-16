from uuid import UUID

from fastapi import APIRouter, Depends, Form, Request
from fastapi.responses import HTMLResponse, RedirectResponse
from sqlalchemy.orm import Session
from app.database.session import SessionLocal

from app.core.roles import Roles
from app.auth.dependencies import require_roles
from app.database.session import get_db

from app.services.customer_service import (
    create_customer_record,
    get_customers,
    get_customer,
    update_customer_record,
)
from app.repositories.customer_repository import list_customers
from app.schemas.customer import CustomerCreate, CustomerUpdate
from app.utils.flash import flash

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
        search=None,
        page=1,
        page_size=100,
    )

    return templates.TemplateResponse(
        "customers/customers.html",
        {
            "request": request,
            "title": "Customers",
            "admin": admin,
            "customers": customers,
        },
    )


@router.get("/new", response_class=HTMLResponse)
def new_customer_page(
    request: Request,
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):
    return templates.TemplateResponse(
        "customers/customer_form.html",
        {
            "request": request,
            "title": "New Customer",
            "admin": admin,
            "customer": None,
        },
    )


@router.post("/new")
def create_customer_page(
    request: Request,
    name: str = Form(...),
    email: str | None = Form(None),
    phone: str | None = Form(None),
    country: str | None = Form(None),
    is_active: bool = Form(True),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):
    payload = CustomerCreate(
        name=name,
        email=email or None,
        phone=phone or None,
        country=country or None,
        is_active=is_active,
    )

    with SessionLocal() as db:
        customer = create_customer_record(
            db,
            payload,
            admin=admin,
            request=request,
        )

    flash(request, "Customer created successfully.", "success")

    return RedirectResponse(
        f"/customers/{customer.id}",
        status_code=303,
    )


@router.get("/{customer_id:uuid}", response_class=HTMLResponse,)
def customer_details_page(
    customer_id: UUID,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):

    return templates.TemplateResponse(
        "customers/customer_details.html",
        {
            "request": request,
            "title": "Customer Details",
            "admin": admin,
            "customer": get_customer(
                db,
                customer_id,
            ),
        },
    )


@router.get("/{customer_id:uuid}/edit", response_class=HTMLResponse)
def edit_customer_page(
    customer_id: UUID,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):
    return templates.TemplateResponse(
        "customers/customer_form.html",
        {
            "request": request,
            "title": "Edit Customer",
            "admin": admin,
            "customer": get_customer(db, customer_id),
        },
    )


@router.post("/{customer_id:uuid}/edit")
def update_customer_page(
    customer_id: UUID,
    request: Request,
    name: str = Form(...),
    email: str | None = Form(None),
    phone: str | None = Form(None),
    country: str | None = Form(None),
    is_active: bool = Form(False),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):
    payload = CustomerUpdate(
        name=name,
        email=email or None,
        phone=phone or None,
        country=country or None,
        is_active=is_active,
    )

    with SessionLocal() as db:
        customer = update_customer_record(
            db,
            customer_id,
            payload,
            admin=admin,
            request=request,
        )

    flash(request, "Customer updated successfully.", "success")

    return RedirectResponse(
        f"/customers/{customer.id}",
        status_code=303,
    )


@router.get("/customer", response_class=HTMLResponse)
def customers_page(request: Request, admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF))) -> HTMLResponse:
    with SessionLocal() as db:
        customers, _ = list_customers(db, offset=0, limit=100)

    return templates.TemplateResponse(
        "customers/customers.html",
        {
            "request": request,
            "settings": settings,
            "title": "Customers",
            "admin": admin,
            "customers": customers,
        },
    )
