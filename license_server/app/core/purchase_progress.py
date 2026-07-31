from app.enums.purchase_status import PurchaseStatus


PURCHASE_STATES = {

    "pending": {
        "progress": 5,
        "message": "Preparing your purchase...",
        "retry_after": 3,
    },

    "payment_pending": {
        "progress": 15,
        "message": "Waiting for payment...",
        "retry_after": 5,
    },

    "payment_verified": {
        "progress": 30,
        "message": "Payment received.",
        "retry_after": 2,
    },

    "customer_created": {
        "progress": 45,
        "message": "Creating customer...",
        "retry_after": 2,
    },

    "school_created": {
        "progress": 55,
        "message": "Creating school...",
        "retry_after": 2,
    },

    "invoice_created": {
        "progress": 65,
        "message": "Generating invoice...",
        "retry_after": 2,
    },

    "payment_recorded": {
        "progress": 75,
        "message": "Recording payment...",
        "retry_after": 2,
    },

    "license_created": {
        "progress": 85,
        "message": "Generating license...",
        "retry_after": 1,
    },

    "activated": {
        "progress": 95,
        "message": "Preparing activation...",
        "retry_after": 1,
    },

    "completed": {
        "progress": 100,
        "message": "Completed.",
        "retry_after": 0,
    },

    "failed": {
        "progress": 0,
        "message": "Purchase failed.",
        "retry_after": 0,
    },

    "cancelled": {
        "progress": 0,
        "message": "Purchase cancelled.",
        "retry_after": 0,
    },

    "expired": {
        "progress": 0,
        "message": "Purchase expired.",
        "retry_after": 0,
    },

}


def get_purchase_state(status: str) -> dict:

    return PURCHASE_STATES.get(

        status,

        {

            "progress": 0,

            "message": status,

            "retry_after": 5,

        },

    )


def get_purchase_progress(status: str) -> int:

    return get_purchase_state(status)["progress"]


def get_purchase_message(status: str) -> str:

    return get_purchase_state(status)["message"]


def get_retry_after(status: str) -> int:

    return get_purchase_state(status)["retry_after"]