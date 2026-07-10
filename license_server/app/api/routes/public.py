from fastapi import APIRouter, Depends
from sqlalchemy.orm import Session

from app.database.session import get_db

from app.schemas.public_activation import (
    PublicLicenseValidationRequest,
    PublicLicenseValidationResponse,
)

from app.schemas.license_device import (
    DeviceHeartbeatRequest,
)

from app.services.activation_service import (
    validate_public_license,
)

from app.services.device_service import (
    heartbeat_device,
)

router = APIRouter(
    prefix="/api/public",
    tags=["Public API"],
)


@router.post(
    "/validate-license",
    response_model=PublicLicenseValidationResponse,
)
def validate_license_endpoint(
    payload: PublicLicenseValidationRequest,
    db: Session = Depends(get_db),
):

    return validate_public_license(
        db,
        license_key=payload.license_key,
        machine_id=payload.machine_id,
        fingerprint=payload.fingerprint,
    )


@router.post(
    "/devices/heartbeat",
)
def heartbeat_endpoint(
    payload: DeviceHeartbeatRequest,
    db: Session = Depends(get_db),
):

    return heartbeat_device(
        db,
        payload,
    )