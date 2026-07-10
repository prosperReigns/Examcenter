from app.events.bus import event_bus

from app.events.event_types import (
    PaymentVerifiedEvent,
)

from app.events.handlers.payment_handlers import (
    renew_license_handler,
)

from app.events.handlers.receipt_handlers import (
    generate_receipt_handler,
)

from app.events.handlers.notification_handlers import (
    notify_customer_handler,
)

from app.events.handlers.analytics_handlers import (
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