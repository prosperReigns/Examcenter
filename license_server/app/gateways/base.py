from __future__ import annotations

from abc import ABC, abstractmethod


class PaymentGateway(ABC):

    @abstractmethod
    def initialize_payment(self, *args, **kwargs):
        ...

    @abstractmethod
    def verify_payment(self, *args, **kwargs):
        ...

    @abstractmethod
    def refund_payment(self, *args, **kwargs):
        ...

    @abstractmethod
    def webhook_signature_is_valid(self, *args, **kwargs):
        ...