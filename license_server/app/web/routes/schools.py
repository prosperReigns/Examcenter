from uuid import UUID

from fastapi import APIRouter, Depends, Request
from fastapi.responses import HTMLResponse
from sqlalchemy.orm import Session
from app.database.session import SessionLocal

from app.auth.dependencies import require_roles
from app.database.session import get_db

from app.core.roles import Roles
from app.services.school_service import (
    get_schools,
    get_school,
)
from app.repositories.school_repository import list_schools
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
        page=1,
        page_size=100,
    )

    return templates.TemplateResponse(
        "schools.html",
        {
            "request": request,
            "schools": schools,
        },
    )


@router.get("/{school_id}", response_class=HTMLResponse,)
def school_details_page(
    school_id: UUID,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):

    return templates.TemplateResponse(
        "school_details.html",
        {
            "request": request,
            "school": get_school(
                db,
                school_id,
            ),
        },
    )

@router.get("/school", response_class=HTMLResponse)
def schools_page(request: Request, admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF))) -> HTMLResponse:
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