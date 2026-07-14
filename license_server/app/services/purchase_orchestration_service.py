from fastapi import HTTPException
from app.enums.purchase_status import PurchaseStatus

from app.services.purchase_state_machine import (
    transition_purchase,
)

def ensure_purchase_not_completed(
    purchase_session,
):
    """
    Prevent processing the same purchase twice.
    """

    if purchase_session.completed_at is not None:

        return False

    return True