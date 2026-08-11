from __future__ import annotations

import logging
import secrets
import json
from datetime import datetime, timezone

from fastapi import HTTPException
from sqlalchemy.orm import Session

from app.core.config import settings
from app.gateways.factory import PaymentGatewayFactory

from app.repositories.purchase_session_repository import PurchaseSessionRepository
from app.services.checkout_service import CheckoutService

logger = logging.getLogger(__name__)


class PaymentInitializationService:
    def __init__(self, db: Session):
        self.db = db
        self.purchase_repo = PurchaseSessionRepository(db)
        self.checkout_service = CheckoutService(db)
        self.gateway = PaymentGatewayFactory.create()

    def initialize_payment(self, checkout_token: str):
        purchase = self.checkout_service.validate_checkout_session(checkout_token)

        customer_email = purchase.customer_email or f"{purchase.id.hex[:24]}@local.invalid"

        if purchase.payment_reference:
            reference = purchase.payment_reference
        else:
            reference = (
                "KTS-"
                + datetime.now(timezone.utc).strftime("%Y%m%d")
                + "-"
                + secrets.token_hex(5).upper()
            )
            purchase.payment_reference = reference

        gateway_response = None
        last_error = None
        for _ in range(3):
            try:
                logger.info(type(self.gateway))
                logger.info(dir(self.gateway))

                gateway_response = self.gateway.initialize(
                    amount=float(purchase.amount),
                    currency=purchase.currency,
                    email=customer_email,
                    reference=reference,
                    callback_url=settings.payment_callback_url,
                    metadata={
                        "purchase_id": str(purchase.id),
                    },
                )
                logger.info("Flutterwave response: %s", gateway_response)
                print(json.dumps(gateway_response, indent=2))
                break
            except Exception as exc:
                logger.exception(
                    "Flutterwave initialization failed (attempt %s)",
                    _ + 1,
                )
                last_error = exc
        else:
            raise HTTPException(status_code=503, detail="Unable to initialize purchase.") from last_error

        purchase.gateway = settings.payment_gateway

        purchase.gateway_reference = (
            gateway_response.get("data", {})
            .get("flw_ref")
        )

        purchase.gateway_response = json.dumps(
            gateway_response
        )

        purchase.status = "payment_pending"

        self.purchase_repo.save(purchase)


        self.db.commit()

        logger.info(
            "payment_initialized",
            extra={
                "purchase_id": purchase.id,
                "payment_id": purchase
                .id,
                "reference": purchase.payment_reference,
                "provider": purchase.gateway,
                "amount": purchase.amount,
            },
        )

        return {
            "payment_id": str(purchase.id),
            "checkout_token": checkout_token,
            "purchase_id": str(purchase.id),
            "payment_reference": purchase.payment_reference,
            "provider": purchase.gateway,
            "authorization_url": gateway_response["data"]["link"],
        }
