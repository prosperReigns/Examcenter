from fastapi import FastAPI
from .payments import router as payments_router
from .checkout import router as checkout_router
from .purchase import router as purchase_router
from .pricing import router as pricing_router
from .plans import router as plans_router
from .payment_status import router as payment_status_router
from .license import router as license_router



def register_public_routes(app: FastAPI) -> None:
    app.include_router(payments_router)
    app.include_router(checkout_router)
    app.include_router(purchase_router)
    app.include_router(pricing_router)
    app.include_router(plans_router)
    app.include_router(payment_status_router)
    app.include_router(license_router)