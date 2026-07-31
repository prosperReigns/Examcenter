import secrets
import logging
from datetime import datetime,  timezone

from sqlalchemy.orm import Session

from fastapi import HTTPException

from app.repositories.payment_repository import PaymentRepository

from app.repositories.payment_repository import get_by_idempotency_key

from app.repositories.purchase_session_repository import PurchaseSessionRepository

from app.services.checkout_service import CheckoutService

from app.gateways.factory import PaymentGatewayFactory

from app.models.payment import Payment

from app.core.config import settings

logger = logging.getLogger(__name__)
class PaymentInitializationService:

    def __init__(
        self,
        db: Session,
    ):

        self.db = db

        self.payment_repo = PaymentRepository(db)

        self.purchase_repo = PurchaseSessionRepository(db)

        self.checkout_service = CheckoutService(db)

        self.gateway = PaymentGatewayFactory.create()

    def initialize_payment(
        self,
        checkout_token: str,
    ):


        if idempotency_key:
            existing = (
                self.payment_repo.get_by_idempotency_key(
                    idempotency_key
                )
            )

            if existing:

                return {

                    "payment_id": existing.id,

                    "reference": existing.reference,

                    "authorization_url":
                        existing.authorization_url,

                }
    
        purchase = self.checkout_service.validate_checkout_session(
            checkout_token
        )

        existing = self.payment_repo.get_pending_by_purchase_session(
            purchase.id
        )

        if existing:

            return {

                "payment_id": existing.id,

                "provider": existing.provider,

                "reference": existing.reference,

                "authorization_url":
                    existing.authorization_url,

            }

        reference = (
            "KTS-"
            + datetime.now(timezone.utc).strftime("%Y%m%d")
            + "-"
            + secrets.token_hex(5).upper()
        )

        payment = Payment(

            purchase_session_id=purchase.id,

            reference=reference,

            provider=settings.PAYMENT_PROVIDER,

            # Production:
            # Provider comes from configuration
            # to allow Flutterwave, Paystack,
            # or future gateways without code changes.

            amount=purchase.plan.price,

            currency=purchase.plan.currency,

            status="pending",
        )

        if not purchase.customer_email:

            raise HTTPException(
                status_code=400,
                detail="Customer email is required."
            )

        payment = self.payment_repo.create(
            payment
        )

        logger.info(

            "payment_initialized",

            extra={

                "purchase_id": purchase.id,

                "payment_id": payment.id,

                "reference": payment.reference,

                "provider": payment.provider,

                "amount": payment.amount,

            },

        )
        
        last_error = None

        for attempt in range(3):
            try:

                gateway_response = self.gateway.initialize(

                    amount=payment.amount,

                    currency=payment.currency,

                    email=purchase.customer_email,

                    reference=reference,

                    callback_url=f"{settings.PUBLIC_BASE_URL}/api/public/payment/callback",

                    metadata={

                        "purchase_id": purchase.id,

                        "payment_id": payment.id,

                    },

                )

                break

            except Exception as exc:

                last_error = exc

        else:

            raise HTTPException(

                status_code=503,

                detail="Unable to initialize payment.",

            ) from last_error

        self.payment_repo.update_gateway_response(

            payment,

            gateway_response,

        )

        purchase.payment_reference = payment.reference

        purchase.gateway = payment.provider

        authorization_data = gateway_response.get("data", {})

        purchase.gateway_reference = authorization_data.get(
            "flw_ref"
        )

        purchase.gateway_response = str(gateway_response)

        self.purchase_repo.save(
            purchase
        )

        purchase.status = "payment_pending"

        purchase.payment_reference = payment.reference

        self.purchase_repo.save(
            purchase
        )

        payment.authorization_url = (

            gateway_response["data"]["authorization_url"]

        )

        self.db.commit()

        if existing.authorization_url:

            return {

                "payment_id": existing.id,

                "provider": existing.provider,

                "reference": existing.reference,

                "authorization_url":

                    existing.authorization_url,

            }
        
        return {

            "payment_id": str(payment.id),

            "checkout_token": checkout_token,

            "purchase_id": str(purchase.id),

            "payment_reference": payment.reference,

            "provider": payment.provider,

            "authorization_url":
                gateway_response["data"]["authorization_url"],

            "expires_at":
                purchase.expires_at,

        }