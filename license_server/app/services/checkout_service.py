from datetime import datetime, timezone

from fastapi import HTTPException
from sqlalchemy.orm import Session

from app.repositories.purchase_session_repository import (
    PurchaseSessionRepository,
)
from app.enums.purchase_status import PurchaseStatus

class CheckoutService:
    """
    Handles retrieval and validation of purchase checkout sessions.
    """

    def __init__(self, db: Session):

        self.db = db

        self.purchase_repo = PurchaseSessionRepository(db)

    def get_checkout_session(
        self,
        checkout_token: str,
    ):
        """
        Load a purchase session using its checkout token.
        """

        session = self.purchase_repo.get_by_checkout_token(
            checkout_token
        )

        if not session:

            raise HTTPException(
                status_code=404,
                detail="Checkout session not found.",
            )

        return session

    def validate_checkout_session(
        self,
        checkout_token: str,
    ):
        """
        Validate a purchase session before allowing checkout.
        """

        session = self.get_checkout_session(
            checkout_token
        )

        #
        # Session expired
        #

        if (
            session.expires_at
            and session.expires_at
            < datetime.now(timezone.utc)
        ):

            raise HTTPException(
                status_code=410,
                detail="Checkout session has expired.",
            )

        #
        # Purchase cancelled
        #

        if session.status == "cancelled":

            raise HTTPException(
                status_code=400,
                detail="Purchase has been cancelled.",
            )

        #
        # Already completed
        #

        if session.status == PurchaseStatus.COMPLETED.value:

            raise HTTPException(
                status_code=400,
                detail="Purchase already completed.",
            )

        return session

    """
    Build the checkout page model.

    This is the only object returned
    to the browser before payment.

    It intentionally hides all internal
    database relationships.
    """
    def checkout_view_model(
        self,
        checkout_token: str,
    ):
        """
        Returns everything required by the HTML checkout page.
        """

        session = self.validate_checkout_session(
            checkout_token
        )

        return {

            "purchase_id": str(session.id),

            "checkout_token": session.checkout_token,

            "product_code": session.product_code,

            "version": session.version,

            "plan_code": session.plan_code,

            "duration_months": session.duration_months,

            "price": float(session.amount),

            "currency": session.currency,

            "customer_name": session.customer_name,

            "customer_email": session.customer_email,

            "school_name": session.school_name,

            "fingerprint": session.fingerprint,

            "status": session.status,

            "expires_at": session.expires_at,

        }

    def purchase_status(
        self,
        checkout_token: str,
    ):
        """
        Returns the current state of
        a purchase session for polling.
        """

        session = self.get_checkout_session(
            checkout_token
        )

        response = {

            "purchase_id":
                str(session.id),

            "status":
                session.status,

            "completed":
                session.completed,

            "payment_status":
                session.payment_status,

        }

        #
        # Only expose download readiness
        # after orchestration finishes.
        #

        if session.completed:

            response["license_ready"] = True

            response["poll_token"] = session.poll_token

        else:

            response["license_ready"] = False

        return response