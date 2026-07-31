from fastapi import FastAPI
from .purchase_status import router as purchase_status_router
from app.api.public.payments import router as payments_router
from .checkout import router as checkout_router
from .license_download import router as license_router
from .purchase import router as purchase_router
from .pricing import router as pricing_router

def register_routes(app: FastAPI) -> None:
    app.include_router(purchase_status_router)
    app.include_router(payments_router)
    app.include_router(checkout_router)
    app.include_router(license_router)
    app.include_router(purchase_router)
    app.include_router(pricing_router)