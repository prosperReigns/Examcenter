from fastapi import APIRouter, Depends
from sqlalchemy.orm import Session

from app.database.session import get_db

from app.schemas.public_activation import (
    PublicLicenseValidationRequest,
    PublicLicenseValidationResponse,
)

from app.schemas.purchase_session import PurchaseInitializationResponse

from app.schemas.license_device import (
    DeviceHeartbeatRequest,
)

from app.services.activation_service import (
    validate_public_license,
    activate_from_token,
)

from app.services.device_service import (
    heartbeat_device,
)

from app.schemas.purchase_session import (
    PurchaseSessionCreate,
)

from app.schemas.activation import ActivationTokenRequest

from app.services.purchase_session_service import (
    start_purchase,
    get_purchase_status,
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

@router.post("/start-purchase",response_model=PurchaseInitializationResponse)
def start_purchase_endpoint(
    payload: PurchaseSessionCreate,
    db: Session = Depends(get_db),
):
    return start_purchase(
        db,
        payload,
    )

@router.get("/purchase-status/{session_id}")
def purchase_status_endpoint(
    session_id: str,
    db: Session = Depends(get_db),
):
    return get_purchase_status(
        db,
        session_id,
    )

@router.post("/activate")
def activate_endpoint(
    payload: ActivationTokenRequest,
    db: Session = Depends(get_db),
):
    return activate_from_token(
        db,
        payload,
    )

@router.post("/verify")
def verify_license_endpoint(
    payload: ActivationTokenRequest,
    db: Session = Depends(get_db),
):
    return activate_from_token(
        db,
        payload,
        verify_only=True,
    )
