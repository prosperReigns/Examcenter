PURCHASE_MESSAGES = {

    "pending":
        "Preparing your purchase...",

    "payment_pending":
        "Waiting for payment...",

    "payment_verified":
        "Payment received.",

    "customer_created":
        "Creating customer...",

    "school_created":
        "Creating school...",

    "invoice_created":
        "Generating invoice...",

    "payment_recorded":
        "Recording payment...",

    "license_created":
        "Generating license...",

    "activated":
        "Preparing activation...",

    "completed":
        "Done.",

    "failed":
        "Purchase failed.",

    "cancelled":
        "Purchase cancelled.",

    "expired":
        "Purchase expired.",

}

def get_purchase_message(status: str) -> str:

    return PURCHASE_MESSAGES.get(
        status,
        status,
    )