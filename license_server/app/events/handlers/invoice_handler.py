from app.services.invoice_service import InvoiceService


class InvoiceHandler:

    def __init__(self, db):

        self.service = InvoiceService(db)

    def handle(
        self,
        event,
    ):

        self.service.create_from_payment(

            payment_id=event.payment_id,

            customer_id=event.customer_id,

        )