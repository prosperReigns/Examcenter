from fastapi import (
    APIRouter,
    Depends,
    HTTPException,
    Query,
    Request,
)

from sqlalchemy.orm import Session
from fastapi.responses import RedirectResponse
from app.database.session import get_db

from app.services.payment_initialization_service import (
    PaymentInitializationService,
)

from app.repositories.purchase_session_repository import (
    PurchaseSessionRepository,
)
from app.services.checkout_service import (
    CheckoutService,
)

# rate limiting
try:
    from slowapi import Limiter
    from slowapi.util import get_remote_address
    limiter = Limiter(key_func=get_remote_address)
except Exception:
    # Fallback no-op limiter if slowapi is not available
    class _NoopLimiter:
        def limit(self, *args, **kwargs):
            def _decorator(func):
                return func
            return _decorator

    limiter = _NoopLimiter()
    
from app.web.templates import templates

router = APIRouter(

    prefix="/api/public/payments",

    tags=["Public Payment"],

)

@limiter.limit(
    "5/minute"
)

@router.post(
    "/{checkout_token}"
)
def initialize_payment(
    request: Request,
    checkout_token: str,
    db: Session = Depends(get_db),
):

    service = PaymentInitializationService(
        db
    )
    return service.initialize_payment(
        checkout_token
    )

@router.get("/callback")
def flutterwave_callback(
    request: Request,
    status: str | None = None,
    tx_ref: str | None = None,
    transaction_id: str | None = None,
    db: Session = Depends(get_db),
):
    """
    Browser callback.

    This endpoint never verifies payment.

    Verification is handled exclusively
    by the webhook.
    """

    payment_status = (status or "").lower()

    template = "payment_processing.html"

    if payment_status == "successful":

        repo = PurchaseSessionRepository(db)

        purchase = repo.get_by_payment_reference(tx_ref)

        if not purchase:
            raise HTTPException(
                status_code=404,
                detail="Purchase session not found.",
            )

        return RedirectResponse(
            url=(
                "http://127.0.0.1/Examcenter/license/waiting.php"
                f"?poll_token={purchase.poll_token}"
            )
        )

    elif payment_status in (

        "cancelled",

        "failed",
        "expired",

        "error",

    ):

        template = "payment_failed.html"

    return templates.TemplateResponse(

        template,

        {

            "request": request,

            "status": payment_status,

            "tx_ref": tx_ref,

            "transaction_id": transaction_id,

            "checkout_token": request.query_params.get(
                "checkout_token"
            ),

        },

    )

@router.get("/completed")
def payment_completed_page(
    request: Request,
    checkout_token: str,
):

    return templates.TemplateResponse(

        "payment_success.html",

        {

            "request": request,

            "status": "successful",

            "checkout_token": checkout_token,

        },

    )