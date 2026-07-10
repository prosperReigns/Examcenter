from uuid import UUID

from sqlalchemy import desc, func, select
from sqlalchemy.orm import Session

from app.models.payment import Payment


def create_payment(
    db: Session,
    payment: Payment,
) -> Payment:
    db.add(payment)
    db.flush()
    return payment


def persist_payment(
    db: Session,
    payment: Payment,
) -> Payment:
    db.add(payment)
    db.flush()
    return payment


def get_payment(
    db: Session,
    payment_id: UUID,
) -> Payment | None:
    return db.get(Payment, payment_id)


def get_payment_by_reference(
    db: Session,
    payment_reference: str,
) -> Payment | None:

    statement = (
        select(Payment)
        .where(
            Payment.payment_reference == payment_reference
        )
    )

    return db.scalar(statement)


def get_payment_by_gateway_reference(
    db: Session,
    gateway_reference: str,
) -> Payment | None:

    statement = (
        select(Payment)
        .where(
            Payment.gateway_reference == gateway_reference
        )
    )

    return db.scalar(statement)


def get_payment_by_gateway_transaction_id(
    db: Session,
    transaction_id: str,
) -> Payment | None:

    statement = (
        select(Payment)
        .where(
            Payment.gateway_transaction_id == transaction_id
        )
    )

    return db.scalar(statement)


def list_payments(
    db: Session,
    *,
    search: str | None = None,
    status: str | None = None,
    school_id: UUID | None = None,
    customer_id: UUID | None = None,
    invoice_id: UUID | None = None,
    gateway: str | None = None,
    offset: int = 0,
    limit: int = 20,
) -> tuple[list[Payment], int]:

    statement = select(Payment)
    count_statement = select(func.count()).select_from(Payment)

    if search:
        term = f"%{search.strip()}%"

        statement = statement.where(
            Payment.payment_reference.ilike(term)
        )

        count_statement = count_statement.where(
            Payment.payment_reference.ilike(term)
        )

    if status:
        statement = statement.where(
            Payment.status == status
        )

        count_statement = count_statement.where(
            Payment.status == status
        )

    if school_id:
        statement = statement.where(
            Payment.school_id == school_id
        )

        count_statement = count_statement.where(
            Payment.school_id == school_id
        )

    if customer_id:
        statement = statement.where(
            Payment.customer_id == customer_id
        )

        count_statement = count_statement.where(
            Payment.customer_id == customer_id
        )

    if invoice_id:
        statement = statement.where(
            Payment.invoice_id == invoice_id
        )

        count_statement = count_statement.where(
            Payment.invoice_id == invoice_id
        )

    if gateway:
        statement = statement.where(
            Payment.gateway == gateway
        )

        count_statement = count_statement.where(
            Payment.gateway == gateway
        )

    total = db.scalar(count_statement) or 0

    items = db.scalars(
        statement
        .order_by(desc(Payment.created_at))
        .offset(offset)
        .limit(limit)
    ).all()

    return items, total


def count_successful_payments(
    db: Session,
) -> int:

    statement = (
        select(func.count())
        .select_from(Payment)
        .where(
            Payment.status == "successful"
        )
    )

    return db.scalar(statement) or 0


def count_pending_payments(
    db: Session,
) -> int:

    statement = (
        select(func.count())
        .select_from(Payment)
        .where(
            Payment.status == "pending"
        )
    )

    return db.scalar(statement) or 0


def total_revenue(
    db: Session,
):

    statement = (
        select(
            func.coalesce(func.sum(Payment.amount), 0)
        )
        .where(
            Payment.status == "successful"
        )
    )

    return db.scalar(statement) or 0