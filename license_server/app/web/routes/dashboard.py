from fastapi import APIRouter, Depends, Request
from fastapi.responses import HTMLResponse
from sqlalchemy.orm import Session
from app.database.session import SessionLocal

from app.auth.dependencies import require_roles
from app.database.session import get_db

from app.services.license_management_service import (
    get_license_statistics,
)
from app.repositories.admin_repository import get_admin_count, create_admin
from app.repositories.dashboard_repository import get_dashboard_stats

from app.web.templates import templates
from app.core.config import get_settings

settings = get_settings()
router = APIRouter(
    prefix="/dashboard",
    tags=["Dashboard"],
)


@router.get("/", response_class=HTMLResponse)
def dashboard(
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(
        require_roles(
            "Super Admin",
            "Staff",
        )
    ),
):

    statistics = get_license_statistics(db)

    return templates.TemplateResponse(
        "dashboard.html",
        {
            "request": request,
            "statistics": statistics,
            "admin": admin,
        },
    )

@router.get("/dashboard", response_class=HTMLResponse)
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
