from datetime import datetime, timedelta, timezone
from uuid import UUID

from fastapi import HTTPException, status
from sqlalchemy import extract, func, select
from sqlalchemy.orm import Session

from app.models.invoice import Invoice

from app.repositories.invoice_repository import (
    create_invoice,
    get_invoice,
    get_invoice_by_id,
    list_invoices,
    mark_invoice_cancelled,
    mark_invoice_paid,
    persist_invoice,

)
from app.services.license_management_service import renew_license
from app.repositories.license_repository import get_license_by_id
from app.services.audit_service import record_audit_event

def generate_invoice_number(
    db: Session,
) -> str:

    year = datetime.now().year

    count = db.scalar(
        select(func.count())
        .select_from(Invoice)
        .where(
            extract("year", Invoice.created_at) == year
        )
    ) or 0

    return f"INV-{year}-{count + 1:06d}"

def create_invoice_record(
    db: Session,
    *,
    license_id: UUID,
    description,
    amount,
    due_days=7,
    admin=None,
    request=None,
):

    license_obj = get_license_by_id(
        db,
        license_id,
    )

    if license_obj is None:

        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="License not found",
        )

    invoice = Invoice(
        license_id=license_obj.id,
        school_id=license_obj.school_id,
        invoice_number=generate_invoice_number(
            db,
        ),
        description=description,
        amount=amount,
        currency="NGN",
        status="pending",
        due_date=datetime.now(
            timezone.utc,
        ) + timedelta(days=due_days),
    )

    create_invoice(
        db,
        invoice,
    )

    record_audit_event(
        db,
        admin=admin,
        action="invoice_created",
        entity_type="invoice",
        entity_id=str(invoice.id),
        description=f"Invoice {invoice.invoice_number} created",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )

    db.commit()
    db.refresh(invoice)
    return invoice


class InvoiceService:
    def __init__(self, db: Session):
        self.db = db

    def create_from_payment(
        self,
        *,
        payment_id,
        customer_id=None,
    ) -> Invoice:
        from app.repositories.payment_repository import get_payment_by_id

        payment = get_payment_by_id(
            self.db,
            UUID(str(payment_id)),
        )
        if payment is None:
            raise HTTPException(
                status_code=status.HTTP_404_NOT_FOUND,
                detail="Payment not found.",
            )

        if payment.invoice_id:
            existing = get_invoice_by_id(
                self.db,
                payment.invoice_id,
            )
            if existing is not None:
                return existing

        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Payment has no invoice.",
        )

def get_invoice_record(
    db: Session,
    invoice_id: UUID,
):

    invoice = get_invoice(
        db,
        invoice_id,
    )

    if invoice is None:

        raise HTTPException(
            status_code=404,
            detail="Invoice not found",
        )

    return invoice

def get_invoice_list(
    db: Session,
    *,
    search=None,
    status=None,
    school_id=None,
    page=1,
    page_size=20,
):

    offset = (
        page - 1
    ) * page_size

    return list_invoices(
        db,
        search=search,
        status=status,
        school_id=school_id,
        offset=offset,
        limit=page_size,
    )

def cancel_invoice(
    db: Session,
    invoice_id: UUID,
    *,
    admin=None,
    request=None,
):

    invoice = get_invoice_record(
        db,
        invoice_id,
    )

    if invoice.status == "paid":

        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Paid invoices cannot be cancelled.",
        )

    mark_invoice_cancelled(
        db,
        invoice,
    )

    record_audit_event(
        db,
        admin=admin,
        action="invoice_cancelled",
        entity_type="invoice",
        entity_id=str(invoice.id),
        description=f"Invoice {invoice.invoice_number} cancelled",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )

    db.commit()

    return invoice

def pay_invoice(
    db: Session,
    invoice_id: UUID,
    *,
    payment_id=None,
    admin=None,
    request=None,
):
    invoice = get_invoice_record(
    db,
    invoice_id,
)

    if invoice.status == "paid":

        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Invoice already paid.",
        )
    invoice.paid_at = datetime.now(timezone.utc)

    persist_invoice(
        db,
        invoice,
    )

    mark_invoice_paid(
        db,
        invoice,
    )

    renew_license(
        db=db,
        license_id=invoice.license_id,
        plan="annual",
        payment_id=payment_id,
        admin=admin,
        request=request,
    )

    record_audit_event(
        db,
        admin=admin,
        action="invoice_paid",
        entity_type="invoice",
        entity_id=str(invoice.id),
        description=f"{invoice.invoice_number} paid",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )

    db.commit()

    return invoice

def expire_invoice(
    db: Session,
    invoice,
):
    if invoice.status != "pending":
        return invoice

    invoice.status = "expired"

    persist_invoice(
        db,
        invoice,
    )

    db.commit()

    return invoice

