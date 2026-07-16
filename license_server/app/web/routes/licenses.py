from uuid import UUID

from fastapi import APIRouter, Depends, Request
from fastapi.responses import HTMLResponse, RedirectResponse, Response
from sqlalchemy.orm import Session
from app.database.session import SessionLocal

from app.core.roles import Roles
from app.auth.dependencies import require_roles
from app.database.session import get_db
from app.repositories.license_repository import (list_licenses, get_license_details,)
from app.services.license_management_service import renew_license, suspend_license, revoke_license, reactivate_license, delete_license, get_license, get_licenses
from app.services.license_download_service import download_license_document
from app.utils.flash import flash

from app.web.templates import templates

from app.core.config import get_settings


router = APIRouter(
    prefix="/licenses", 
    tags=["Web - License"]
)
settings = get_settings()

@router.get("/", response_class=HTMLResponse,)
def license_list_page(
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):

    licenses, _ = get_licenses(
        db,
        search=None,
        page=1,
        page_size=100,
    )

    return templates.TemplateResponse(
        "licenses.html",
        {
            "request": request,
            "licenses": licenses,
            "title": "License",
            "admin": admin,
        },
    )


@router.get("/{license_id:uuid}", response_class=HTMLResponse,)
def license_details_page(
    request: Request,
    license_id: UUID,
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):

    with SessionLocal() as db:
        license = get_license(
            db,
            license_id,
        )

    if license is None:
        flash(
            request,
            "License not found.",
            "danger",
        )

        return RedirectResponse(
            "/licenses",
            status_code=303,
        )

    return templates.TemplateResponse(
        "license_details.html",
        {
            "request": request,
            "settings": settings,
            "title": "License Details",
            "admin": admin,
            "license": license,
        },
    )


@router.get("/license", response_class=HTMLResponse)
def licenses_page(request: Request, admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF))) -> HTMLResponse:
    with SessionLocal() as db:
        licenses, _ = list_licenses(db, offset=0, limit=100)
    return templates.TemplateResponse(
        "licenses.html",
        {
            "request": request,
            "settings": settings,
            "title": "Licenses",
            "admin": admin,
            "licenses": licenses,
        },
    )

@router.post("/{license_id:uuid}/renew")
def renew_license_page(
    license_id: UUID,
    request: Request,
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):
    with SessionLocal() as db:
        renew_license(
            db,
            license_id,
            admin=admin,
            request=request,
        )

    flash(
        request,
        "License renewed successfully.",
        "success",
    )

    return RedirectResponse(
        url=f"/{license_id}",
        status_code=303,
    )

@router.post("/{license_id:uuid}/suspend")
def suspend_license_page(
    license_id: UUID,
    request: Request,
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):
    with SessionLocal() as db:
        suspend_license(
            db,
            license_id,
            admin=admin,
            request=request,
        )

    flash(request, "License suspended successfully.", "warning")

    return RedirectResponse(
        url=f"/{license_id}",
        status_code=303,
    )

@router.post("/{license_id:uuid}/reactivate")
def reactivate_license_page(
    license_id: UUID,
    request: Request,
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):
    with SessionLocal() as db:
        reactivate_license(
            db,
            license_id,
            admin=admin,
            request=request,
        )

    flash(request, "License reactivated successfully.", "success")

    return RedirectResponse(
        url=f"/{license_id}",
        status_code=303,
    )

@router.post("/{license_id:uuid}/revoke")
def revoke_license_page(
    license_id: UUID,
    request: Request,
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):
    with SessionLocal() as db:
        revoke_license(
            db,
            license_id,
            admin=admin,
            request=request,
        )

    flash(request, "License revoked.", "danger")

    return RedirectResponse(
        url=f"/{license_id}",
        status_code=303,
    )

@router.get("/{license_id:uuid}/download")
def download_license(
    license_id: UUID,
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):
    with SessionLocal() as db:

        filename, contents = download_license_document(
            db,
            license_id,
        )

    return Response(
        content=contents,
        media_type="application/json",
        headers={
            "Content-Disposition":
                f'attachment; filename="{filename}"'
        },
    )

@router.post("/{license_id:uuid}/delete")
def delete_license_page(
    license_id: UUID,
    request: Request,
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):

    with SessionLocal() as db:

        delete_license(
            db,
            license_id,
            admin=admin,
            request=request,
        )

    flash(
        request,
        "License deleted successfully.",
        "success",
    )

    return RedirectResponse(
        "/licenses",
        status_code=303,
    )
