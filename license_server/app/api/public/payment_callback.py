from fastapi import (
    APIRouter,
    Depends,
    HTTPException,
    Query,
    Request,
)

from sqlalchemy.orm import Session

from app.database.session import get_db

from app.services.payment_initialization_service import (
    PaymentInitializationService,
)

from app.services.checkout_service import (
    CheckoutService,
)

from app.web.templates import templates

router = APIRouter(

    prefix="/api/public/payment-callback",

    tags=["Public Payment-callback"],

)

@router.get("")
def payment_callback(
    tx_ref: str,
    transaction_id: str | None = None,
    status: str | None = None,
):
    """
    Browser callback.

    Never trust browser parameters.

    The webhook performs the real verification.
    """

    return RedirectResponse(
        url=f"/checkout/status/{tx_ref}"
    )