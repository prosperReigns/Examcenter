from datetime import timedelta

from fastapi import Depends, APIRouter, Request
from fastapi.responses import HTMLResponse
from app.database.session import SessionLocal

from app.core.roles import Roles
from app.auth.dependencies import require_roles
from app.services.audit_log_service import get_audit_logs
from app.web.templates import templates
from app.core.config import get_settings

router = APIRouter(
    prefix="/audit", 
    tags=["Web - Audits"],
)
settings = get_settings()


@router.get("/audit-logs", response_class=HTMLResponse)
def audit_logs_page(request: Request, admin=Depends(require_roles(Roles.SUPER_ADMIN))) -> HTMLResponse:
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


