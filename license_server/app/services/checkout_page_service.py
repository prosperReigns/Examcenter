from fastapi import HTTPException
from sqlalchemy.orm import Session

from app.repositories.purchase_session_repository import PurchaseSessionRepository


def get_checkout_page(
    db: Session,
    checkout_token: str,
):

    purchase = PurchaseSessionRepository.get_checkout_session(
        db,
        checkout_token,
    )

    if purchase is None:

        raise HTTPException(
            status_code=404,
            detail="Checkout session not found.",
        )

    if purchase.completed:

        raise HTTPException(
            status_code=400,
            detail="Purchase already completed.",
        )

    if purchase.checkout_url is None:

        raise HTTPException(
            status_code=400,
            detail="Checkout unavailable.",
        )

    return purchase