from enum import Enum


class PaymentMethod(str, Enum):

    CARD = "card"

    BANK_TRANSFER = "bank_transfer"

    USSD = "ussd"

    MOBILE_MONEY = "mobile_money"

    CASH = "cash"

    POS = "pos"