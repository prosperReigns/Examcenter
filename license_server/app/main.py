from pathlib import Path
from datetime import timedelta

from fastapi import Depends, FastAPI, Form, Request
from fastapi.responses import HTMLResponse, RedirectResponse
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates
from sqlalchemy import text
from starlette.middleware.sessions import SessionMiddleware

from app.api.routes.auth import router as auth_router
from app.api.routes.activations import router as activations_router
from app.api.routes.audit_logs import router as audit_logs_router
from app.api.routes.customers import router as customers_router
from app.api.routes.licenses import router as licenses_router
from app.api.routes.payments import router as payments_router
from app.api.routes.schools import router as schools_router
from app.api.routes.settings import router as settings_router
from app.auth.dependencies import require_roles
from app.core.config import get_settings
from app.auth.security import get_password_hash
from app.database.session import SessionLocal
from app.database.session import engine
from app.repositories.activation_repository import list_activations
from app.repositories.customer_repository import list_customers
from app.repositories.dashboard_repository import get_dashboard_stats
from app.repositories.admin_repository import get_admin_count, create_admin
from app.repositories.license_repository import list_licenses
from app.repositories.payment_repository import list_payments
from app.repositories.school_repository import list_schools
from app.schemas.setting import SettingUpsert
from app.services.audit_log_service import get_audit_logs
from app.services.audit_service import record_audit_event
from app.services.auth_service import authenticate_admin, issue_admin_token
from app.services.setting_service import get_settings_list, upsert_setting_record
from app.utils.flash import flash, pop_flashes

BASE_DIR = Path(__file__).resolve().parent
TEMPLATES_DIR = BASE_DIR / "templates"
STATIC_DIR = BASE_DIR / "static"

settings = get_settings()
app = FastAPI(title=settings.app_name, debug=settings.debug)
app.add_middleware(SessionMiddleware, secret_key=settings.secret_key, same_site=settings.access_token_cookie_samesite)


def template_context(request: Request) -> dict[str, object]:
    return {
        "request": request,
        "settings": settings,
        "messages": pop_flashes(request),
    }


templates = Jinja2Templates(directory=str(TEMPLATES_DIR), context_processors=[template_context])

app.mount("/static", StaticFiles(directory=str(STATIC_DIR)), name="static")
app.include_router(auth_router)
app.include_router(activations_router)
app.include_router(audit_logs_router)
app.include_router(customers_router)
app.include_router(licenses_router)
app.include_router(payments_router)
app.include_router(schools_router)
app.include_router(settings_router)


@app.get("/", response_class=HTMLResponse)
def root(request: Request) -> RedirectResponse:
    return RedirectResponse(url="/login", status_code=302)


@app.get("/login", response_class=HTMLResponse)
def login_page(request: Request) -> HTMLResponse:
    return templates.TemplateResponse("login.html", {"request": request, "settings": settings, "title": "Admin Login"})


@app.post("/login")
def login_submit(request: Request, email: str = Form(...), password: str = Form(...), remember_me: bool = Form(False)) -> RedirectResponse:
    with SessionLocal() as db:
        admin = authenticate_admin(db, email=email, password=password)
        if admin is None:
            flash(request, "Invalid email or password.", "danger")
            return RedirectResponse(url="/login?error=1", status_code=303)

        token = issue_admin_token(
            admin,
            expires_delta=timedelta(seconds=settings.remember_me_max_age_seconds if remember_me else settings.access_token_cookie_max_age_seconds),
        )

    flash(request, f"Welcome back, {admin.full_name}.", "success")
    response = RedirectResponse(url="/dashboard", status_code=303)
    response.set_cookie(
        key=settings.access_token_cookie_name,
        value=token,
        httponly=True,
        secure=settings.access_token_cookie_secure,
        samesite=settings.access_token_cookie_samesite,
        max_age=settings.remember_me_max_age_seconds if remember_me else settings.access_token_cookie_max_age_seconds,
        path="/",
    )
    with SessionLocal() as db:
        record_audit_event(
            db,
            admin=admin,
            action="admin_login",
            entity_type="admin",
            entity_id=str(admin.id),
            description=f"Admin {admin.email} signed in",
            ip_address=request.client.host if request.client else None,
            user_agent=request.headers.get("user-agent"),
        )
        db.commit()
    return response


@app.get("/dashboard", response_class=HTMLResponse)
def dashboard(request: Request, admin=Depends(require_roles("Super Admin", "Staff"))) -> HTMLResponse:
    with SessionLocal() as db:
        admin_count = get_admin_count(db)
        stats = get_dashboard_stats(db)
    return templates.TemplateResponse(
        "dashboard.html",
        {
            "request": request,
            "settings": settings,
            "title": "Dashboard",
            "admin": admin,
            "admin_count": admin_count,
            "stats": stats,
        },
    )


