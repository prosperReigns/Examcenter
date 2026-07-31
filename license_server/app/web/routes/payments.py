from uuid import UUID
from fastapi.responses import HTMLResponse
from fastapi import Depends, APIRouter, Request
from fastapi.responses import RedirectResponse
from app.database.session import SessionLocal
from app.auth.dependencies import require_roles

from app.core.roles import Roles
from app.repositories.payment_repository import list_payments
from app.web.templates import templates

from app.services.payment_service import (
    get_payment_list,
    get_payment,
)
from app.core.config import get_settings

settings = get_settings()
router = APIRouter(
    prefix="/payments",
    tags=["Payment Pages"],
)

@router.get("/", response_class=HTMLResponse)
def payments_page(request: Request, admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF))) -> HTMLResponse:
    with SessionLocal() as db:
        payments, _ = list_payments(db, offset=0, limit=100)
    return templates.TemplateResponse(
        "payments.html",
        {
            "request": request,
            "settings": settings,
            "title": "Payments",
            "admin": admin,
            "payments": payments,
        },
    )

@router.get("/payments/{payment_id}")
def payment_details_page(
    payment_id: UUID,
    request: Request,
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):

    with SessionLocal() as db:

        payment = get_payment(
            db,
            payment_id,
        )

    if payment is None:

        return RedirectResponse(
            "/payments",
            status_code=303,
        )

    return templates.TemplateResponse(
        "payment_details.html",
        {
            "request": request,
            "title": "Payment Details",
            "payment": payment,
            "admin": admin,
        },
    )


@router.get("/payments/new")
def initialize_payment_page(
    request: Request,
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):

    return templates.TemplateResponse(
        "payment_initialize.html",
        {
            "request": request,
            "title": "Initialize Payment",
            "admin": admin,
        },
    )