from fastapi import (
    APIRouter,
    Depends,
)
from fastapi import Request
from sqlalchemy.orm import Session

from app.database.session import get_db

from app.services.checkout_service import CheckoutService
from fastapi.responses import JSONResponse
from app.services.checkout_page_service import (
    get_checkout_page,
)

from app.web.templates import templates

router = APIRouter(
    prefix="/api/public/checkout",
    tags=["Public Checkout"],
)

@limiter.limit(
    "5/minute"
)

@router.get("/{checkout_token}")
def get_checkout(
    request: Request,
    checkout_token: str,
    db: Session = Depends(get_db),
):
    purchase = get_checkout_page(

        db,

        checkout_token,

    )

    return templates.TemplateResponse(

        "public/checkout.html",

        {

            "request": request,

            "checkout_url": purchase.checkout_url,

            "purchase": purchase,

        },

    )


@router.get("/{checkout_token}/validate")
def validate_checkout(
    checkout_token: str,
    db: Session = Depends(get_db),
):
    """
    Validate a checkout token without
    returning all purchase information.
    """

    service = CheckoutService(db)

    session = service.validate_checkout_session(
        checkout_token
    )

    return {

        "valid": True,

        "purchase_id": session.id,

        "status": session.status,

        "payment_status": session.payment_status,

        "expires_at": session.expires_at,

    }


@router.get("/{checkout_token}/summary")
def purchase_summary(
    checkout_token: str,
    db: Session = Depends(get_db),
):
    """
    Lightweight purchase summary
    used by the checkout page.
    """

    service = CheckoutService(db)

    session = service.checkout_view_model(
        checkout_token
    )

    return {

        "product":

            session["product_code"],

        "version":

            session["version"],

        "plan":

            session["plan_code"],

        "price":

            session["price"],

        "currency":

            session["currency"],

        "duration":

            session["duration_months"],

        "school":

            session["school_name"],

        "status":

            session["status"],

        "expires_at":

            session["expires_at"],

    }

@router.get("/{checkout_token}/status")
def purchase_status(
    checkout_token: str,
    db: Session = Depends(get_db),
):
    """
    Lightweight polling endpoint used
    by the desktop application.

    This endpoint intentionally returns
    only the current purchase status.
    """

    service = CheckoutService(db)

    return service.purchase_status(
        checkout_token
    )