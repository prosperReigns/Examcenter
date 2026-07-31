from fastapi import APIRouter
from fastapi import Depends
from fastapi import HTTPException

from sqlalchemy.orm import Session

from app.database.session import get_db

from app.services.checkout_service import CheckoutService

from app.repositories.purchase_session_repository import (
    get_purchase_session_by_reference,
)

from app.repositories.activation_token_repository import (
    get_active_for_purchase,
)

router = APIRouter(
    prefix="/api/public/payment-status",
    tags=["Public Payment Status"],
)


@router.get("/{checkout_token}")
def payment_status(
    checkout_token: str,
    db: Session = Depends(get_db),
):
    """
    Returns the current state of a purchase session.

    This endpoint is designed to be polled by
    the desktop application after the customer
    is redirected to Flutterwave.
    """

    checkout = CheckoutService(db)

    session = checkout.get_checkout_session(
        checkout_token
    )

    purchase = get_purchase_session_by_reference(
        db,
        session.payment_reference,
    )

    if purchase is None:

        raise HTTPException(
            status_code=404,
            detail="Purchase session not found.",
        )

    token = get_active_for_purchase(
        db,
        purchase.id,
    )

    response = {

        "purchase_id":
            str(purchase.id),

        "checkout_token":
            checkout_token,

        "status":
            purchase.status,

        "completed":
            purchase.completed,

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
            token is not None,

        "download_ready":
            token is not None
            and purchase.completed,

        "expires_at":
            purchase.expires_at,

    }

    if token is not None:

        response["activation_token"] = token.token

        response["download_url"] = (
            f"/api/public/license/download/"
            f"{token.token}"
        )

    return response



# @router.get("/{reference}")

# def payment_status(

#     reference: str,

#     db: Session = Depends(get_db),

# ):

#     payment = get_by_reference(

#         db,

#         reference,

#     )

#     if payment is None:

#         return {

#             "status": "not_found",

#         }

#     return {

#         "status": payment.status,

#         "paid_at": payment.paid_at,

#     }