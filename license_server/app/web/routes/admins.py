from uuid import UUID

from fastapi import APIRouter, Depends, Form, Request
from fastapi.responses import HTMLResponse, RedirectResponse
from sqlalchemy.orm import Session
from app.database.session import SessionLocal

from app.core.roles import Roles
from app.auth.dependencies import require_roles
from app.database.session import get_db

from app.services.admin_service import (
    create_admin_record,
    list_admins,
    get_admin,
    update_admin_record,
)
from app.schemas.admin import AdminCreate, AdminUpdate
from app.utils.flash import flash

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

    admins = list_admins(db)
    total = len(admins)

    return templates.TemplateResponse(
        "admins/index.html",
        {
            "request": request,
            "title": "Administrators",
            "admin": admin,
            "admins": admins,
            "total": total,
            "page": 1,
            "total_pages": (total + 19) // 20,
        },
    )


@router.get("/{admin_id:uuid}", response_class=HTMLResponse,)
def admin_details_page(
    admin_id: UUID,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):

    return templates.TemplateResponse(
        "admins/details.html",
        {
            "request": request,
            "title": "Administrator Details",
            "admin": admin,
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

        admins = list_admins(
            db,
            search=search,
            offset=(page - 1) * 20,
            limit=20,
        )
        total = len(admins)

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


@router.post("/new")
def create_admin_page(
    request: Request,
    full_name: str = Form(...),
    email: str = Form(...),
    password: str = Form(...),
    role: str = Form("Staff"),
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):
    payload = AdminCreate(
        full_name=full_name,
        email=email,
        password=password,
        role=role,
    )

    with SessionLocal() as db:
        created = create_admin_record(
            db,
            payload,
            current_admin=admin,
            request=request,
        )

    flash(request, "Administrator created successfully.", "success")

    return RedirectResponse(
        f"/admins/{created.id}",
        status_code=303,
    )


@router.get("/{admin_id:uuid}/edit", response_class=HTMLResponse)
def edit_admin_page(
    admin_id: UUID,
    request: Request,
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):
    with SessionLocal() as db:

        target = get_admin(
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


@router.post("/{admin_id:uuid}/edit")
def update_admin_page(
    admin_id: UUID,
    request: Request,
    full_name: str = Form(...),
    email: str = Form(...),
    role: str = Form("Staff"),
    is_active: bool = Form(False),
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):
    payload = AdminUpdate(
        full_name=full_name,
        email=email,
        role=role,
        is_active=is_active,
    )

    with SessionLocal() as db:
        target = update_admin_record(
            db,
            admin_id,
            payload,
            current_admin=admin,
            request=request,
        )

    flash(request, "Administrator updated successfully.", "success")

    return RedirectResponse(
        f"/admins/{target.id}",
        status_code=303,
    )