@app.get("/settings", response_class=HTMLResponse)
def settings_page(request: Request, admin=Depends(require_roles("Super Admin"))) -> HTMLResponse:
    with SessionLocal() as db:
        settings_list = get_settings_list(db)
    return templates.TemplateResponse(
        "settings.html",
        {
            "request": request,
            "settings": settings,
            "title": "Settings",
            "admin": admin,
            "settings_list": settings_list,
        },
    )


@app.post("/settings")
def settings_submit(
    request: Request,
    key: str = Form(...),
    value: str = Form(...),
    category: str | None = Form(None),
    description: str | None = Form(None),
    is_system: bool = Form(False),
    admin=Depends(require_roles("Super Admin")),
) -> RedirectResponse:
    with SessionLocal() as db:
        upsert_setting_record(
            db,
            SettingUpsert(key=key, value=value, category=category, description=description, is_system=is_system),
            admin=admin,
            request=request,
        )
    flash(request, f"Setting {key} saved.", "success")
    return RedirectResponse(url="/settings", status_code=303)


@app.get("/audit-logs", response_class=HTMLResponse)
def audit_logs_page(request: Request, admin=Depends(require_roles("Super Admin"))) -> HTMLResponse:
    with SessionLocal() as db:
        audit_logs, _ = get_audit_logs(db, page=1, page_size=50)
    return templates.TemplateResponse(
        "audit_logs.html",
        {
            "request": request,
            "settings": settings,
            "title": "Audit Logs",
            "admin": admin,
            "audit_logs": audit_logs,
        },
    )


@app.get("/customers", response_class=HTMLResponse)
def customers_page(request: Request, admin=Depends(require_roles("Super Admin", "Staff"))) -> HTMLResponse:
    with SessionLocal() as db:
        customers, _ = list_customers(db, offset=0, limit=100)
    return templates.TemplateResponse(
        "customers.html",
        {
            "request": request,
            "settings": settings,
            "title": "Customers",
            "admin": admin,
            "customers": customers,
        },
    )


@app.get("/schools", response_class=HTMLResponse)
def schools_page(request: Request, admin=Depends(require_roles("Super Admin", "Staff"))) -> HTMLResponse:
    with SessionLocal() as db:
        schools, _ = list_schools(db, offset=0, limit=100)
    return templates.TemplateResponse(
        "schools.html",
        {
            "request": request,
            "settings": settings,
            "title": "Schools",
            "admin": admin,
            "schools": schools,
        },
    )


@app.get("/licenses", response_class=HTMLResponse)
def licenses_page(request: Request, admin=Depends(require_roles("Super Admin", "Staff"))) -> HTMLResponse:
    with SessionLocal() as db:
        licenses, _ = list_licenses(db, offset=0, limit=100)
    return templates.TemplateResponse(
        "licenses.html",
        {
            "request": request,
            "settings": settings,
            "title": "Licenses",
            "admin": admin,
            "licenses": licenses,
        },
    )


@app.get("/payments", response_class=HTMLResponse)
def payments_page(request: Request, admin=Depends(require_roles("Super Admin", "Staff"))) -> HTMLResponse:
    with SessionLocal() as db:
        payments, _ = list_payments(db, offset=0, limit=100)
    return templates.TemplateResponse(
        "payments.html",
        {
            "request": request,
            "settings": settings,
            "title": "Payments",
            "admin": admin,
            "payments": payments,
        },
    )


@app.get("/activations", response_class=HTMLResponse)
def activations_page(request: Request, admin=Depends(require_roles("Super Admin", "Staff"))) -> HTMLResponse:
    with SessionLocal() as db:
        activations, _ = list_activations(db, offset=0, limit=100)
    return templates.TemplateResponse(
        "activations.html",
        {
            "request": request,
            "settings": settings,
            "title": "Activations",
            "admin": admin,
            "activations": activations,
        },
    )


@app.post("/logout")
def logout(request: Request) -> RedirectResponse:
    flash(request, "You have been signed out.", "info")
    response = RedirectResponse(url="/login", status_code=303)
    response.delete_cookie(key=settings.access_token_cookie_name, path="/")
    return response


@app.get("/health")
def health_check() -> dict[str, str]:
    with engine.connect() as connection:
        connection.execute(text("SELECT 1"))
    return {"status": "ok"}


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
