from fastapi import HTTPException

from app.enums.purchase_status import PurchaseStatus

ALLOWED_TRANSITIONS = {

    PurchaseStatus.PENDING: {

        PurchaseStatus.PAYMENT_PENDING,

        PurchaseStatus.PAYMENT_VERIFIED,

        PurchaseStatus.CANCELLED,

        PurchaseStatus.EXPIRED,

        PurchaseStatus.FAILED,

    },

    PurchaseStatus.PAYMENT_PENDING: {

        PurchaseStatus.PAYMENT_VERIFIED,

        PurchaseStatus.CANCELLED,

        PurchaseStatus.EXPIRED,

        PurchaseStatus.FAILED,

    },

    PurchaseStatus.PAYMENT_VERIFIED: {

        PurchaseStatus.CUSTOMER_CREATED,

        PurchaseStatus.FAILED,

    },

    PurchaseStatus.CUSTOMER_CREATED: {

        PurchaseStatus.SCHOOL_CREATED,

        PurchaseStatus.FAILED,

    },

    PurchaseStatus.SCHOOL_CREATED: {

        PurchaseStatus.LICENSE_CREATED,

        PurchaseStatus.FAILED,

    },

    PurchaseStatus.LICENSE_CREATED: {

        PurchaseStatus.INVOICE_CREATED,

        PurchaseStatus.FAILED,

    },

    PurchaseStatus.INVOICE_CREATED: {

        PurchaseStatus.PAYMENT_RECORDED,

        PurchaseStatus.DEVICE_REGISTERED,

        PurchaseStatus.FAILED,

    },

    PurchaseStatus.PAYMENT_RECORDED: {

        PurchaseStatus.DEVICE_REGISTERED,

        PurchaseStatus.FAILED,

    },

    PurchaseStatus.DEVICE_REGISTERED: {

        PurchaseStatus.ACTIVATED,

        PurchaseStatus.FAILED,

    },

    PurchaseStatus.ACTIVATED: {

        PurchaseStatus.RECEIPT_CREATED,

        PurchaseStatus.FAILED,

    },

    PurchaseStatus.RECEIPT_CREATED: {

        PurchaseStatus.COMPLETED,

        PurchaseStatus.FAILED,

    },

    PurchaseStatus.COMPLETED: set(),

    PurchaseStatus.CANCELLED: set(),

    PurchaseStatus.EXPIRED: set(),

    PurchaseStatus.FAILED: {

        PurchaseStatus.PAYMENT_VERIFIED,

    },

}

def validate_transition(
    current_status: PurchaseStatus | str,
    next_status: PurchaseStatus,
) -> None:
    """
    Ensure a purchase only moves through
    valid lifecycle states.
    """

    current = PurchaseStatus(current_status)

    if current == next_status:
        return

    allowed = ALLOWED_TRANSITIONS.get(
        current,
        set(),
    )

    if next_status not in allowed:

        raise HTTPException(
            status_code=400,
            detail=(
                f"Invalid purchase transition "
                f"{current.value} -> "
                f"{next_status.value}"
            ),
        )

def transition_purchase(
    purchase,
    next_status: PurchaseStatus,
):
    """
    Update purchase status after validation.
    """

    validate_transition(
        purchase.status,
        next_status,
    )

    purchase.status = next_status.value

    return purchase
