from fastapi import (
    APIRouter,
    Depends,
    Request,
)

from fastapi.responses import RedirectResponse

from fastapi.templating import Jinja2Templates

from sqlalchemy.orm import Session

from app.database.session import get_db

from app.services.checkout_service import CheckoutService

router = APIRouter(
    tags=["Public Activation"],
)

templates = Jinja2Templates(
    directory="app/templates"
)


@router.get(
    "/activation/{checkout_token}"
)
def activation_checkout(
    request: Request,
    checkout_token: str,
    db: Session = Depends(get_db),
):
    """
    Display the checkout page.
    """

    service = CheckoutService(db)

    purchase = service.checkout_view_model(
        checkout_token
    )

    return templates.TemplateResponse(

        "activation/checkout.html",

        {

            "request": request,

            "purchase": purchase,

        },

    )


@router.get(
    "/activation/{checkout_token}/refresh"
)
def refresh_checkout(
    request: Request,
    checkout_token: str,
    db: Session = Depends(get_db),
):
    """
    Refresh the checkout page.
    """

    service = CheckoutService(db)

    purchase = service.checkout_view_model(
        checkout_token
    )

    return templates.TemplateResponse(

        "activation/checkout.html",

        {

            "request": request,

            "purchase": purchase,

        },

    )


@router.get(
    "/activation/{checkout_token}/expired"
)
def checkout_expired(
    request: Request,
    checkout_token: str,
):
    """
    Checkout expired page.
    """

    return templates.TemplateResponse(

        "activation/expired.html",

        {

            "request": request,

            "checkout_token": checkout_token,

        },

    )


@router.get(
    "/activation/{checkout_token}/cancel"
)
def cancel_checkout(
    checkout_token: str,
):
    """
    Customer cancelled checkout.
    """

    return RedirectResponse(
        url=f"/activation/{checkout_token}"
    )