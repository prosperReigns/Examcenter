from fastapi import HTTPException

def ensure_purchase_not_completed(
    purchase_session,
):
    """
    Prevent processing the same purchase twice.
    """

    if purchase_session.completed_at is not None:

        return False

    return True