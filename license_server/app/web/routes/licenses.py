from uuid import UUID

from fastapi import APIRouter, Depends, Request
from fastapi.responses import HTMLResponse, RedirectResponse, Response, FileResponse
from fastapi.templating import Jinja2Templates
from sqlalchemy.orm import Session
from app.database.session import SessionLocal

from app.auth.dependencies import require_roles
from app.database.session import get_db
from app.repositories.license_repository import (list_licenses, get_license_details,)
from app.services.license_management_service import renew_license, suspend_license, revoke_license, reactivate_license, delete_license, get_license, get_licenses
from app.services.license_history_service import get_renewal_history
from app.services.license_download_service import download_license_document
from app.utils.flash import flash

from app.web.templates import templates

from app.core.config import get_settings

settings = get_settings()
router = APIRouter(
    prefix="/licenses",
    tags=["License Pages"],
)


@router.get("", response_class=HTMLResponse,)
def license_list_page(
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles("Super Admin", "Staff")),
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
        },
    )


@router.get("/{license_id}", response_class=HTMLResponse,)
def license_details_page(
    request: Request,
    license_id: UUID,
    db: Session = Depends(get_db),
    admin=Depends(require_roles("Super Admin", "Staff")),
):

    license = get_license(
        db,
        license_id,
    )

    return templates.TemplateResponse(
        "license_details.html",
        {
            "request": request,
            "license": license,
        },
    )


@router.get("/licenses", response_class=HTMLResponse)
def licenses_page(request: Request, admin=Depends(require_roles("Super Admin", "Staff"))) -> HTMLResponse:
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

@router.post("/licenses/{license_id}/renew")
def renew_license_page(
    license_id: UUID,
    request: Request,
    admin=Depends(require_roles("Super Admin", "Staff")),
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
        url=f"/licenses/{license_id}",
        status_code=303,
    )

@router.post("/licenses/{license_id}/suspend")
def suspend_license_page(
    license_id: UUID,
    request: Request,
    admin=Depends(require_roles("Super Admin", "Staff")),
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
        url=f"/licenses/{license_id}",
        status_code=303,
    )

@router.post("/licenses/{license_id}/reactivate")
def reactivate_license_page(
    license_id: UUID,
    request: Request,
    admin=Depends(require_roles("Super Admin", "Staff")),
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
        url=f"/licenses/{license_id}",
        status_code=303,
    )

@router.post("/licenses/{license_id}/revoke")
def revoke_license_page(
    license_id: UUID,
    request: Request,
    admin=Depends(require_roles("Super Admin")),
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
        url=f"/licenses/{license_id}",
        status_code=303,
    )

@router.get("/licenses/{license_id}/download")
def download_license(
    license_id: UUID,
    admin=Depends(require_roles("Super Admin", "Staff")),
):
    with SessionLocal() as db:

        filename, contents = download_license_document(
            db,
            license_id,
        )

    return Response(
        content=contents,
        media_type="application/octet-stream",
        headers={
            "Content-Disposition":
                f'attachment; filename="{filename}"'
        },
    )

@router.get("/licenses/{license_id}/download")
def download_license_page(
    license_id: UUID,
    admin=Depends(require_roles("Super Admin", "Staff")),
):
    from app.services.license_management_service import download_license

    with SessionLocal() as db:
        license_json, filename = download_license(db, license_id)

    headers = {
        "Content-Disposition": f'attachment; filename="{filename}"'
    }

    return Response(
        content=license_json,
        media_type="application/json",
        headers=headers,
    )

@router.post("/licenses/{license_id}/delete")
def delete_license_page(
    license_id: UUID,
    request: Request,
    admin=Depends(require_roles("Super Admin")),
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

@router.get(
    "/licenses/{license_id}",
    response_class=HTMLResponse,
)
def license_details_page(
    license_id: str,
    request: Request,
    admin=Depends(
        require_roles(
            "Super Admin",
            "Staff",
        )
    ),
):

    from uuid import UUID

    with SessionLocal() as db:

        license = get_license_details(
            db,
            UUID(license_id),
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