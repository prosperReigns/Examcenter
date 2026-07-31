from app.tasks.notification_tasks import (
    queue_notification,
)


class NotificationHandler:

    def handle(
        self,
        event,
    ):

        queue_notification.delay(

            payment_id=event.payment_id

        )