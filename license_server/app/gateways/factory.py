from app.core.config import get_settings

from app.gateways.flutterwave import FlutterwaveGateway
from app.gateways.paystack import PaystackGateway

settings = get_settings()


def get_gateway():

    if settings.payment_gateway.lower() == "paystack":

        return PaystackGateway(

            secret_key=settings.paystack_secret_key,

            base_url=settings.paystack_base_url,

            webhook_secret=settings.paystack_webhook_secret,

        )

    return FlutterwaveGateway(

        secret_key=settings.flutterwave_secret_key,

        base_url=settings.flutterwave_base_url,

    )