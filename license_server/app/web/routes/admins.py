from fastapi import APIRouter, Depends, Request
from fastapi.responses import HTMLResponse
from sqlalchemy.orm import Session

from app.auth.dependencies import require_roles
from app.database.session import get_db

from app.services.admin_service import (
    list_admins,
    get_admin,
)

from app.web.templates import templates

router = APIRouter(
    prefix="/admins",
    tags=["Admin Pages"],
)


@router.get("", response_class=HTMLResponse,)
def admin_list_page(
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles("Super Admin")),
):

    admins, _ = list_admins(db)

    return templates.TemplateResponse(
        "admins.html",
        {
            "request": request,
            "admins": admins,
        },
    )


@router.get("/{admin_id}", response_class=HTMLResponse,)
def admin_details_page(
    admin_id: int,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles("Super Admin")),
):

    return templates.TemplateResponse(
        "admin_details.html",
        {
            "request": request,
            "admin_record": get_admin(
                db,
                admin_id,
            ),
        },
    )