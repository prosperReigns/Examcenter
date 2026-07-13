from fastapi import APIRouter, Depends, Request
from fastapi.responses import HTMLResponse
from sqlalchemy.orm import Session
from app.database.session import SessionLocal

from app.core.roles import Roles
from app.auth.dependencies import require_roles
from app.database.session import get_db

from app.services.admin_service import (
    list_admins,
    get_admin,
    get_admin_by_id
)

from app.web.templates import templates

router = APIRouter(
    prefix="/admins", 
    tags=["Web - Admins"],
)


@router.get("/", response_class=HTMLResponse,)
def admin_list_page(
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
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
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
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

@router.get("/admin", response_class=HTMLResponse)
def admins_page(
    request: Request,
    page: int = 1,
    search: str | None = None,
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):
    with SessionLocal() as db:

        admins, total = list_admins(
            db,
            search=search,
            page=page,
            page_size=20,
        )

    return templates.TemplateResponse(
        "admins/index.html",
        {
            "request": request,
            "title": "Administrators",
            "admin": admin,
            "admins": admins,
            "total": total,
            "page": page,
            "total_pages": (total + 19) // 20,
        },
    )

@router.get("/new", response_class=HTMLResponse)
def new_admin_page(
    request: Request,
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):
    return templates.TemplateResponse(
        "admins/create.html",
        {
            "request": request,
            "title": "Create Administrator",
            "admin": admin,
        },
    )

@router.get("/{admin_id}/edit", response_class=HTMLResponse)
def edit_admin_page(
    admin_id: int,
    request: Request,
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):
    with SessionLocal() as db:

        target = get_admin_by_id(
            db,
            admin_id,
        )

    return templates.TemplateResponse(
        "admins/edit.html",
        {
            "request": request,
            "title": "Edit Administrator",
            "admin": admin,
            "target": target,
        },
    )