from enum import Enum


class PaymentStatus(str, Enum):

    PENDING = "pending"

    PROCESSING = "processing"

    SUCCESS = "success"

    FAILED = "failed"

    CANCELLED = "cancelled"

    REFUNDED = "refunded"

    EXPIRED = "expired"