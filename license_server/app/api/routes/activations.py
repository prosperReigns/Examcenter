from uuid import UUID

from fastapi import APIRouter, Depends, status
from sqlalchemy.orm import Session

from app.core.roles import Roles
from app.auth.dependencies import require_roles
from app.database.session import get_db
from app.schemas.activation import ActivationRead, ActivationRequest, LicenseValidationResponse
from app.services.activation_service import activate_license, deactivate_license_activation, get_activation, validate_license_for_machine, get_activation_statistics
from app.core.roles import Roles


router = APIRouter(prefix="/api/activations", tags=["activations"])

@router.post("/{license_id}/validate", response_model=LicenseValidationResponse)
def validate_activation_endpoint(
    license_id: UUID,
    payload: ActivationRequest,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):
    return validate_license_for_machine(db, license_id, payload.machine_id)


@router.post("/{license_id}", response_model=ActivationRead, status_code=status.HTTP_201_CREATED)
def create_activation_endpoint(
    license_id: UUID,
    payload: ActivationRequest,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):
    return activate_license(db, license_id, payload)


@router.get("/{activation_id}", response_model=ActivationRead)
def get_activation_endpoint(
    activation_id: UUID,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):
    """
    Fetch details for a specific activation record.
    """
    return get_activation(db, activation_id)

@router.post("/{activation_id}/deactivate", response_model=ActivationRead)
def deactivate_activation_endpoint(
    activation_id: UUID,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):
    return deactivate_license_activation(db, activation_id)

@router.get(
    "/statistics",
)
def activation_statistics_endpoint(
    db: Session = Depends(get_db),
    admin=Depends(
        require_roles(
            Roles.SUPER_ADMIN,
            Roles.STAFF
        )
    ),
):

    return get_activation_statistics(db)
