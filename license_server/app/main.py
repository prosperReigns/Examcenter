from pathlib import Path

from fastapi import FastAPI
from fastapi.staticfiles import StaticFiles

from starlette.middleware.sessions import SessionMiddleware
from fastapi.middleware.cors import CORSMiddleware

from app.api.public import register_public_routes
from app.api.routes import register_routes
from app.web.routes import register_web_routes
from app.web.templates import templates
from app.core.startup import bootstrap_application, production_startup_checks
from app.core.config import get_settings
from app.database.session import SessionLocal

from slowapi import Limiter
from slowapi.util import get_remote_address
BASE_DIR = Path(__file__).resolve().parent

STATIC_DIR = BASE_DIR / "static"

settings = get_settings()
app = FastAPI(title=settings.app_name, debug=settings.debug)

limiter = Limiter(
    key_func=get_remote_address
)

app.state.limiter = limiter

app.add_middleware(SessionMiddleware, secret_key=settings.secret_key, same_site=settings.access_token_cookie_samesite)

app.add_middleware(
    CORSMiddleware,
    allow_origins=[
        "http://127.0.0.1",
        "http://localhost",
        "http://localhost:80",
    ],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

register_public_routes(app)
register_routes(app)
register_web_routes(app)
print("\n========== REGISTERED ROUTES ==========")

for route in app.routes:
    methods = ",".join(route.methods) if hasattr(route, "methods") else ""
    print(f"{methods:15} {route.path}")

print("=======================================\n")

print("Gateway:", settings.payment_gateway)
print("Secret:", settings.flutterwave_secret_key)
print("Public:", settings.flutterwave_public_key)

app.mount("/static", StaticFiles(directory=str(STATIC_DIR)), name="static")

@app.on_event("startup")
async def startup():
    bootstrap_application()
    await production_startup_checks()