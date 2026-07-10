from fastapi import FastAPI

from .dashboard import router as dashboard_router
from .licenses import router as licenses_router
from .activations import router as activations_router
from .admins import router as admins_router
from .customers import router as customers_router
from .schools import router as schools_router


def register_web_routes(app: FastAPI):

    app.include_router(dashboard_router)

    app.include_router(admins_router)

    app.include_router(customers_router)

    app.include_router(schools_router)

    app.include_router(licenses_router)

    app.include_router(activations_router)