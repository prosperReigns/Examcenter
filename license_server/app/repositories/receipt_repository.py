from __future__ import annotations

from uuid import UUID

from sqlalchemy import desc, func, select
from sqlalchemy.orm import Session

from app.models.receipt import Receipt


def create_receipt(
    db: Session,
    receipt: Receipt,
) -> Receipt:
    db.add(receipt)
    db.flush()
    return receipt


def persist_receipt(
    db: Session,
    receipt: Receipt,
) -> Receipt:
    db.add(receipt)
    db.flush()
    return receipt


def get_receipt(
    db: Session,
    receipt_id: UUID,
) -> Receipt | None:
    return db.get(Receipt, receipt_id)


def get_receipt_by_number(
    db: Session,
    receipt_number: str,
) -> Receipt | None:
    statement = (
        select(Receipt)
        .where(
            Receipt.receipt_number == receipt_number
        )
    )

    return db.scalar(statement)


def get_receipt_by_payment(
    db: Session,
    payment_id: UUID,
) -> Receipt | None:
    statement = (
        select(Receipt)
        .where(
            Receipt.payment_id == payment_id
        )
    )

    return db.scalar(statement)


def get_receipt_by_invoice(
    db: Session,
    invoice_id: UUID,
) -> list[Receipt]:

    statement = (
        select(Receipt)
        .where(
            Receipt.invoice_id == invoice_id
        )
        .order_by(
            desc(Receipt.issued_at)
        )
    )

    return db.scalars(statement).all()


def list_receipts(
    db: Session,
    *,
    search: str | None = None,
    school_id: UUID | None = None,
    customer_id: UUID | None = None,
    status: str | None = None,
    offset: int = 0,
    limit: int = 20,
) -> tuple[list[Receipt], int]:

    statement = select(Receipt)

    count_statement = (
        select(func.count())
        .select_from(Receipt)
    )

    if search:

        term = f"%{search.strip()}%"

        statement = statement.where(
            Receipt.receipt_number.ilike(term)
        )

        count_statement = count_statement.where(
            Receipt.receipt_number.ilike(term)
        )

    if school_id:

        statement = statement.where(
            Receipt.school_id == school_id
        )

        count_statement = count_statement.where(
            Receipt.school_id == school_id
        )

    if customer_id:

        statement = statement.where(
            Receipt.customer_id == customer_id
        )

        count_statement = count_statement.where(
            Receipt.customer_id == customer_id
        )

    if status:

        statement = statement.where(
            Receipt.status == status
        )

        count_statement = count_statement.where(
            Receipt.status == status
        )

    total = db.scalar(count_statement) or 0

    receipts = db.scalars(
        statement
        .order_by(
            desc(Receipt.issued_at)
        )
        .offset(offset)
        .limit(limit)
    ).all()

    return receipts, total


def delete_receipt(
    db: Session,
    receipt: Receipt,
) -> None:
    db.delete(receipt)
    db.flush()


def receipt_count(
    db: Session,
) -> int:

    statement = (
        select(func.count())
        .select_from(Receipt)
    )

    return db.scalar(statement) or 0


def receipt_count_by_status(
    db: Session,
    status: str,
) -> int:

    statement = (
        select(func.count())
        .select_from(Receipt)
        .where(
            Receipt.status == status
        )
    )

    return db.scalar(statement) or 0


def receipts_for_school(
    db: Session,
    school_id: UUID,
) -> list[Receipt]:

    statement = (
        select(Receipt)
        .where(
            Receipt.school_id == school_id
        )
        .order_by(
            desc(Receipt.issued_at)
        )
    )

    return db.scalars(statement).all()


def receipts_for_customer(
    db: Session,
    customer_id: UUID,
) -> list[Receipt]:

    statement = (
        select(Receipt)
        .where(
            Receipt.customer_id == customer_id
        )
        .order_by(
            desc(Receipt.issued_at)
        )
    )

    return db.scalars(statement).all()