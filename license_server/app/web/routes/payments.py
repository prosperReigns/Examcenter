
from fastapi.responses import HTMLResponse
from fastapi import Depends, APIRouter, Request
from app.database.session import SessionLocal
from app.auth.dependencies import require_roles

from app.core.roles import Roles
from app.repositories.payment_repository import list_payments
from app.web.templates import templates

from app.core.config import get_settings

settings = get_settings()
router = APIRouter(
    prefix="/payments",
    tags=["Payment Pages"],
)

@router.get("/", response_class=HTMLResponse)
def payments_page(request: Request, admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF))) -> HTMLResponse:
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