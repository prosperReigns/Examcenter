from uuid import UUID

from fastapi import APIRouter, Depends, Request, status, HTTPException
from sqlalchemy.orm import Session

from app.auth.dependencies import require_roles
from app.database.session import get_db
from app.schemas.license import LicenseRead
from app.schemas.license_renewal import (
    LicenseRenewRequest,
    LicenseRenewalRead,
)
from app.services.license_renewal_service import (
    get_license_history,
    renew_license, get_available_renewal_plans, get_renewal_statistics
)
from app.repositories.license_renewal_repository import (
    get_license_renewal,
)

router = APIRouter(
    prefix="/api",
    tags=["License Renewals"],
)

@router.post(
    "/licenses/{license_id}/renew",
    response_model=LicenseRead,
    status_code=status.HTTP_200_OK,
)
def renew_license_endpoint(
    license_id: UUID,
    payload: LicenseRenewRequest,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(
        require_roles(
            "Super Admin",
            "Staff",
        )
    ),
):

    return renew_license(
        db=db,
        license_id=license_id,
        plan=payload.plan,
        payment_id=payload.payment_id,
        notes=payload.notes,
        admin=admin,
        request=request,
    )

@router.get(
    "/licenses/{license_id}/renewals",
    response_model=list[LicenseRenewalRead],
)
def renewal_history_endpoint(
    license_id: UUID,
    db: Session = Depends(get_db),
    admin=Depends(
        require_roles(
            "Super Admin",
            "Staff",
        )
    ),
):

    return get_license_history(
        db,
        license_id,
    )

@router.get(
    "/renewals/{renewal_id}",
    response_model=LicenseRenewalRead,
)
def renewal_details_endpoint(
    renewal_id: UUID,
    db: Session = Depends(get_db),
    admin=Depends(
        require_roles(
            "Super Admin",
            "Staff",
        )
    ),
):

    renewal = get_license_renewal(
        db,
        renewal_id,
    )

    if renewal is None:
        raise HTTPException(
            status_code=404,
            detail="Renewal not found",
        )

    return renewal

@router.get("/renewal-plans")
def renewal_plans_endpoint(
    admin=Depends(require_roles("Super Admin", "Staff")),
):

    return get_available_renewal_plans()

@router.get(
    "/renewals/statistics",
)
def renewal_statistics_endpoint(

    db: Session = Depends(get_db),

    admin=Depends(
        require_roles(
            "Super Admin",
            "Staff",
        )
    ),

):

    return get_renewal_statistics(db)