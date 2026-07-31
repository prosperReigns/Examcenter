from fastapi import FastAPI

from .dashboard import router as dashboard_router
from .licenses import router as licenses_router
from .activations import router as activations_router
from .admins import router as admins_router
from .customers import router as customers_router
from .schools import router as schools_router
from .settings import router as settings_router
from .receipts import router as receipts_router
from .payments import router as payments_router
from .invoices import router as invoices_router
from .health import router as health_router
from .devices import router as devices_router
from .auth import router as auth_router
from .audit_logs import router as audit_logs_router
from .notifications import router as notifications_router
from .outbox import router as outbox_router
from .public_activation import router as public_activation_router

def register_web_routes(app: FastAPI):
    app.include_router(dashboard_router)
    app.include_router(admins_router)
    app.include_router(activations_router)
    app.include_router(customers_router)
    app.include_router(schools_router)
    app.include_router(licenses_router)
    app.include_router(settings_router)
    app.include_router(payments_router)
    app.include_router(invoices_router)
    app.include_router(health_router)
    app.include_router(auth_router)
    app.include_router(devices_router)
    app.include_router(receipts_router)
    app.include_router(audit_logs_router)
    app.include_router(notifications_router)
    app.include_router(outbox_router)
    app.include_router(public_activation_router)