from app.tasks.analytics_tasks import (
    update_sales_analytics,
)


class AnalyticsHandler:

    def handle(
        self,
        event,
    ):

        update_sales_analytics.delay(

            payment_id=event.payment_id

        )