from fastapi import APIRouter, Depends, HTTPException, Request

from sqlalchemy.orm import Session

from app.database.session import get_db
from app.repositories.activation_token_repository import get_active_for_purchase, get_by_id
from app.repositories.purchase_session_repository import get_purchase_session_by_reference
from app.services.checkout_service import CheckoutService

from sqlalchemy import select
from app.models.activation_token import ActivationToken
from app.utils.time import utcnow


try:
    from slowapi import Limiter
    from slowapi.util import get_remote_address

    limiter = Limiter(key_func=get_remote_address)
except Exception:
    class _NoopLimiter:
        def limit(self, *args, **kwargs):
            def _decorator(func):
                return func

            return _decorator

    limiter = _NoopLimiter()


router = APIRouter(prefix="/api/public/payment-status", tags=["Public Payment Status"])


@limiter.limit("5/minute")
@router.get("/{checkout_token}")
def payment_status(request: Request,checkout_token: str, db: Session = Depends(get_db)):
    checkout = CheckoutService(db)
    session = checkout.get_checkout_session(checkout_token)

    print("Checkout payment_reference:", session.payment_reference)

    purchase = get_purchase_session_by_reference(db, session.payment_reference)

    if purchase is None:
        raise HTTPException(
            status_code=404,
            detail="Purchase session not found."
        )

    print("Purchase ID:", purchase.id)
    print("Payment reference:", purchase.payment_reference)


    print("Purchase activation_token_id:", purchase.activation_token_id)

    token = None

    if purchase.activation_token_id:
        token = get_by_id(
            db,
            purchase.activation_token_id
        )

    print("Activation token:", token)

    token_available = False
    token_consumed = False

    if token is not None:

        token_consumed = token.used_at is not None

        if (
            token.used_at is None
            and token.revoked_at is None
            and token.expires_at >= utcnow()
        ):
            token_available = True

    response = {
        "purchase_id": str(purchase.id),
        "checkout_token": checkout_token,
        "status": purchase.status,
        "completed": purchase.completed,

        "payment_reference":
            purchase.payment_reference,

        "payment_verified":
            purchase.status in (
                "payment_verified",
                "customer_created",
                "school_created",
                "license_created",
                "invoice_created",
                "payment_recorded",
                "device_registered",
                "activated",
                "receipt_created",
                "completed",
            ),

        "license_ready":
            purchase.license_id is not None,

        "activation_ready":
            token_available,

        "activation_consumed":
            token_consumed,

        "expires_at":
            purchase.expires_at,
    }

    if token is not None and token.used_at is None:
        response["activation_token"] = token.token

    return response
