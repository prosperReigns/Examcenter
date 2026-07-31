from app.events.event_bus import event_bus

from app.events.payment_completed import (
    PaymentCompletedEvent,
)

from app.events.handlers.invoice_handler import (
    InvoiceHandler,
)

from app.events.handlers.receipt_handler import (
    ReceiptHandler,
)

from app.events.handlers.activation_handler import (
    ActivationHandler,
)

from app.events.handlers.license_handler import (
    LicenseHandler,
)

from app.events.handlers.activation_token_handler import (
    ActivationTokenHandler,
)

from app.events.handlers.notification_handler import (
    NotificationHandler,
)

from app.events.handlers.analytics_handler import (
    AnalyticsHandler,
)

from app.events.handlers.audit_handler import (
    AuditHandler,
)

def register_handlers(db):

    event_bus.subscribe(

        PaymentCompletedEvent,

        InvoiceHandler(db),

    )

    event_bus.subscribe(

        PaymentCompletedEvent,

        ReceiptHandler(db),

    )

    event_bus.subscribe(

        PaymentCompletedEvent,

        LicenseHandler(db),

    )

    event_bus.subscribe(

        PaymentCompletedEvent,

        ActivationHandler(db),

    )

    event_bus.subscribe(

        PaymentCompletedEvent,

        ActivationTokenHandler(),

    )

    event_bus.subscribe(

        PaymentCompletedEvent,

        NotificationHandler(),

    )

    event_bus.subscribe(

        PaymentCompletedEvent,

        AnalyticsHandler(),

    )

    event_bus.subscribe(

        PaymentCompletedEvent,

        AuditHandler(),

    )