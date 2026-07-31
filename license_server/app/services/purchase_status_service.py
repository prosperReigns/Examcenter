from fastapi import HTTPException
from sqlalchemy.orm import Session

from app.repositories.purchase_session_repository import (
    PurchaseSessionRepository,
)

from app.repositories.activation_token_repository import (
    ActivationTokenRepository,
)

from app.core.config import settings


class PurchaseStatusService:

    def __init__(
        self,
        db: Session,
    ):

        self.purchase_repo = PurchaseSessionRepository(db)

        self.token_repo = ActivationTokenRepository(db)

    def status(
        self,
        poll_token: str,
    ):

        purchase = self.purchase_repo.get_by_poll_token(
            poll_token
        )

        if purchase is None:

            raise HTTPException(
                status_code=404,
                detail="Purchase not found."
            )

        token = self.token_repo.get_active_token(
            purchase.id
        )

        response = {

            "purchase_number":
                purchase.purchase_number,

            "status":
                purchase.status,

            "payment_status":
                purchase.payment_status,

            "activation_token":
                None,

            "download_url":
                None,

            "expires_at":
                None,

            "message":
                None,

        }

        if token:

            response["activation_token"] = token.token

            response["expires_at"] = token.expires_at

            response["download_url"] = (

                f"{settings.PUBLIC_BASE_URL}"

                "/api/public/license/download/"

                f"{token.token}"

            )

        return response