from app.events.bus import event_bus

from app.events.event_types import (
    PaymentVerifiedEvent,
)

from app.events.handlers.payment_handlers import (
    renew_license_handler,
)

from license_server.app.events.handlers.receipt_handler import (
    generate_receipt_handler,
)

from license_server.app.events.handlers.notification_handler import (
    notify_customer_handler,
)

from license_server.app.events.handlers.analytics_handler import (
    analytics_handler,
)

event_bus.subscribe(

    PaymentVerifiedEvent,

    renew_license_handler,

)

event_bus.subscribe(

    PaymentVerifiedEvent,

    generate_receipt_handler,

)

event_bus.subscribe(

    PaymentVerifiedEvent,

    notify_customer_handler,

)

event_bus.subscribe(

    PaymentVerifiedEvent,

    analytics_handler,

)