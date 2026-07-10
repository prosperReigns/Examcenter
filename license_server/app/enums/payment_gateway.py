from enum import Enum


class PaymentGateway(str, Enum):

    FLUTTERWAVE = "flutterwave"

    PAYSTACK = "paystack"

    MONNIFY = "monnify"

    MANUAL = "manual"