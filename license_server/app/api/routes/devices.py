from uuid import UUID

from fastapi import APIRouter, Depends, Query, Request
from sqlalchemy.orm import Session

from app.auth.dependencies import require_roles
from app.database.session import get_db
from app.schemas.license_device import (
    DeviceBlacklistRequest,
    DeviceNotesRequest,
    DeviceRenameRequest,
    LicenseDeviceRead,
)
from app.services.device_service import (
    blacklist_license_device,
    get_device_or_404,
    get_devices,
    rename_license_device,
    save_device_notes,
    unblacklist_license_device,
    get_device_statistics,
)

router = APIRouter(
    prefix="/api/devices",
    tags=["devices"],
)

@router.get(
    "",
    response_model=list[LicenseDeviceRead],
)
def list_devices_endpoint(
    search: str | None = Query(default=None),
    status: str | None = Query(default=None),
    license_id: UUID | None = Query(default=None),
    page: int = Query(default=1, ge=1),
    page_size: int = Query(default=20, ge=1, le=100),
    db: Session = Depends(get_db),
    admin=Depends(require_roles("Super Admin", "Staff")),
):

    devices, _ = get_devices(
        db,
        search=search,
        status_filter=status,
        license_id=license_id,
        page=page,
        page_size=page_size,
    )

    return devices

@router.get(
    "/{device_id}",
    response_model=LicenseDeviceRead,
)
def get_device_endpoint(
    device_id: UUID,
    db: Session = Depends(get_db),
    admin=Depends(require_roles("Super Admin", "Staff")),
):

    return get_device_or_404(
        db,
        device_id,
    )

@router.patch(
    "/{device_id}/rename",
    response_model=LicenseDeviceRead,
)
def rename_device_endpoint(
    device_id: UUID,
    payload: DeviceRenameRequest,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles("Super Admin", "Staff")),
):

    return rename_license_device(
        db,
        device_id,
        payload.renamed_to,
        admin=admin,
        request=request,
    )

@router.post(
    "/{device_id}/blacklist",
    response_model=LicenseDeviceRead,
)
def blacklist_device_endpoint(
    device_id: UUID,
    payload: DeviceBlacklistRequest,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles("Super Admin", "Staff")),
):

    return blacklist_license_device(
        db,
        device_id,
        payload.blacklist_reason,
        admin=admin,
        request=request,
    )

@router.post(
    "/{device_id}/unblacklist",
    response_model=LicenseDeviceRead,
)
def unblacklist_device_endpoint(
    device_id: UUID,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles("Super Admin", "Staff")),
):

    return unblacklist_license_device(
        db,
        device_id,
        admin=admin,
        request=request,
    )

@router.patch(
    "/{device_id}/notes",
    response_model=LicenseDeviceRead,
)
def update_notes_endpoint(
    device_id: UUID,
    payload: DeviceNotesRequest,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles("Super Admin", "Staff")),
):

    return save_device_notes(
        db,
        device_id,
        payload.notes,
        admin=admin,
        request=request,
    )

@router.get(
    "/statistics",
    tags=["devices"],
)
def device_statistics_endpoint(
    db: Session = Depends(get_db),
    admin=Depends(require_roles("Super Admin", "Staff")),
):

    return get_device_statistics(db)