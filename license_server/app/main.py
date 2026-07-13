from pathlib import Path

from fastapi import FastAPI
from fastapi.staticfiles import StaticFiles

from starlette.middleware.sessions import SessionMiddleware


from app.api.routes import register_routes
from app.web.routes import register_web_routes
from app.web.templates import templates
from app.core.startup import bootstrap_application
from app.core.config import get_settings
from app.database.session import SessionLocal

BASE_DIR = Path(__file__).resolve().parent

STATIC_DIR = BASE_DIR / "static"

settings = get_settings()
app = FastAPI(title=settings.app_name, debug=settings.debug)
app.add_middleware(SessionMiddleware, secret_key=settings.secret_key, same_site=settings.access_token_cookie_samesite)
register_routes(app)
register_web_routes(app)
print("\n========== REGISTERED ROUTES ==========")

for route in app.routes:
    methods = ",".join(route.methods) if hasattr(route, "methods") else ""
    print(f"{methods:15} {route.path}")

print("=======================================\n")

app.mount("/static", StaticFiles(directory=str(STATIC_DIR)), name="static")

@app.on_event("startup")
def startup():

    bootstrap_application()