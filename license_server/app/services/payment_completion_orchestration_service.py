from sqlalchemy.orm import Session

from app.events.event_bus import event_bus
from app.events.payment_completed import PaymentCompletedEvent

from app.gateways.factory import PaymentGatewayFactory

from app.repositories.payment_repository import get_payment_by_reference

from app.services.customer_service import create_from_purchase


class PaymentCompletionOrchestrationService:

    def __init__(
        self,
        db: Session,
    ):

        self.db = db
        self.gateway = PaymentGatewayFactory.create()


    def complete_payment(
        self,
        reference: str,
    ):

        #
        # Load payment
        #

        payment = get_payment_by_reference(
            self.db,
            reference
        )

        if payment is None:

            raise ValueError(
                "Payment not found."
            )

        #
        # Idempotency
        #

        if payment.status == "paid":

            return payment

        #
        # Verify with gateway
        #

        verification = self.gateway.verify(
            reference
        )

        if not verification["status"]:

            payment.status = "failed"

            self.db.commit()

            return payment

        gateway_data = verification["data"]

        #
        # Mark payment
        #

        payment.status = "paid"

        payment.gateway_reference = gateway_data.get(
            "reference"
        )

        payment.gateway_response = gateway_data

        #
        # Purchase
        #

        purchase = payment.purchase_session

        purchase.payment_status = "paid"

        purchase.status = "paid"

        #
        # Customer
        #

        customer = purchase.customer

        if customer is None:

            customer =  create_from_purchase(
                self.db,
                purchase
            )

            purchase.customer_id = customer.id

        #
        # Commit database first
        #

        self.db.commit()

        #
        # Publish event
        #

        event = PaymentCompletedEvent(

            payment_id=payment.id,

            purchase_session_id=purchase.id,

            customer_id=customer.id,

            provider=payment.provider,

            amount=payment.amount,

            currency=payment.currency,

            reference=payment.reference,

        )

        event_bus.publish(
            event
        )

        return payment