from uuid import UUID

from app.database.session import SessionLocal

from fastapi import Depends, APIRouter, Form, Request
from app.auth.dependencies import require_roles

from app.core.roles import Roles
from app.services.receipt_service import (
    get_receipt_record,
    get_receipt_list,
    get_receipt_pdf_path, email_receipt,verify_receipt
)
from fastapi.responses import HTMLResponse, RedirectResponse, Response, FileResponse

from app.web.templates import templates

from app.core.config import get_settings


router = APIRouter(
    prefix="/receipts", 
    tags=["Web -Receipts"],
)
settings = get_settings()

@router.get("/", response_class=HTMLResponse)
def receipts_page(
    request: Request,
    admin=Depends(
        require_roles(
            Roles.SUPER_ADMIN,
           Roles.STAFF
        )
    ),
):

    with SessionLocal() as db:
        receipts, _ = get_receipt_list(db)

    return templates.TemplateResponse(
        "receipts.html",
        {
            "request": request,
            "settings": settings,
            "title": "Receipts",
            "admin": admin,
            "receipts": receipts,
        },
    )

@router.get("/{receipt_id:uuid}", response_class=HTMLResponse)
def receipt_details_page(
    receipt_id: UUID,
    request: Request,
    admin=Depends(
        require_roles(
            Roles.SUPER_ADMIN,
            Roles.STAFF
        )
    ),
):

    with SessionLocal() as db:
        receipt = get_receipt_record(
            db,
            receipt_id,
        )

    return templates.TemplateResponse(
        "receipt_details.html",
        {
            "request": request,
            "settings": settings,
            "title": "Receipt",
            "admin": admin,
            "receipt": receipt,
        },
    )



@router.get("/{receipt_id:uuid}/download")
def download_receipt(
    receipt_id: UUID,
    admin=Depends(
        require_roles(
            Roles.SUPER_ADMIN,
            Roles.STAFF
        )
    ),
):

    with SessionLocal() as db:
        path = get_receipt_pdf_path(
            db,
            receipt_id,
        )

    return FileResponse(
        path,
        filename=path.name,
        media_type="application/pdf",
    )

@router.post("/{receipt_id:uuid}/email")
def email_receipt_page(
    receipt_id: UUID,
    request: Request,
    admin=Depends(
        require_roles(
            Roles.SUPER_ADMIN,
            Roles.STAFF
        )
    ),
):

    with SessionLocal() as db:
        email_receipt(
            db,
            receipt_id,
            admin=admin,
            request=request,
        )

    return RedirectResponse(
        f"/receipts/{receipt_id}",
        status_code=303,
    )

@router.get(
    "/verify-receipt/{receipt_number}",
    response_class=HTMLResponse,
)
def verify_receipt_page(
    receipt_number: str,
    request: Request,
):

    with SessionLocal() as db:

        receipt = verify_receipt(
            db,
            receipt_number,
        )

    return templates.TemplateResponse(
        "verify_receipt.html",
        {
            "request": request,
            "receipt": receipt,
            "title": "Receipt Verification",
        },
    )
