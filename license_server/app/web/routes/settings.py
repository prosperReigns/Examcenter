from uuid import UUID

from fastapi import APIRouter, Depends, Request, Form
from fastapi.responses import HTMLResponse, RedirectResponse
from app.schemas.setting import SettingUpsert
from app.database.session import SessionLocal

from app.auth.dependencies import require_roles
from app.services.setting_service import get_settings_list, upsert_setting_record
from app.utils.flash import flash

from app.web.templates import templates
from app.core.config import get_settings

settings = get_settings()
router = APIRouter(
    prefix="/settings",
    tags=["Settings Pages"],
)


@router.get("/settings", response_class=HTMLResponse)
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


@router.post("/settings")
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
