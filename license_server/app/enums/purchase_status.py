from enum import StrEnum


class PurchaseStatus(StrEnum):
    PENDING = "pending"
    PAYMENT_PENDING = "payment_pending"
    PAYMENT_VERIFIED = "payment_verified"
    CUSTOMER_CREATED = "customer_created"
    SCHOOL_CREATED = "school_created"
    LICENSE_CREATED = "license_created"
    INVOICE_CREATED = "invoice_created"
    PAYMENT_RECORDED = "payment_recorded"
    DEVICE_REGISTERED = "device_registered"
    ACTIVATED = "activated"
    RECEIPT_CREATED = "receipt_created"
    COMPLETED = "completed"
    CANCELLED = "cancelled"
    FAILED = "failed"
    EXPIRED = "expired"
