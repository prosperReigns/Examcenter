from __future__ import annotations

import hmac

import requests

from app.gateways.base import PaymentGateway


class PaystackGateway(PaymentGateway):

    def __init__(
        self,
        secret_key: str,
        base_url: str,
        webhook_secret: str,
    ):
        self.secret_key = secret_key
        self.base_url = base_url
        self.webhook_secret = webhook_secret

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
            f"{self.base_url}/transaction/initialize",
            json=payload,
            headers=self.headers,
            timeout=30,
        )

        response.raise_for_status()

        return response.json()

    def verify_payment(
        self,
        reference,
    ):
        response = requests.get(
            f"{self.base_url}/transaction/verify/{reference}",
            headers=self.headers,
            timeout=30,
        )

        response.raise_for_status()

        return response.json()

    def refund_payment(
        self,
        reference,
        amount=None,
    ):
        payload = {
            "transaction": reference,
        }

        if amount is not None:
            payload["amount"] = amount

        response = requests.post(
            f"{self.base_url}/refund",
            json=payload,
            headers=self.headers,
            timeout=30,
        )

        response.raise_for_status()

        return response.json()

    def webhook_signature_is_valid(
        self,
        request,
        payload: bytes,
    ):

        signature = request.headers.get(
            "x-paystack-signature",
            "",
        )

        computed = hmac.new(
            self.webhook_secret.encode(),
            payload,
            digestmod="sha512",
        ).hexdigest()

        return hmac.compare_digest(
            signature,
            computed,
        )