from app.core.config import settings
from app.services.paystack_service import PaystackService
from fastapi import HTTPException, status

from app.gateways.factory import get_gateway

class FlutterwaveService:
    def __init__(self):
        self.gateway = get_gateway()

    def initialize_payment(
        self,
        invoice,
        customer,
    ):
            payload = {
                "tx_ref": invoice.invoice_number,
                "amount": invoice.amount,
                "currency": invoice.currency,
                "redirect_url": settings.PAYMENT_CALLBACK_URL,
                "customer": {
                    "email": customer.email,
                    "name": customer.name,
                    "phonenumber": customer.phone,
                },

                "customizations": {
                    "title": "Seed of Abraham License Renewal",
                    "description": invoice.description,
                },
            }

            return self.gateway.initialize_payment(
                payload,
            )
    
    def verify_payment(
        self,
        transaction_id: str,
    ):

        try:

            return self.gateway.verify_payment(
                transaction_id,
            )

        except Exception as exc:

            raise HTTPException(
                status_code=status.HTTP_502_BAD_GATEWAY,
                detail=f"Flutterwave verification failed: {exc}",
            )
        
    def refund_payment(
        self,
        transaction_id: str,
        amount: float | None = None,
    ):

        try:

            return self.gateway.refund_payment(
                transaction_id,
                amount,
            )

        except Exception as exc:

            raise HTTPException(
                status_code=status.HTTP_502_BAD_GATEWAY,
                detail=f"Refund failed: {exc}",
            )
        
    def webhook_signature_is_valid(
        self,
        request,
    ):

        return self.gateway.webhook_signature_is_valid(
            request,
        )

class PaymentGatewayService:
    def __init__(self):
        if settings.PAYMENT_GATEWAY == "flutterwave":
            self.gateway = FlutterwaveService()
        else:
            self.gateway = PaystackService()

    def initialize_payment(
        self,
        invoice,
        customer,
    ):
        return self.gateway.initialize_payment(
            invoice,
            customer,
        )