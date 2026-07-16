from uuid import UUID

from fastapi import APIRouter, Depends, Query, Request, Form
from fastapi.responses import HTMLResponse, RedirectResponse
from sqlalchemy.orm import Session
from app.database.session import SessionLocal

from app.core.roles import Roles
from app.auth.dependencies import require_roles
from app.database.session import get_db
from app.services.device_service import (
    get_devices,
    get_device_or_404,
)
from app.services.device_service import rename_license_device, save_device_notes,blacklist_license_device, unblacklist_license_device, deactivate_device, reset_device
from app.repositories.license_device_repository import list_devices, get_device


from app.utils.flash import flash
from app.web.templates import templates
from app.core.config import get_settings


router = APIRouter(
    prefix="/devices", 
    tags=["Web - Devices"],
)
settings = get_settings()

@router.get("/", response_class=HTMLResponse,)
def device_list_page(
    request: Request,
    search: str | None = Query(default=None),
    status: str | None = Query(default=None),
    page: int = Query(default=1, ge=1),
    page_size: int = Query(default=20, ge=1, le=100),
    db: Session = Depends(get_db),
    admin=Depends(
        require_roles(
            Roles.SUPER_ADMIN,
            Roles.STAFF
        )
    ),
):

    devices, total = get_devices(
        db,
        search=search,
        status_filter=status,
        page=page,
        page_size=page_size,
    )

    return templates.TemplateResponse(
        "devices.html",
        {
            "request": request,
            "devices": devices,
            "total": total,
            "page": page,
            "status": status,
            "search": search,
        },
    )

@router.get("/{device_id:uuid}",response_class=HTMLResponse)
def device_details_page(
    request: Request,
    device_id: UUID,
    admin=Depends(
        require_roles(
            Roles.SUPER_ADMIN,
            Roles.STAFF
        )
    ),
):

    with SessionLocal() as db:
        device = get_device_or_404(
            db,
            device_id,
        )

    return templates.TemplateResponse(
        "device_details.html",
        {
            "request": request,
            "settings": settings,
            "title": "Device Details",
            "admin": admin,
            "device": device,
        },
    )

@router.get("/device", response_class=HTMLResponse)
def devices_page(
    request: Request,
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):

    with SessionLocal() as db:
        devices, _ = list_devices(
            db,
            offset=0,
            limit=100,
        )

    return templates.TemplateResponse(
        "devices.html",
        {
            "request": request,
            "settings": settings,
            "title": "Devices",
            "admin": admin,
            "devices": devices,
        },
    )

@router.post("/{device_id:uuid}/rename")
def rename_device_submit(
    device_id: UUID,
    request: Request,
    renamed_to: str = Form(...),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):

    with SessionLocal() as db:
        rename_license_device(
            db,
            device_id,
            renamed_to,
            admin=admin,
            request=request,
        )

    flash(request, "Device renamed successfully.", "success")

    return RedirectResponse(
        f"/devices/{device_id}",
        status_code=303,
    )

@router.post("/{device_id:uuid}/notes")
def save_notes_submit(
    device_id: UUID,
    request: Request,
    notes: str = Form(""),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):

    with SessionLocal() as db:
        save_device_notes(
            db,
            device_id,
            notes,
            admin=admin,
            request=request,
        )

    flash(request, "Notes updated.", "success")

    return RedirectResponse(
        f"/devices/{device_id}",
        status_code=303,
    )

@router.post("/{device_id:uuid}/blacklist")
def blacklist_submit(
    device_id: UUID,
    request: Request,
    reason: str = Form(""),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):

    with SessionLocal() as db:
        blacklist_license_device(
            db,
            device_id,
            reason,
            admin=admin,
            request=request,
        )

    flash(request, "Device blacklisted.", "warning")

    return RedirectResponse(
        f"/devices/{device_id}",
        status_code=303,
    )

@router.post("/{device_id:uuid}/unblacklist")
def unblacklist_submit(
    device_id: UUID,
    request: Request,
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):

    with SessionLocal() as db:
        unblacklist_license_device(
            db,
            device_id,
            admin=admin,
            request=request,
        )

    flash(request, "Device restored.", "success")

    return RedirectResponse(
        f"/devices/{device_id}",
        status_code=303,
    )

@router.post("/{device_id:uuid}/deactivate")
def deactivate_device_submit(
    device_id: UUID,
    request: Request,
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):

    with SessionLocal() as db:
        deactivate_device(
            db,
            device_id,
            admin=admin,
            request=request,
        )

    flash(
        request,
        "Device deactivated.",
        "warning",
    )

    return RedirectResponse(
        f"/devices/{device_id}",
        status_code=303,
    )

@router.post("/{device_id:uuid}/reset")
def reset_device_submit(
    device_id: UUID,
    request: Request,
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):

    with SessionLocal() as db:
        reset_device(
            db,
            device_id,
            admin=admin,
            request=request,
        )

    flash(
        request,
        "Activation reset successfully.",
        "success",
    )

    return RedirectResponse(
        f"/devices/{device_id}",
        status_code=303,
    )
