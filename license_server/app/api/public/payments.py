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

from app.services.checkout_service import (
    CheckoutService,
)

from app.web.templates import templates

router = APIRouter(

    prefix="/api/public/payment",

    tags=["Public Payment"],

)

@limiter.limit(
    "5/minute"
)

@router.post(
    "/{checkout_token}"
)
def initialize_payment(
    checkout_token: str,
    db: Session = Depends(get_db),
):

    service = PaymentInitializationService(
        db
    )

    idempotency_key = request.headers.get(
        "Idempotency-Key"
    )
    return service.initialize_payment(
        checkout_token
    )

# @router.get("/callback")
# def payment_callback(
#     request: Request,
#     checkout_token: str = Query(...),
#     status: str | None = Query(None),
#     tx_ref: str | None = Query(None),
#     transaction_id: str | None = Query(None),
#     db: Session = Depends(get_db),
# ):
#     """
#     Browser callback after Flutterwave redirects
#     the customer back to the application.

#     IMPORTANT:
#     This endpoint does NOT verify payment.

#     The webhook remains the source of truth.
#     """

#     checkout = CheckoutService(db)

#     try:

#         session = checkout.get_checkout_session(
#             checkout_token
#         )

#     except HTTPException:

#         raise HTTPException(
#             status_code=404,
#             detail="Invalid checkout session.",
#         )

#     return templates.TemplateResponse(

#         request,

#         "payment_pending.html",

#         {

#             "checkout_token": checkout_token,

#             "purchase_number":
#                 session.purchase_number,

#             "status":
#                 status,

#             "tx_ref":
#                 tx_ref,

#             "transaction_id":
#                 transaction_id,

#         },

#     )
@router.get("/callback")
def flutterwave_callback(
    request: Request,
    status: str | None = None,
    tx_ref: str | None = None,
    transaction_id: str | None = None,
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

        template = "payment_success.html"

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