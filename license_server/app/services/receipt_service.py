from __future__ import annotations

from datetime import datetime, timezone
from uuid import UUID
from pathlib import Path

from fastapi import HTTPException, status
from sqlalchemy import extract, func, select
from sqlalchemy.orm import Session

from app.models.payment import Payment
from app.models.receipt import Receipt

from app.repositories.payment_repository import (
    get_payment,
)

from app.repositories.receipt_repository import (
    create_receipt,
    get_receipt,
    get_receipt_by_number,
    get_receipt_by_payment,
    list_receipts,
    persist_receipt,
    receipt_count,
)

from app.services.audit_service import (
    record_audit_event,
)
from fastapi.responses import FileResponse

from app.services.notification_service import (
    send_email,
    send_sms,
)

from app.services.receipt_pdf_service import generate_receipt_pdf

def generate_receipt_number(
    db: Session,
) -> str:
    """
    Example:
    RCT-2026-000001
    """

    year = datetime.now(timezone.utc).year
    count = (
        db.scalar(
            select(func.count())
            .select_from(Receipt)
            .where(
                extract(
                    "year",
                    Receipt.created_at,
                ) == year
            )
        )
        or 0
    )

    return f"RCT-{year}-{count+1:06d}"

def get_receipt_record(
    db: Session,
    receipt_id: UUID,
) -> Receipt:

    receipt = get_receipt(
        db,
        receipt_id,
    )

    if receipt is None:

        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Receipt not found",
        )

    return receipt

def create_receipt_record(
    db: Session,
    payment: Payment,
    *,
    admin=None,
    request=None,
) -> Receipt:

    existing = get_receipt_by_payment(
        db,
        payment.id,
    )

    if existing:

        return existing

    receipt = Receipt(

        receipt_number=generate_receipt_number(db),

        invoice_id=payment.invoice_id,

        payment_id=payment.id,

        customer_id=payment.customer_id,

        school_id=payment.school_id,

        amount=payment.amount,

        currency=payment.currency,

        status="issued",

    )

    create_receipt(
        db,
        receipt,
    )

    pdf_path = generate_receipt_pdf(receipt)

    receipt.pdf_path = pdf_path

    persist_receipt(
        db,
        receipt,
    )

    record_audit_event(
        db,
        admin=admin,
        action="receipt_created",
        entity_type="receipt",
        entity_id=str(receipt.id),
        description=f"Receipt {receipt.receipt_number} created.",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )

    db.commit()

    db.refresh(receipt)

    return receipt

def get_receipt_list(
    db: Session,
    *,
    search: str | None = None,
    school_id: UUID | None = None,
    customer_id: UUID | None = None,
    status_filter: str | None = None,
    page: int = 1,
    page_size: int = 20,
):

    offset = (page - 1) * page_size

    return list_receipts(
        db,
        search=search,
        school_id=school_id,
        customer_id=customer_id,
        status=status_filter,
        offset=offset,
        limit=page_size,
    )

def reissue_receipt(
    db: Session,
    receipt_id: UUID,
    *,
    admin=None,
    request=None,
) -> Receipt:

    receipt = get_receipt_record(
        db,
        receipt_id,
    )

    receipt.status = "reissued"

    persist_receipt(
        db,
        receipt,
    )

    record_audit_event(
        db,
        admin=admin,
        action="receipt_reissued",
        entity_type="receipt",
        entity_id=str(receipt.id),
        description=f"Receipt {receipt.receipt_number} reissued.",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )

    db.commit()

    db.refresh(receipt)

    return receipt

def void_receipt(
    db: Session,
    receipt_id: UUID,
    *,
    admin=None,
    request=None,
) -> Receipt:

    receipt = get_receipt_record(
        db,
        receipt_id,
    )

    if receipt.status == "void":

        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Receipt already voided.",
        )

    receipt.status = "void"

    persist_receipt(
        db,
        receipt,
    )

    record_audit_event(
        db,
        admin=admin,
        action="receipt_voided",
        entity_type="receipt",
        entity_id=str(receipt.id),
        description=f"Receipt {receipt.receipt_number} voided.",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )

    db.commit()

    db.refresh(receipt)

    return receipt

