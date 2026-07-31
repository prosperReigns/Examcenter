from app.services.receipt_service import ReceiptService


class ReceiptHandler:

    def __init__(self, db):

        self.service = ReceiptService(db)

    def handle(
        self,
        event,
    ):

        self.service.create_from_payment(
            event.payment_id
        )