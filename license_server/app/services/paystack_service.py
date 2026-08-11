from __future__ import annotations

from fastapi import HTTPException, Request, status

from app.core.config import get_settings
from app.gateways.factory import PaymentGatewayFactory

settings = get_settings()


class PaystackService:
    """
    High-level wrapper around the Paystack gateway.

    This service hides Paystack-specific implementation details from the
    rest of the application and normalizes responses into a consistent
    format that can later be shared with Flutterwave.
    """

    def __init__(self):
        self.gateway = PaymentGatewayFactory.create()

    def initialize_payment(
        self,
        invoice,
        customer,
    ) -> dict:

        payload = {
            "email": customer.email,
            "amount": int(invoice.amount * 100),  # Kobo
            "currency": invoice.currency,
            "reference": invoice.invoice_number,
            "callback_url": settings.payment_callback_url,
            "metadata": {
                "customer_id": str(customer.id),
                "invoice_id": str(invoice.id),
                "school_id": str(invoice.school_id),
            },
        }

        response = self.gateway.initialize_payment(payload)

        if not response.get("status"):
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail=response.get(
                    "message",
                    "Unable to initialize payment.",
                ),
            )

        data = response.get("data", {})

        return {
            "gateway": "paystack",
            "reference": data.get("reference"),
            "authorization_url": data.get("authorization_url"),
            "access_code": data.get("access_code"),
            "raw": response,
        }

    def verify_payment(
        self,
        reference: str,
    ) -> dict:

        response = self.gateway.verify_payment(reference)

        if not response.get("status"):
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail=response.get(
                    "message",
                    "Payment verification failed.",
                ),
            )

        data = response.get("data", {})

        return {
            "gateway": "paystack",
            "reference": data.get("reference"),
            "transaction_id": data.get("id"),
            "status": data.get("status"),
            "amount": data.get("amount", 0) / 100,
            "currency": data.get("currency"),
            "paid_at": data.get("paid_at"),
            "customer_email": (
                data.get("customer", {}).get("email")
            ),
            "raw": response,
        }

    def refund_payment(
        self,
        reference: str,
        amount: float | None = None,
    ) -> dict:

        refund_amount = (
            int(amount * 100)
            if amount is not None
            else None
        )

        response = self.gateway.refund_payment(
            reference=reference,
            amount=refund_amount,
        )

        if not response.get("status"):
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail=response.get(
                    "message",
                    "Refund failed.",
                ),
            )

        return {
            "gateway": "paystack",
            "status": "refunded",
            "raw": response,
        }

    def webhook_signature_is_valid(
        self,
        request: Request,
        payload: bytes,
    ) -> bool:

        return self.gateway.webhook_signature_is_valid(
            request=request,
            payload=payload,
        )