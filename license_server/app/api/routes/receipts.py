from uuid import UUID

from fastapi import APIRouter, Depends, HTTPException, Query
from fastapi.responses import FileResponse
from sqlalchemy.orm import Session

from app.core.roles import Roles
from app.auth.dependencies import require_roles
from app.database.session import get_db
from app.schemas.receipt import ReceiptRead
from app.services.receipt_service import (
    get_receipt_record,
    get_receipt_list,
    reissue_receipt,
    void_receipt,
    get_receipt,
    download_receipt,
    sms_receipt,
    regenerate_pdf,
    email_receipt
)

router = APIRouter(
    prefix="/api/receipts",
    tags=["Receipts"],
)


@router.get("", response_model=list[ReceiptRead])
def list_receipts_api(
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)), page: int = Query(1, ge=1),
    page_size: int = Query(20, ge=1, le=100),
):
    receipts, _ = get_receipt_list(
        db,
        page=page,
        page_size=page_size,
    )
    return receipts


@router.get("/{receipt_id}", response_model=ReceiptRead)
def receipt_details(
    receipt_id: UUID,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):
    return get_receipt_record(
        db,
        receipt_id,
    )

@router.get("/{receipt_id}/download",)
def download_receipt_api(
    receipt_id: UUID,
    db: Session = Depends(get_db),
    admin=Depends(
        require_roles(
            Roles.SUPER_ADMIN,
            Roles.STAFF
        )
    ),
):

    return download_receipt(
        db,
        receipt_id,
    )

@router.post("/{receipt_id}/regenerate")
def regenerate_receipt_api(
    receipt_id: UUID,
    db: Session = Depends(get_db),
    admin=Depends(
        require_roles(
            Roles.SUPER_ADMIN,
            Roles.STAFF
        )
    ),

):

    return regenerate_pdf(
        db,
        receipt_id,
    )

@router.post("/{receipt_id}/email")
def email_receipt_api(
    receipt_id: UUID,
    db: Session = Depends(get_db),
    admin=Depends(
        require_roles(
            Roles.SUPER_ADMIN,
            Roles.STAFF
        )
    ),
):

    email_receipt(
        db,
        receipt_id,
    )

    return {
        "message":
        "Receipt emailed successfully."
    }

@router.post("/{receipt_id}/sms")
def sms_receipt_api(
    receipt_id: UUID,
    db: Session = Depends(get_db),
    admin=Depends(
        require_roles(
            Roles.SUPER_ADMIN,
            Roles.STAFF
        )
    ),
):

    sms_receipt(
        db,
        receipt_id,
    )

    return {
        "message":
        "Receipt sent successfully."
    }


@router.post("/{receipt_id}/reissue")
def reissue_receipt_api(
    receipt_id: UUID,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):
    receipt = reissue_receipt(
        db,
        receipt_id,
    )

    return {

        "message": "Receipt reissued.",

        "receipt": ReceiptRead.model_validate(receipt),

    }


@router.post("/{receipt_id}/void")
def void_receipt_api(
    receipt_id: UUID,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):
    receipt = void_receipt(
        db,
        receipt_id,
    )

    return {

        "message": "Receipt voided.",

        "receipt": ReceiptRead.model_validate(receipt),

    }


@router.get("/{receipt_id}/download")
def download_receipt_endpoint(
    receipt_id: UUID,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):

    path = download_receipt(
        db,
        receipt_id,
    )

    return FileResponse(path)

@router.post("/{receipt_id}/regenerate")
def regenerate_receipt_endpoint(
    receipt_id: UUID,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):
    return regenerate_pdf(
        db,
        receipt_id,
    )

@router.post("/{receipt_id}/email")
def email_receipt_endpoint(
    receipt_id: UUID,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):
    email_receipt(
        db,
        receipt_id,
    )

    return {
        "message": "Receipt emailed successfully."
    }

@router.post("/{receipt_id}/sms")
def sms_receipt_endpoint(
    receipt_id: UUID,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):
    sms_receipt(
        db,
        receipt_id,
    )

    return {
        "message": "Receipt sent successfully."
    }