from sqlalchemy.orm import Session

from app.events.event_bus import event_bus

from app.events.payment_completed import (
    PaymentCompletedEvent,
)

from app.repositories.payment_repository import (
    PaymentRepository,
)

from app.repositories.purchase_session_repository import (
    PurchaseSessionRepository,
)

from app.gateways.factory import (
    PaymentGatewayFactory,
)


class PaymentCompletionService:

    def __init__(
        self,
        db: Session,
    ):

        self.db = db

        self.gateway = (
            PaymentGatewayFactory.create()
        )

        self.payment_repo = (
            PaymentRepository(db)
        )

        self.purchase_repo = (
            PurchaseSessionRepository(db)
        )

    def complete(
        self,
        reference: str,
    ):

        payment = (
            self.payment_repo.get_by_reference(
                reference
            )
        )

        if payment is None:

            raise ValueError(
                "Payment not found."
            )

        if payment.status == "paid":

            return payment

        verification = self.gateway.verify(
            reference
        )

        if not verification["status"]:

            payment.status = "failed"

            self.db.commit()

            return payment

        payment.status = "paid"

        purchase = payment.purchase_session

        purchase.status = "completed"

        purchase.payment_status = "paid"

        self.db.commit()

        event = PaymentCompletedEvent(

            payment_id=payment.id,

            purchase_session_id=purchase.id,

            customer_id=purchase.customer_id,

            reference=reference,

            amount=payment.amount,

            currency=payment.currency,

            provider=payment.provider,

        )

        event_bus.publish(
            event
        )

        return payment