from uuid import UUID

from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.models.payment import Payment


def get_payment_by_id(db: Session, payment_id: UUID) -> Payment | None:
    return db.get(Payment, payment_id)


def get_payment_by_tx_ref(db: Session, tx_ref: str) -> Payment | None:
    statement = select(Payment).where(Payment.flutterwave_tx_ref == tx_ref)
    return db.scalar(statement)


def get_payment_by_transaction_id(db: Session, transaction_id: str) -> Payment | None:
    statement = select(Payment).where(Payment.flutterwave_transaction_id == transaction_id)
    return db.scalar(statement)


def list_payments(db: Session, *, offset: int = 0, limit: int = 20) -> tuple[list[Payment], int]:
    statement = select(Payment)
    count_statement = select(func.count()).select_from(Payment)
    total = db.scalar(count_statement) or 0
    items = db.scalars(statement.order_by(Payment.created_at.desc()).offset(offset).limit(limit)).all()
    return items, total


def create_payment_record(
    db: Session,
    *,
    customer_id: UUID,
    school_id: UUID | None,
    flutterwave_tx_ref: str,
    amount: int,
    currency: str,
    payment_type: str,
    status: str = "pending",
) -> Payment:
    payment = Payment(
        customer_id=customer_id,
        school_id=school_id,
        flutterwave_tx_ref=flutterwave_tx_ref,
        amount=amount,
        currency=currency,
        payment_type=payment_type,
        status=status,
    )
    db.add(payment)
    db.flush()
    return payment


def persist_payment(db: Session, payment: Payment) -> Payment:
    db.add(payment)
    db.flush()
    return payment
