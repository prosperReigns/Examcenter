from dataclasses import dataclass
from decimal import Decimal

from sqlalchemy.orm import Session

from app.models.purchase_session import PurchaseSession
from app.services.purchase_orchestration_service import complete_purchase as orchestrate_purchase


@dataclass
class PurchaseContext:
    fingerprint: str
    product_code: str
    version: str
    plan: str
    school_name: str
    customer_name: str
    customer_email: str
    customer_phone: str | None
    payment_reference: str
    gateway: str | None
    amount: Decimal
    currency: str


def complete_purchase(db: Session, context: PurchaseContext | PurchaseSession):
    if isinstance(context, PurchaseSession):
        return orchestrate_purchase(db, context)

    purchase_session = PurchaseSession(
        fingerprint=context.fingerprint,
        product_code=context.product_code,
        version=context.version,
        plan_code=context.plan,
        duration_months=12,
        amount=context.amount,
        currency=context.currency,
        customer_name=context.customer_name,
        customer_email=context.customer_email,
        customer_phone=context.customer_phone,
        school_name=context.school_name,
        payment_reference=context.payment_reference,
        gateway=context.gateway,
    )
    db.add(purchase_session)
    db.flush()
    return orchestrate_purchase(db, purchase_session)
