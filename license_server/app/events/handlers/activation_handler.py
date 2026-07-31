from app.services.activation_token_service import (
    ActivationTokenService,
)


class ActivationHandler:

    def __init__(self, db):

        self.service = ActivationTokenService(
            db
        )

    def handle(
        self,
        event,
    ):

        self.service.create_for_purchase(

            purchase_session_id=event.purchase_session_id

        )