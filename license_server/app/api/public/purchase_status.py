from fastapi import APIRouter
from fastapi import Depends
from fastapi import HTTPException

from sqlalchemy.orm import Session

from app.database.session import get_db

from app.repositories.purchase_session_repository import (
    PurchaseSessionRepository,
)

from app.repositories.activation_token_repository import (
    ActivationTokenRepository,
)

from app.core.purchase_progress import (
    get_purchase_progress,
    get_purchase_message,
    get_retry_after,
)

from app.core.purchase_messages import (
    get_purchase_message,
)

router = APIRouter(
    prefix="/api/public/purchase",
    tags=["Public Purchase"],
)


@router.get("/{checkout_token}")
def purchase_status(
    checkout_token: str,
    db: Session = Depends(get_db),
):

    purchase_repo = PurchaseSessionRepository(db)

    token_repo = ActivationTokenRepository(db)

    purchase = purchase_repo.get_by_checkout_token(
        checkout_token
    )

    if purchase is None:

        raise HTTPException(
            status_code=404,
            detail="Purchase not found.",
        )

    #
    # Completed
    #

    if purchase.status == "completed":

        token = token_repo.get_active_for_purchase(
            purchase.id
        )

        if token is None:

            return {

                "status": purchase.status,

                "activation_token": None,

                "download_url": None,

            }

        return {

            "status": "completed",

            "progress": 100,

            "message": "Completed.",

            "retry_after": 0,

            "activation_token": token_repo.generate_download_nonce(
                db,
                token,
            ),

            "download_url":
                f"/api/public/license/download/{checkout_token}",

        }

    #
    # Failed
    #

    if purchase.status == "failed":

        return {

            "status": purchase.status,

            "progress":
                get_purchase_progress(
                    purchase.status
                ),

            "message":
                get_purchase_message(
                    purchase.status
                ),

            "retry_after":
                get_retry_after(
                    purchase.status
                ),

        }

    #
    # Cancelled
    #

    if purchase.status == "cancelled":

        return {

            "status": purchase.status,

            "progress":
                get_purchase_progress(
                    purchase.status
                ),

            "message":
                get_purchase_message(
                    purchase.status
                ),

            "retry_after":
                get_retry_after(
                    purchase.status
                ),

        }
    #
    # Everything else
    #

    return {

        "status": purchase.status,

        "progress":
            get_purchase_progress(
                purchase.status
            ),

        "message":
            get_purchase_message(
                purchase.status
            ),

        "retry_after":
            get_retry_after(
                purchase.status
            ),

    }