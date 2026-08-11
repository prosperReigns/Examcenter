from datetime import datetime, timezone

from fastapi import HTTPException

from sqlalchemy.orm import Session

from app.repositories.purchase_session_repository import (
    get_purchase_session_by_poll_token,
)
from app.repositories.license_repository import ( 
    get_license_by_id,
)

class PurchasePollService:
    """
    Public polling service used by:

    - Desktop application
    - Browser success page

    It exposes only safe information about the
    purchase progress.
    """

    STATUS_MAP = {

        "pending": (
            "pending",
            5,
            "Waiting for payment.",
        ),

        "payment_pending": (
            "waiting_payment",
            10,
            "Waiting for payment confirmation.",
        ),

        "payment_verified": (
            "processing",
            20,
            "Payment verified.",
        ),

        "customer_created": (
            "processing",
            35,
            "Creating customer.",
        ),

        "school_created": (
            "processing",
            50,
            "Creating school.",
        ),

        "license_created": (
            "processing",
            70,
            "Generating license.",
        ),

        "invoice_created": (
            "processing",
            80,
            "Generating invoice.",
        ),

        "payment_recorded": (
            "processing",
            85,
            "Recording payment.",
        ),

        "device_registered": (
            "processing",
            90,
            "Registering device.",
        ),

        "activated": (
            "processing",
            95,
            "Activating license.",
        ),

        "receipt_created": (
            "processing",
            98,
            "Finalizing purchase.",
        ),

        "completed": (
            "completed",
            100,
            "License ready.",
        ),

        "failed": (
            "failed",
            0,
            "Purchase failed.",
        ),

        "cancelled": (
            "cancelled",
            0,
            "Purchase cancelled.",
        ),

        "expired": (
            "expired",
            0,
            "Purchase expired.",
        ),
    }

    def __init__(self, db: Session):

        self.db = db

    def get_status(
        self,
        poll_token: str,
    ):

        purchase = get_purchase_session_by_poll_token(
            self.db,
            poll_token,
        )

        if purchase is None:

            raise HTTPException(
                status_code=404,
                detail="Purchase not found.",
            )

        #
        # Poll token expired
        #

        if purchase.expires_at < datetime.now(
            timezone.utc
        ):

            raise HTTPException(
                status_code=410,
                detail="Poll token expired.",
            )

        public_status, progress, message = (
            self.STATUS_MAP.get(
                purchase.status,
                (
                    "processing",
                    50,
                    "Processing purchase.",
                ),
            )
        )

        
        # Recommend polling interval
        #

        if public_status == "completed":

            poll_after = 0

        elif public_status in (

            "failed",
            "cancelled",
            "expired",
        ):

            poll_after = 0

        elif progress < 20:

            poll_after = 2

        elif progress < 90:

            poll_after = 3

        else:

            poll_after = 5

        etag = (
            f'{purchase.status}:'
            f'{purchase.updated_at.timestamp()}'
        )

        result = {

            "status": public_status,

            "progress": progress,

            "message": message,

            "download_ready": purchase.completed,

            "poll_after": poll_after,

            "server_time": datetime.now(
                timezone.utc
            ).isoformat(),

            "etag": etag,

            "last_modified":
                purchase.updated_at,

        }

        if purchase.completed and purchase.license_id is not None:

            license_record = get_license_by_id(
                self.db,
                purchase.license_id,
            )

            if license_record is not None:

                result["license"] = (
                    license_record.signed_license
                )

            else:

                result["license"] = None

        else:

            result["license"] = None

        return result