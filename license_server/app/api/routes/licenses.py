from uuid import UUID

from fastapi import APIRouter, Depends, Query, Request, Response, status,  HTTPException
from fastapi.responses import PlainTextResponse, Response
from sqlalchemy.orm import Session

from app.core.roles import Roles
from app.auth.dependencies import require_roles
from app.database.session import get_db
from app.schemas.license import LicenseCreateRequest, LicenseRead, LicenseStatusUpdateRequest, LicenseVerifyRequest
from app.schemas.license_renewal import LicenseRenewRequest

from app.services.license_management_service import( get_license, get_licenses, download_license, renew_license, issue_license, update_license_status, verify_license_document, get_license_statistics,  delete_license,)
from app.services.license_download_service import (
    download_license_document,
)

router = APIRouter(prefix="/api/licenses", tags=["licenses"])

@router.get("/statistics")
def license_statistics_endpoint(
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):
    """
    Dashboard summary statistics.
    """
    return get_license_statistics(db)

@router.get("", response_model=list[LicenseRead])
def list_licenses_endpoint(
    search: str | None = Query(default=None, max_length=255),
    page: int = Query(default=1, ge=1),
    page_size: int = Query(default=20, ge=1, le=100),
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):
    items, _ = get_licenses(db, search=search, page=page, page_size=page_size)
    return items

@router.get(
    "/{license_id}",
    response_model=LicenseRead,
)
def get_license_endpoint(
    license_id: UUID,
    db: Session = Depends(get_db),
    admin=Depends(
        require_roles(Roles.SUPER_ADMIN, Roles.STAFF)
    ),
):
    return get_license(
        db,
        license_id,
    )

@router.post("", response_model=LicenseRead, status_code=status.HTTP_201_CREATED)
def create_license_endpoint(payload: LicenseCreateRequest, request: Request, db: Session = Depends(get_db), admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF))):
    return issue_license(db, payload, admin=admin, request=request)


@router.post("/verify")
def verify_license_endpoint(payload: LicenseVerifyRequest):
    return verify_license_document(payload)


@router.patch("/{license_id}/status", response_model=LicenseRead)
def update_license_status_endpoint(
    license_id: UUID,
    payload: LicenseStatusUpdateRequest,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):
    return update_license_status(db, license_id, payload, admin=admin, request=request)

@router.post(
    "/{license_id}/renew",
    response_model=LicenseRead,
)
def renew_license_endpoint(
    license_id: UUID,
    payload: LicenseRenewRequest,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):
    return renew_license(
        db,
        license_id,
        payload,
        admin=admin,
        request=request,
    )

# ============================================================================
# PUBLIC ACTIVATION ROUTES
# These routes are called directly from the CBT application.
# They do NOT require authentication.
# ============================================================================


@router.get("/activation/options")
def activation_options(
    fingerprint: str,
    product: str,
    version: str,
):
    """
    Returns information needed by the activation page.
    """
    return {
        "product": product,
        "version": version,
        "fingerprint": fingerprint,
        "plans": [
            {
                "code": "trial",
                "name": "7-Day Trial",
                "months": 0
            },
            {
                "code": "6",
                "name": "6 Months",
                "months": 6
            },
            {
                "code": "12",
                "name": "12 Months",
                "months": 12
            },
            {
                "code": "24",
                "name": "24 Months",
                "months": 24
            }
        ]
    }

@router.get("/activation/start")
def activation_start(
    fingerprint: str,
    product: str,
    version: str,
    plan: str,
):
    """
    Receives data from the CBT software.
    The HTML page will later read these values
    and automatically populate the purchase form.
    """
    return {
        "fingerprint": fingerprint,
        "product": product,
        "version": version,
        "selected_plan": plan
    }

@router.post("/{license_id}/suspend")
def suspend_license_endpoint(
    license_id: UUID,
    request: Request,
    db: Session =Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):

    payload = LicenseStatusUpdateRequest(
        status="suspended"
    )

    return update_license_status(
        db,
        license_id,
        payload,
        admin=admin,
        request=request,
    )

@router.post("/{license_id}/revoke")
def revoke_license_endpoint(
    license_id: UUID,
    request: Request,
    db: Session=Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):

    payload = LicenseStatusUpdateRequest(
        status="revoked"
    )

    return update_license_status(
        db,
        license_id,
        payload,
        admin=admin,
        request=request,
    )

@router.post("/{license_id}/activate")
def activate_license_endpoint(
    license_id: UUID,
    request: Request,
    db: Session=Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):

    payload = LicenseStatusUpdateRequest(
        status="active"
    )

    return update_license_status(
        db,
        license_id,
        payload,
        admin=admin,
        request=request,
    )

@router.get("/{license_id}/download")
def download_license_endpoint(
    license_id: UUID,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):
    filename, contents = download_license_document(
        db,
        license_id,
    )

    return Response(
        content=contents,
        media_type="application/json",
        headers={
            "Content-Disposition": (
                f'attachment; filename="{filename}"'
            )
        },
    )

@router.delete("/{license_id}", status_code=status.HTTP_204_NO_CONTENT,)
def delete_license_endpoint(
    license_id: UUID,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):
    delete_license(
        db,
        license_id,
        admin=admin,
        request=request,
    )

    return Response(
        status_code=status.HTTP_204_NO_CONTENT
    )
