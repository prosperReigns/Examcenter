from uuid import UUID

from fastapi import APIRouter, Depends, Form, Request
from fastapi.responses import HTMLResponse, RedirectResponse
from sqlalchemy.orm import Session
from app.database.session import SessionLocal

from app.auth.dependencies import require_roles
from app.database.session import get_db

from app.core.roles import Roles
from app.services.school_service import (
    create_school_record,
    get_schools,
    get_school,
    update_school_record,
)
from app.repositories.school_repository import list_schools
from app.repositories.customer_repository import list_customers as list_customer_records
from app.schemas.school import SchoolCreate, SchoolUpdate
from app.utils.flash import flash
from app.web.templates import templates
from app.core.config import get_settings


router = APIRouter(
    prefix="/schools", 
    tags=["School"],
)
settings = get_settings()

@router.get("/", response_class=HTMLResponse,)
def school_list_page(
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):

    schools, _ = get_schools(
        db,
        search=None,
        page=1,
        page_size=100,
    )

    return templates.TemplateResponse(
        "schools/schools.html",
        {
            "request": request,
            "title": "Schools",
            "admin": admin,
            "schools": schools,
        },
    )


@router.get("/new", response_class=HTMLResponse)
def new_school_page(
    request: Request,
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):
    with SessionLocal() as db:
        customers, _ = list_customer_records(db, offset=0, limit=100)

    return templates.TemplateResponse(
        "schools/school_form.html",
        {
            "request": request,
            "title": "Register School",
            "admin": admin,
            "school": None,
            "customers": customers,
        },
    )


@router.post("/new")
def create_school_page(
    request: Request,
    customer_id: UUID = Form(...),
    name: str = Form(...),
    code: str | None = Form(None),
    contact_email: str | None = Form(None),
    contact_phone: str | None = Form(None),
    address: str | None = Form(None),
    is_active: bool = Form(True),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):
    payload = SchoolCreate(
        customer_id=customer_id,
        name=name,
        code=code or None,
        contact_email=contact_email or None,
        contact_phone=contact_phone or None,
        address=address or None,
        is_active=is_active,
    )

    with SessionLocal() as db:
        school = create_school_record(
            db,
            payload,
            admin=admin,
            request=request,
        )

    flash(request, "School registered successfully.", "success")

    return RedirectResponse(
        f"/schools/{school.id}",
        status_code=303,
    )


@router.get("/{school_id:uuid}", response_class=HTMLResponse,)
def school_details_page(
    school_id: UUID,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):

    return templates.TemplateResponse(
        "schools/school_details.html",
        {
            "request": request,
            "title": "School Details",
            "admin": admin,
            "school": get_school(
                db,
                school_id,
            ),
        },
    )


@router.get("/{school_id:uuid}/edit", response_class=HTMLResponse)
def edit_school_page(
    school_id: UUID,
    request: Request,
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):
    with SessionLocal() as db:
        school = get_school(db, school_id)
        customers, _ = list_customer_records(db, offset=0, limit=100)

    return templates.TemplateResponse(
        "schools/school_form.html",
        {
            "request": request,
            "title": "Edit School",
            "admin": admin,
            "school": school,
            "customers": customers,
        },
    )


@router.post("/{school_id:uuid}/edit")
def update_school_page(
    school_id: UUID,
    request: Request,
    customer_id: UUID = Form(...),
    name: str = Form(...),
    code: str | None = Form(None),
    contact_email: str | None = Form(None),
    contact_phone: str | None = Form(None),
    address: str | None = Form(None),
    is_active: bool = Form(False),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):
    payload = SchoolUpdate(
        customer_id=customer_id,
        name=name,
        code=code or None,
        contact_email=contact_email or None,
        contact_phone=contact_phone or None,
        address=address or None,
        is_active=is_active,
    )

    with SessionLocal() as db:
        school = update_school_record(
            db,
            school_id,
            payload,
            admin=admin,
            request=request,
        )

    flash(request, "School updated successfully.", "success")

    return RedirectResponse(
        f"/schools/{school.id}",
        status_code=303,
    )


@router.get("/school", response_class=HTMLResponse)
def schools_page(request: Request, admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF))) -> HTMLResponse:
    with SessionLocal() as db:
        schools, _ = list_schools(db, offset=0, limit=100)

    return templates.TemplateResponse(
        "schools/schools.html",
        {
            "request": request,
            "settings": settings,
            "title": "Schools",
            "admin": admin,
            "schools": schools,
        },
    )
