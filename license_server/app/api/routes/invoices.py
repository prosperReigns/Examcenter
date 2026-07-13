from uuid import UUID

from fastapi import APIRouter, Depends, Request, status
from fastapi.responses import FileResponse
from sqlalchemy.orm import Session

from app.core.roles import Roles
from app.auth.dependencies import require_roles
from app.database.session import get_db

from app.schemas.invoice import (
    InvoiceCreateRequest,
    InvoiceRead,
)

from app.services.invoice_service import (
    create_invoice_record,
    get_invoice_list,
    get_invoice_record,
    pay_invoice,
    cancel_invoice
)
from app.services.invoice_pdf_service import (
    generate_invoice_pdf,
)

router = APIRouter(
    prefix="/api/invoices",
    tags=["Invoices"],
)

@router.get(
    "",
    response_model=list[InvoiceRead],
)
def list_invoices_endpoint(

    search: str | None = None,

    status_filter: str | None = None,

    page: int = 1,

    page_size: int = 20,

    db: Session = Depends(get_db),

    admin=Depends(
        require_roles(
            Roles.SUPER_ADMIN,
            Roles.STAFF
        )
    ),

):

    invoices, _ = get_invoice_list(

        db,

        search=search,

        status=status_filter,

        page=page,

        page_size=page_size,

    )

    return invoices

@router.get(
    "/{invoice_id}",
    response_model=InvoiceRead,
)
def invoice_details_endpoint(

    invoice_id: UUID,

    db: Session = Depends(get_db),

    admin=Depends(
        require_roles(
            Roles.SUPER_ADMIN,
            Roles.STAFF
        )
    ),

):

    return get_invoice_record(
        db,
        invoice_id,
    )

@router.post(
    "",
    response_model=InvoiceRead,
    status_code=status.HTTP_201_CREATED,
)
def create_invoice_endpoint(

    payload: InvoiceCreateRequest,

    request: Request,

    db: Session = Depends(get_db),

    admin=Depends(
        require_roles(
            Roles.SUPER_ADMIN,
            Roles.STAFF
        )
    ),

):

    return create_invoice_record(

        db=db,

        license_id=payload.license_id,

        description=payload.description,

        amount=payload.amount,

        due_days=payload.due_days,

        admin=admin,

        request=request,

    )

@router.post(
    "/{invoice_id}/pay",
    response_model=InvoiceRead,
)
def pay_invoice_endpoint(

    invoice_id: UUID,

    request: Request,

    db: Session = Depends(get_db),

    admin=Depends(
        require_roles(
            Roles.SUPER_ADMIN,
            Roles.STAFF
        )
    ),

):

    return pay_invoice(

        db=db,

        invoice_id=invoice_id,

        admin=admin,

        request=request,

    )

@router.post(
    "/{invoice_id}/cancel",
    response_model=InvoiceRead,
)
def cancel_invoice_endpoint(

    invoice_id: UUID,

    request: Request,

    db: Session = Depends(get_db),

    admin=Depends(
        require_roles(
            Roles.SUPER_ADMIN,
            Roles.STAFF
        )
    ),

):

    return cancel_invoice(

        db=db,

        invoice_id=invoice_id,

        admin=admin,

        request=request,

    )

@router.get(
    "/{invoice_id}/download",
)
def download_invoice_endpoint(

    invoice_id: UUID,

    db: Session = Depends(get_db),

    admin=Depends(
        require_roles(
            Roles.SUPER_ADMIN,
            Roles.STAFF
        )
    ),

):

    pdf = generate_invoice_pdf(
        db,
        invoice_id,
    )

    return FileResponse(
        pdf,
        media_type="application/pdf",
        filename=f"invoice-{invoice_id}.pdf",
    )

# @router.post(
#     "/{invoice_id}/send-email",
# )
# def email_invoice_endpoint(

#     invoice_id: UUID,

#     request: Request,

#     db: Session = Depends(get_db),

#     admin=Depends(
#         require_roles(
#             "Super Admin",
#             "Staff",
#         )
#     ),

# ):

#     return send_invoice_email(
#         db,
#         invoice_id,
#         admin=admin,
#         request=request,
#     )