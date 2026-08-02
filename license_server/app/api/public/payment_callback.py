from fastapi import (
    APIRouter,
    Depends,
    HTTPException,
    Query,
    Request,
)
from fastapi.response import RedirectResponse
from sqlalchemy.orm import Session

from app.database.session import get_db

from app.services.payment_initialization_service import (
    PaymentInitializationService,
)

from app.services.checkout_service import (
    CheckoutService,
)

from app.web.templates import templates

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

router = APIRouter(

    prefix="/api/public/payment-callback",

    tags=["Public Payment-callback"],

)

@limiter.limit(
    "5/minute"
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