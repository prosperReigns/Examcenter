from uuid import UUID

from fastapi import APIRouter, Depends, HTTPException, Query, status
from sqlalchemy.orm import Session

from app.database.session import get_db
from app.repositories import activation_token_repository
from app.schemas.activation import (
    ActivationTokenRequest,
    ActivationTokenResponse,
    LicenseValidationResponse,
)
from app.schemas.public_activation import PublicRenewalCheckRequest
from app.schemas.purchase_session import (
    CompletePaymentRequest,
    PublicActivationStartRequest,
    PublicStatusResponse,
    PurchaseSessionRead,
)
from app.services.activation_service import validate_license_for_machine
from app.services.activation_token_service import validate_machine, validate_token
from app.services.public_activation_service import complete_activation_from_token
from app.services.purchase_session_service import (
    complete_purchase_session,
    get_purchase_status,
    get_purchase_status_by_reference,
    start_purchase,
)

router = APIRouter(
    prefix="/public",
    tags=["Public Activation"],
)


@router.post(
    "/start-activation",
    response_model=PurchaseSessionRead,
)
def start_activation(
    request: PublicActivationStartRequest,
    db: Session = Depends(get_db),
):
    return start_purchase(db, request)


@router.post("/complete-payment")
def complete_payment(
    request: CompletePaymentRequest,
    db: Session = Depends(get_db),
):
    return complete_purchase_session(db, payload=request)


@router.get(
    "/status",
    response_model=PublicStatusResponse,
)
def status_endpoint(
    session_id: UUID | None = Query(default=None),
    payment_reference: str | None = Query(default=None, max_length=100),
    db: Session = Depends(get_db),
):
    if session_id is None and not payment_reference:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="session_id or payment_reference is required.",
        )
    if session_id is not None:
        return get_purchase_status(db, session_id)
    return get_purchase_status_by_reference(db, payment_reference.strip())


@router.get(
    "/license",
    response_model=ActivationTokenResponse,
)
def download_license(
    activation_token: str = Query(min_length=20, max_length=255),
    fingerprint: str = Query(min_length=5, max_length=255),
    ip_address: str | None = Query(default=None, max_length=50),
    db: Session = Depends(get_db),
):
    return complete_activation_from_token(
        db,
        ActivationTokenRequest(
            activation_token=activation_token,
            machine_fingerprint=fingerprint,
            ip_address=ip_address,
        ),
    )


@router.post(
    "/check-renewal",
    response_model=LicenseValidationResponse,
)
def check_renewal(
    request: PublicRenewalCheckRequest,
    db: Session = Depends(get_db),
):
    license_id = None
    if request.activation_token:
        activation_token = activation_token_repository.get_by_token(db, request.activation_token)
        validate_token(activation_token)
        validate_machine(activation_token, request.machine_fingerprint)
        license_id = activation_token.license_id
    elif request.license_key:
        try:
            license_id = UUID(request.license_key)
        except ValueError as exc:
            raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="Invalid license key.") from exc
    else:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="license_key or activation_token is required.",
        )

    return validate_license_for_machine(
        db,
        license_id,
        request.machine_fingerprint,
    )


@router.post(
    "/activate",
    response_model=ActivationTokenResponse,
)
def activate(
    request: ActivationTokenRequest,
    db: Session = Depends(get_db),
):
    return complete_activation_from_token(db, request)
