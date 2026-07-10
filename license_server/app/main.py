from pathlib import Path


from fastapi import Depends, FastAPI, Form, Request
from fastapi.responses import HTMLResponse, RedirectResponse
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates
from starlette.middleware.sessions import SessionMiddleware

from app.api.routes import register_routes


from app.core.config import get_settings
from app.auth.security import get_password_hash
from app.database.session import SessionLocal
from app.database.session import engine



from app.repositories.admin_repository import get_admin_count, create_admin


from app.schemas.setting import SettingUpsert


from app.services.audit_service import record_audit_event




from app.utils.flash import flash, pop_flashes



BASE_DIR = Path(__file__).resolve().parent
TEMPLATES_DIR = BASE_DIR / "templates"
STATIC_DIR = BASE_DIR / "static"

settings = get_settings()
app = FastAPI(title=settings.app_name, debug=settings.debug)
app.add_middleware(SessionMiddleware, secret_key=settings.secret_key, same_site=settings.access_token_cookie_samesite)
register_routes(app)


def template_context(request: Request) -> dict[str, object]:
    return {
        "request": request,
        "settings": settings,
        "messages": pop_flashes(request),
    }


templates = Jinja2Templates(directory=str(TEMPLATES_DIR), context_processors=[template_context])

app.mount("/static", StaticFiles(directory=str(STATIC_DIR)), name="static")


@app.get("/", response_class=HTMLResponse)
def root(request: Request) -> RedirectResponse:
    return RedirectResponse(url="/login", status_code=302)











@app.on_event("startup")
def on_startup() -> None:
    with SessionLocal() as db:
        if get_admin_count(db) == 0 and settings.bootstrap_admin_email and settings.bootstrap_admin_password and settings.bootstrap_admin_full_name:
            create_admin(
                db,
                full_name=settings.bootstrap_admin_full_name,
                email=settings.bootstrap_admin_email,
                password_hash=get_password_hash(settings.bootstrap_admin_password),
                role=settings.bootstrap_admin_role,
            )
            db.commit()




