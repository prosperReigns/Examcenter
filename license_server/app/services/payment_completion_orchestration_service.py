from sqlalchemy.orm import Session

from app.events.event_bus import event_bus
from app.events.payment_completed import PaymentCompletedEvent

from app.gateways.factory import PaymentGatewayFactory

from app.repositories.payment_repository import PaymentRepository
from app.repositories.purchase_session_repository import PurchaseSessionRepository
from app.repositories.customer_repository import CustomerRepository

from app.services.customer_service import CustomerService


class PaymentCompletionOrchestrationService:

    def __init__(
        self,
        db: Session,
    ):

        self.db = db

        self.gateway = PaymentGatewayFactory.create()

        self.payment_repo = PaymentRepository(db)

        self.purchase_repo = PurchaseSessionRepository(db)

        self.customer_repo = CustomerRepository(db)

        self.customer_service = CustomerService(db)

    def complete_payment(
        self,
        reference: str,
    ):

        #
        # Load payment
        #

        payment = self.payment_repo.get_by_reference(
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

            customer = self.customer_service.create_from_purchase(
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