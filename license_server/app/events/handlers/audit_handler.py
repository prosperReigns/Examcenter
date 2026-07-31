from app.tasks.audit_tasks import (
    create_audit_log_task,
)


class AuditHandler:

    def handle(
        self,
        event,
    ):

        create_audit_log_task.delay(

            payment_id=event.payment_id

        )