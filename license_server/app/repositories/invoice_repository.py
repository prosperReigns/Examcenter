from uuid import UUID

from sqlalchemy import desc, func, select
from sqlalchemy.orm import Session

from app.models.invoice import Invoice


def create_invoice(
    db: Session,
    invoice: Invoice,
) -> Invoice:
    db.add(invoice)
    db.flush()
    return invoice


def get_invoice(
    db: Session,
    invoice_id: UUID,
) -> Invoice | None:
    return db.get(Invoice, invoice_id)


def get_invoice_by_number(
    db: Session,
    invoice_number: str,
) -> Invoice | None:

    statement = (
        select(Invoice)
        .where(
            Invoice.invoice_number == invoice_number
        )
    )

    return db.scalar(statement)


def list_invoices(
    db: Session,
    *,
    search: str | None = None,
    status: str | None = None,
    school_id: UUID | None = None,
    offset: int = 0,
    limit: int = 20,
) -> tuple[list[Invoice], int]:

    statement = select(Invoice)
    count_statement = select(func.count()).select_from(Invoice)

    if search:
        term = f"%{search.strip()}%"

        statement = statement.where(
            Invoice.invoice_number.ilike(term)
        )

        count_statement = count_statement.where(
            Invoice.invoice_number.ilike(term)
        )

    if status:
        statement = statement.where(
            Invoice.status == status
        )

        count_statement = count_statement.where(
            Invoice.status == status
        )

    if school_id:
        statement = statement.where(
            Invoice.school_id == school_id
        )

        count_statement = count_statement.where(
            Invoice.school_id == school_id
        )

    total = db.scalar(count_statement) or 0

    invoices = db.scalars(
        statement
        .order_by(desc(Invoice.created_at))
        .offset(offset)
        .limit(limit)
    ).all()

    return invoices, total


def delete_invoice(
    db: Session,
    invoice: Invoice,
):

    db.delete(invoice)
    db.flush()

def update_invoice_status(
    db,
    invoice,
    status: str,
):
    invoice.status = status

    db.add(invoice)
    db.flush()

    return invoice


def count_pending_invoices(
    db: Session,
) -> int:

    statement = (
        select(func.count())
        .select_from(Invoice)
        .where(
            Invoice.status == "pending"
        )
    )

    return db.scalar(statement) or 0


def count_paid_invoices(
    db: Session,
) -> int:

    statement = (
        select(func.count())
        .select_from(Invoice)
        .where(
            Invoice.status == "paid"
        )
    )

    return db.scalar(statement) or 0


def get_total_invoice_amount(
    db: Session,
):

    statement = (
        select(func.sum(Invoice.amount))
        .where(
            Invoice.status == "paid"
        )
    )

    return db.scalar(statement) or 0

def mark_invoice_cancelled():
    pass
def mark_invoice_paid():
    pass
def persist_invoice():
    pass
def get_invoice_by_id():
    pass
def get_invoice_by_payment():
    pass