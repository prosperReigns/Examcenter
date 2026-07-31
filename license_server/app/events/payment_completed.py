from dataclasses import dataclass
from decimal import Decimal


@dataclass(slots=True)
class PaymentCompletedEvent:

    payment_id: str

    purchase_session_id: str

    customer_id: str | None

    reference: str

    amount: Decimal

    currency: str

    provider: str