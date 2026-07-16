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

def create_payment_record(
    db: Session,
    *,
    customer_id: UUID,
    school_id: UUID | None,
    invoice_id: UUID,
    payment_reference: str,
    gateway: str | None,
    amount,
    currency: str,
    payment_type: str,
    status: str = "pending",
    gateway_reference: str | None = None,
    gateway_transaction_id: str | None = None,
    gateway_payment_url: str | None = None,
    payment_method: str | None = None,
    raw_payload: str | None = None,
) -> Payment:
    payment = Payment(
        customer_id=customer_id,
        school_id=school_id,
        invoice_id=invoice_id,
        amount=amount,
        currency=currency,
        status=status,
        payment_type=payment_type,
        payment_reference=payment_reference,
        gateway=gateway or "manual",
        gateway_reference=gateway_reference,
        gateway_transaction_id=gateway_transaction_id,
        gateway_payment_url=gateway_payment_url,
        payment_method=payment_method,
        raw_payload=raw_payload,
    )
    return create_payment(db, payment)


def get_payment_by_transaction_id(
    db: Session,
    transaction_id: str,
) -> Payment | None:
    return get_payment_by_gateway_transaction_id(db, transaction_id)


def get_payment_by_tx_ref(
    db: Session,
    tx_ref: str,
) -> Payment | None:
    return get_payment_by_reference(db, tx_ref)


def get_payment_by_id(
    db: Session,
    payment_id: UUID,
) -> Payment | None:
    return get_payment(db, payment_id)
