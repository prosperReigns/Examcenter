from datetime import datetime, timezone
from fastapi import APIRouter, Depends, Query, HTTPException

from sqlalchemy.orm import Session

from app.database.session import get_db

from app.utils.rate_limit import limiter

from app.repositories.activation_token_repository import (
    consume_download_token,
    get_valid_download_token,
    create_download_token,
    mark_used,
    get_by_token,
    ActivationTokenRepository,
)

from app.repositories.purchase_session_repository import PurchaseSessionRepository
from app.repositories.purchase_session_repository import (
    get_purchase_session_by_id,
)
from app.repositories.license_repository import (
    LicenseRepository,
)

from app.services.audit_service import record_audit_event

router = APIRouter(
    prefix="/api/public/license",
    tags=["Public License"],
)

@limiter.limit(
    "5/minute"
)

@router.post(
    "/request-download/{checkout_token}"
)
def request_download_token(
    checkout_token: str,
    db: Session = Depends(get_db),
):
    """
    Generates a one-time download token.

    Only available after purchase
    orchestration completes.
    """

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

    if not purchase.completed:

        raise HTTPException(
            status_code=409,
            detail="License is not ready yet.",
        )

    token = create_download_token(
        db,
        purchase_session=purchase,
    )

    return {

        "license_ready": True,

        "download_token": token.token,

        "expires_at": token.expires_at,

    }

@router.get(
    "/download/{download_token}"
)
def download_license(
    download_token: str,
    db: Session = Depends(get_db)
):
        #
    # Start one atomic transaction.
    #
    with db.begin():
        license_repo = LicenseRepository(db)

        token = get_valid_download_token(
            db,
            download_token
        )

        if token is None:

            raise HTTPException(
                status_code=403,
                detail="Download token is invalid or has expired.",
            )

        purchase = get_purchase_session_by_id(
            db,
            token.purchase_session_id,
        )

        if token.used_at is not None:
            raise HTTPException(
                status_code=410,
                detail="Download token already used.",
            )

        if token.revoked_at is not None:
            raise HTTPException(
                status_code=410,
                detail="Download token revoked.",
            )

        if token.expires_at < datetime.now(timezone.utc):

            raise HTTPException(
                status_code=410,
                detail="Download token expired.",
            )

        if purchase.status != "completed":

            raise HTTPException(
                status_code=400,
                detail="Purchase not completed.",
            )

        token = token.get_active_for_purchase(
            purchase.id
        )

        if token is None:

            raise HTTPException(
                status_code=403,
                detail="Activation token not found.",
            )

        if not token.is_valid(token):

            raise HTTPException(
                status_code=403,
                detail="Activation token expired.",
            )

        if not token.validate_download_nonce(
            token,
        ):
            raise HTTPException(
                status_code=403,
                detail="Invalid download nonce.",
            )

        license = (
            license_repo.get(
                token.license_id
            )
        )

        if license is None:

            raise HTTPException(
                status_code=404,
                detail="License not found.",
            )
        
        record_audit_event(

            db,

            action="license_downloaded",

            entity_type="license",

            entity_id=str(license.id),

            description="Customer downloaded license.",

        )
        
        token.consume_download_nonce(
            db,
            token,
        )
        
        token.mark_used(db, token)

        #
        # Consume the token BEFORE returning the
        # signed license.
        #
        # This guarantees one-time downloads even
        # if the client retries immediately.
        #
        consume_download_token(
            db,
            token,
        )

        return {

            "license": license.signed_license,

            "issued_at": license.issued_at,

            "expires_at": license.expires_at,

        }