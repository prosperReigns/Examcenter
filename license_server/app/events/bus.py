from collections import defaultdict

class EventBus:

    def __init__(self):

        self._handlers = defaultdict(list)

    def subscribe(
        self,
        event_type,
        handler,
    ):

        self._handlers[event_type].append(
            handler
        )

    def publish(
        self,
        event,
    ):

        handlers = self._handlers.get(
            type(event),
            [],
        )

        for handler in handlers:

            handler(event)