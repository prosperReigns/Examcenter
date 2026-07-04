from uuid import UUID

from fastapi import APIRouter, Depends, Query, Request, status
from sqlalchemy.orm import Session

from app.auth.dependencies import require_roles
from app.database.session import get_db
from app.schemas.license import LicenseCreateRequest, LicenseRead, LicenseStatusUpdateRequest
from app.services.license_management_service import get_license, get_licenses, issue_license, update_license_status, verify_license_document

router = APIRouter(prefix="/api/licenses", tags=["licenses"])


@router.get("", response_model=list[LicenseRead])
def list_licenses_endpoint(
    search: str | None = Query(default=None, max_length=255),
    page: int = Query(default=1, ge=1),
    page_size: int = Query(default=20, ge=1, le=100),
    db: Session = Depends(get_db),
    admin=Depends(require_roles("Super Admin", "Staff")),
):
    items, _ = get_licenses(db, search=search, page=page, page_size=page_size)
    return items


@router.get("/{license_id}", response_model=LicenseRead)
def get_license_endpoint(license_id: UUID, db: Session = Depends(get_db), admin=Depends(require_roles("Super Admin", "Staff"))):
    return get_license(db, license_id)


@router.post("", response_model=LicenseRead, status_code=status.HTTP_201_CREATED)
def create_license_endpoint(payload: LicenseCreateRequest, request: Request, db: Session = Depends(get_db), admin=Depends(require_roles("Super Admin", "Staff"))):
    return issue_license(db, payload, admin=admin, request=request)


@router.post("/verify")
def verify_license_endpoint(payload: dict):
    return verify_license_document(payload)


@router.patch("/{license_id}/status", response_model=LicenseRead)
def update_license_status_endpoint(
    license_id: UUID,
    payload: LicenseStatusUpdateRequest,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles("Super Admin", "Staff")),
):
    return update_license_status(db, license_id, payload, admin=admin, request=request)