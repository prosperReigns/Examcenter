from sqlalchemy.orm import Session

from app.services.payment_completion_orchestration_service import PaymentCompletionOrchestrationService


class PaymentCompletionService:

    def __init__(
        self,
        db: Session,
    ):

        self.db = db
        self._orchestration = PaymentCompletionOrchestrationService(db)

    def complete(
        self,
        reference: str,
    ):
        return self._orchestration.complete_payment(reference)