def receipt_statistics(
    db: Session,
):

    return {

        "total_receipts":
            receipt_count(db),

        "issued":
            db.scalar(
                select(func.count())
                .select_from(Receipt)
                .where(
                    Receipt.status == "issued"
                )
            ) or 0,

        "reissued":
            db.scalar(
                select(func.count())
                .select_from(Receipt)
                .where(
                    Receipt.status == "reissued"
                )
            ) or 0,

        "void":
            db.scalar(
                select(func.count())
                .select_from(Receipt)
                .where(
                    Receipt.status == "void"
                )
            ) or 0,

    }

def get_receipt_pdf_path(
    db: Session,
    receipt_id: UUID,
) -> Path:

    receipt = get_receipt_record(
        db,
        receipt_id,
    )

    if not receipt.pdf_path:
        raise HTTPException(
            status_code=404,
            detail="Receipt PDF has not been generated.",
        )

    path = Path(receipt.pdf_path)

    if not path.exists():
        raise HTTPException(
            status_code=404,
            detail="Receipt PDF file not found.",
        )

    return path
def email_receipt(
    db: Session,
    receipt_id: UUID,
    *,
    admin=None,
    request=None,
):

    receipt = get_receipt_record(
        db,
        receipt_id,
    )

    if not receipt.customer.email:

        raise HTTPException(
            status_code=400,
            detail="Customer has no email address.",
        )

    send_email(
        db,
        recipient=receipt.customer.email,
        subject=f"Receipt {receipt.receipt_number}",
        message=(
            f"Dear {receipt.customer.full_name},\n\n"
            f"Attached is your payment receipt "
            f"{receipt.receipt_number}.\n\n"
            "Thank you."
        ),
        customer_id=receipt.customer.id,
        school_id=receipt.school.id,
        admin=admin,
        request=request,
    )

    record_audit_event(
        db,
        admin=admin,
        action="receipt_emailed",
        entity_type="receipt",
        entity_id=str(receipt.id),
        description=f"Receipt emailed to {receipt.customer.email}",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )

    db.commit()

    return receipt


def verify_receipt(
    db: Session,
    receipt_number: str,
):

    receipt = get_receipt_by_number(
        db,
        receipt_number,
    )

    if receipt is None:
        raise HTTPException(
            status_code=404,
            detail="Receipt not found.",
        )

    return receipt

def download_receipt(
    db: Session,
    receipt_id: UUID,
):

    path = get_receipt_pdf_path(
        db,
        receipt_id,
    )

    return FileResponse(
        path=path,
        filename=path.name,
        media_type="application/pdf",
    )

def regenerate_pdf(
    db: Session,
    receipt_id: UUID,
    *,
    admin=None,
    request=None,
):

    receipt = get_receipt_record(
        db,
        receipt_id,
    )

    pdf_path = generate_receipt_pdf(
        receipt,
    )

    receipt.pdf_path = pdf_path

    persist_receipt(
        db,
        receipt,
    )

    record_audit_event(
        db,
        admin=admin,
        action="receipt_pdf_regenerated",
        entity_type="receipt",
        entity_id=str(receipt.id),
        description=f"Regenerated PDF for {receipt.receipt_number}",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )

    db.commit()

    db.refresh(receipt)

    return receipt

def sms_receipt(
    db: Session,
    receipt_id: UUID,
    *,
    admin=None,
    request=None,
):

    receipt = get_receipt_record(
        db,
        receipt_id,
    )

    if not receipt.customer.phone:

        raise HTTPException(
            status_code=400,
            detail="Customer has no phone number.",
        )

    send_sms(
        db,
        recipient=receipt.customer.phone,
        message=(
            f"Payment received.\n"
            f"Receipt: {receipt.receipt_number}\n"
            f"Amount: {receipt.currency} {receipt.amount:,.2f}"
        ),
        customer_id=receipt.customer.id,
        school_id=receipt.school.id,
        admin=admin,
        request=request,
    )

    record_audit_event(
        db,
        admin=admin,
        action="receipt_sms_sent",
        entity_type="receipt",
        entity_id=str(receipt.id),
        description=f"Receipt SMS sent to {receipt.customer.phone}",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )

    db.commit()

    return receipt