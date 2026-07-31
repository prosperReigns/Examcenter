from app.tasks.activation_token_tasks import (
    generate_activation_token_task,
)


class ActivationTokenHandler:

    def handle(
        self,
        event,
    ):

        generate_activation_token_task.delay(

            purchase_session_id=

            event.purchase_session_id

        )