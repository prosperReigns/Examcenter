from uuid import UUID

from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.models.customer import Customer

def get_customer_by_id(db: Session, customer_id: UUID) -> Customer | None:
    return db.get(Customer, customer_id)


def get_customer_by_email(db: Session, email: str) -> Customer | None:
    statement = select(Customer).where(Customer.email == email.lower().strip(), Customer.deleted_at.is_(None))
    return db.scalar(statement)


def list_customers(db: Session, *, search: str | None = None, offset: int = 0, limit: int = 20) -> tuple[list[Customer], int]:
    statement = select(Customer).where(Customer.deleted_at.is_(None))
    count_statement = select(func.count()).select_from(Customer).where(Customer.deleted_at.is_(None))

    if search:
        term = f"%{search.strip()}%"
        statement = statement.where(Customer.name.ilike(term))
        count_statement = count_statement.where(Customer.name.ilike(term))

    total = db.scalar(count_statement) or 0
    items = db.scalars(statement.order_by(Customer.created_at.desc()).offset(offset).limit(limit)).all()
    return items, total


def create_customer(db: Session, *, name: str, email: str | None, phone: str | None, country: str | None, is_active: bool) -> Customer:
    customer = Customer(
        name=name.strip(),
        email=email.lower().strip() if email else None,
        phone=phone.strip() if phone else None,
        country=country.strip() if country else None,
        is_active=is_active,
    )
    db.add(customer)
    db.flush()
    return customer


def soft_delete_customer(db: Session, customer: Customer) -> Customer:
    customer.deleted_at = func.now()
    customer.is_active = False
    db.add(customer)
    db.flush()
    return customer
