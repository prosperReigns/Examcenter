from fastapi import FastAPI

from .auth import router as auth_router
from .admins import router as admin_router
from .activations import router as activations_router
from .audit_logs import router as audit_logs_router
from .customers import router as customers_router
from .licenses import router as licenses_router
from .payments import router as payments_router
from .schools import router as schools_router
from .settings import router as settings_router
from .receipts import router as receipts_router
from .idempotency import router as idempotency_router
from .health import router as health_router
from .outbox import router as outbox_router
from .system import router as system_router
from .public import router as public_router
from .webhook import router as webhook_router
from .activation import router as activation_router
from .devices import router as devices_router
from .purchase_sessions import router as purchase_sessions_router
from .license_renewals import router as license_renewals_router
from .invoices import router as invoices_router
from .notifications import router as notification_router
from .public_activation import router as public_activation_router


def register_routes(app: FastAPI) -> None:
    app.include_router(auth_router)
    app.include_router(devices_router)
    app.include_router(activations_router)
    app.include_router(audit_logs_router)
    app.include_router(customers_router)
    app.include_router(licenses_router)
    app.include_router(payments_router)
    app.include_router(purchase_sessions_router)
    app.include_router(schools_router)
    app.include_router(settings_router)
    app.include_router(activation_router)
    app.include_router(license_renewals_router)
    app.include_router(invoices_router)
    app.include_router(receipts_router)
    app.include_router(notification_router)
    app.include_router(outbox_router)
    app.include_router(health_router)
    app.include_router(system_router)
    app.include_router(public_router)
    app.include_router(idempotency_router)
    app.include_router(admin_router)
    app.include_router(webhook_router)
    app.include_router(public_activation_router)


__all__ = [
    "activation_router",
    "admin_router",
    "activations_router",
    "admins_router",
    "audit_logs_router",
    "auth_router",
    "customers_router",
    "devices_router",
    "idempotency_router",
    "invoices_router",
    "license_renewals_router",
    "licenses_router",
    "notifications_router",
    "outbox_router",
    "payments_router",
    "purchase_sessions_router",
    "receipts_router",
    "schools_router",
    "settings_router",
    "health_router",
    "outbox_router",
    "notification_router",
    "idempotency_router",
    "system_router",
    "webhook_router",
    "public_router",
    "public_activation_router"
]
