from __future__ import annotations

import requests
from app.core.config import settings
from app.gateways.base import PaymentGateway


class FlutterwaveGateway(PaymentGateway):
    def __init__(
        self,
        secret_key: str,
        base_url: str,
    ):
        self.secret_key = secret_key
        self.base_url = base_url

    @property
    def headers(self):
        return {
            "Authorization": f"Bearer {self.secret_key}",
            "Content-Type": "application/json",
        }

    def initialize_payment(
        self,
        payload,
    ):
        response = requests.post(
            f"{self.base_url}/payments",
            json=payload,
            headers=self.headers,
            timeout=30,
        )

        response.raise_for_status()
        return response.json()

    def verify_payment(
        self,
        transaction_id,
    ):
        response = requests.get(
            f"{self.base_url}/transactions/{transaction_id}/verify",
            headers=self.headers,
            timeout=30,
        )

        response.raise_for_status()
        return response.json()

    def refund_payment(
        self,
        transaction_id,
        amount=None,
    ):

        payload = {
            "id": transaction_id,
        }

        if amount is not None:
            payload["amount"] = amount

        response = requests.post(
            f"{self.base_url}/transactions/{transaction_id}/refund",
            json=payload,
            headers=self.headers,
            timeout=30,
        )

        response.raise_for_status()
        return response.json()

    def webhook_signature_is_valid(
        self,
        request,
        payload: bytes | None = None,
    ):

        signature = request.headers.get(
            "verif-hash"
        )

        return (
            signature is not None
            and signature == settings.flutterwave_hash
        )