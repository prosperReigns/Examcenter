from datetime import datetime

from fastapi import (
    APIRouter,
    Depends,
    HTTPException,
    status,
)

from sqlalchemy.orm import Session

from app.database.session import get_db

from app.schemas.activation import (
    ActivationRequest,
    ActivationResponse,
)

from app.repositories import (
    activation_token_repository,
    activation_repository,
    license_repository,
)

from app.services.activation_token_service import (
    validate_token,
    validate_machine,
    consume_token,
)

from app.services.license_signing_service import (
    export_license,
)

from app.services.audit_service import (
    record_audit_event,
)

router = APIRouter(
    prefix="/public",
    tags=["Public Activation"],
)

@router.post(
    "/activate",
    response_model=ActivationResponse,
)
def activate(
    request: ActivationRequest,
    db: Session = Depends(get_db),
):
        activation_token = (
        activation_token_repository.get_by_token(
            db,
            request.activation_token,
        )
    )

    if activation_token is None:

        raise HTTPException(
            status_code=404,
            detail="Activation token not found.",
        )

    validate_token(
        activation_token,
    )

    validate_machine(

        activation_token,

        request.machine_fingerprint,

    )

    license = (
        license_repository.get_by_id(
            db,
            activation_token.license_id,
        )
    )

    if license is None:

        raise HTTPException(
            status_code=404,
            detail="License not found.",
        )

    if license.status != "active":

    raise HTTPException(
        status_code=403,
        detail="License is inactive.",
    )

        signed_license = export_license(
        license,
    )

    activation = activation_repository.create(

        db=db,

        license_id=license.id,

        machine_fingerprint=request.machine_fingerprint,

        ip_address=request.ip_address,

        activated_at=datetime.utcnow(),

    )

    consume_token(

        db,

        activation_token,

    )

    license.activation_count += 1

    db.commit()

    record_audit_event(

        db=db,

        action="license_activated",

        entity_type="license",

        entity_id=str(license.id),

        description=(
            "License activated "
            "using activation token."
        ),

    )

    return ActivationResponse(

        success=True,

        message="Activation successful.",

        license=signed_license,

    )