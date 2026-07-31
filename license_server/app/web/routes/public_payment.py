from fastapi import APIRouter, Depends

from fastapi.responses import RedirectResponse

from sqlalchemy.orm import Session

from app.database.session import get_db

from app.services.payment_initialization_service import (
    PaymentInitializationService,
)

router = APIRouter()


@router.post(
    "/activation/{checkout_token}/payment"
)
def payment_redirect(
    checkout_token: str,
    db: Session = Depends(get_db),
):

    service = PaymentInitializationService(
        db
    )

    payment = service.initialize_payment(
        checkout_token
    )

    return RedirectResponse(

        url=payment["authorization_url"],

        status_code=302,

    )