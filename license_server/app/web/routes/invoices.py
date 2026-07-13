from uuid import UUID

from fastapi import APIRouter, Depends, Request
from fastapi.responses import HTMLResponse, RedirectResponse, Response
from app.database.session import SessionLocal

from app.auth.dependencies import require_roles
from app.database.session import get_db

from app.core.roles import Roles
from app.services.invoice_service import get_invoice_list
from app.services.invoice_service import (
    get_invoice_record,pay_invoice, cancel_invoice
)
from app.services.invoice_pdf_service import generate_invoice_pdf

from app.utils.flash import flash

from app.web.templates import templates
from app.core.config import get_settings


router = APIRouter(
    prefix="/invoices", 
    tags=["Web - Invoices"],
)
settings = get_settings()

@router.get("/", response_class=HTMLResponse)
def invoices_page(
    request: Request,
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):

    with SessionLocal() as db:
        invoices, total = get_invoice_list(
            db,
            page=1,
            page_size=100,
        )

    return templates.TemplateResponse(
        "invoices.html",
        {
            "request": request,
            "settings": settings,
            "title": "Invoices",
            "admin": admin,
            "invoices": invoices,
            "total": total,
        },
    )

@router.get("/{invoice_id}", response_class=HTMLResponse)
def invoice_details_page(
    invoice_id: UUID,
    request: Request,
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):

    with SessionLocal() as db:

        invoice = get_invoice_record(
            db,
            invoice_id,
        )

    return templates.TemplateResponse(
        "invoice_details.html",
        {
            "request": request,
            "settings": settings,
            "title": invoice.invoice_number,
            "admin": admin,
            "invoice": invoice,
        },
    )


@router.post("/{invoice_id}/pay")
def pay_invoice_submit(
    invoice_id: UUID,
    request: Request,
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):

    with SessionLocal() as db:

        pay_invoice(
            db,
            invoice_id,
            admin=admin,
            request=request,
        )

    flash(
        request,
        "Invoice marked as paid.",
        "success",
    )

    return RedirectResponse(
        f"/invoices/{invoice_id}",
        status_code=303,
    )

@router.post("/{invoice_id}/cancel")
def cancel_invoice_submit(
    invoice_id: UUID,
    request: Request,
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):

    with SessionLocal() as db:
        cancel_invoice(
            db,
            invoice_id,
            admin=admin,
            request=request,
        )

    flash(
        request,
        "Invoice cancelled.",
        "warning",
    )

    return RedirectResponse(
        f"/invoices/{invoice_id}",
        status_code=303,
    )

@router.get("/{invoice_id}/pdf")
def download_invoice_pdf(
    invoice_id: UUID,
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):

    with SessionLocal() as db:
        invoice = get_invoice_record(
            db,
            invoice_id,
        )

        pdf = generate_invoice_pdf(
            invoice,
        )

    return Response(
        content=pdf,
        media_type="application/pdf",
        headers={
            "Content-Disposition":
            f'attachment; filename="{invoice.invoice_number}.pdf"'
        },
    )