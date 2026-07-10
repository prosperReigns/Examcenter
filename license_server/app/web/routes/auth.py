from datetime import timedelta

from fastapi import Depends, APIRouter, Form, Request
from fastapi.responses import HTMLResponse, RedirectResponse, Response, FileResponse
from app.database.session import SessionLocal

from app.services.audit_service import record_audit_event
from app.services.auth_service import authenticate_admin, issue_admin_token
from app.utils.flash import flash
from app.web.templates import templates
from app.core.config import get_settings

settings = get_settings()
router = APIRouter(
    prefix="/auth",
    tags=["Auth Pages"],
)


@router.get("/login", response_class=HTMLResponse)
def login_page(request: Request) -> HTMLResponse:
    return templates.TemplateResponse("login.html", {"request": request, "settings": settings, "title": "Admin Login"})


@router.post("/login")
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


@router.post("/logout")
def logout(request: Request) -> RedirectResponse:
    flash(request, "You have been signed out.", "info")
    response = RedirectResponse(url="/login", status_code=303)
    response.delete_cookie(key=settings.access_token_cookie_name, path="/")
    return response
