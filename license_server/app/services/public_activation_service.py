import json

from fastapi import HTTPException, status
from sqlalchemy.orm import Session

from app.repositories import activation_token_repository
from app.repositories.activation_repository import (
    count_active_activations,
    create_activation_record,
    get_activation_for_machine,
)
from app.repositories.license_device_repository import (
    create_device,
    get_device_by_machine_id,
    save_device,
)
from app.repositories.license_repository import get_license_by_id, persist_license
from app.schemas.activation import ActivationTokenRequest, ActivationTokenResponse
from app.services.activation_token_service import consume_token, validate_machine, validate_token
from app.services.audit_service import record_audit_event
from app.services.license_package_service import license_package_document
from app.services.license_service import is_activation_allowed
from app.utils.time import as_aware, utcnow


def _load_valid_license(db: Session, license_id):
    license_obj = get_license_by_id(db, license_id)
    if license_obj is None or license_obj.deleted_at is not None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="License not found.")
    if license_obj.status != "active":
        raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="License is inactive.")
    if license_obj.revoked_at is not None:
        raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="License is revoked.")
    if license_obj.suspended_at is not None:
        raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="License is suspended.")
    if license_obj.expiry_at is not None and as_aware(license_obj.expiry_at) < utcnow():
        license_obj.status = "expired"
        persist_license(db, license_obj)
        raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="License has expired.")
    if not license_obj.signed_license:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Signed license not found.")
    return license_obj


def complete_activation_from_token(
    db: Session,
    payload: ActivationTokenRequest,
) -> ActivationTokenResponse:
    activation_token = activation_token_repository.get_by_token(db, payload.activation_token)
    validate_token(activation_token)
    validate_machine(activation_token, payload.machine_fingerprint)

    license_obj = _load_valid_license(db, activation_token.license_id)
    activation = get_activation_for_machine(
        db,
        license_obj.id,
        payload.machine_fingerprint,
    )

    if activation is None:
        current_activation_count = count_active_activations(db, license_obj.id)
        if not is_activation_allowed(current_activation_count, license_obj.max_activations):
            raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="Activation limit reached.")

        device = get_device_by_machine_id(db, payload.machine_fingerprint)
        if device is None:
            device = create_device(
                db,
                license_id=license_obj.id,
                machine_id=payload.machine_fingerprint,
            )
        else:
            device.license_id = license_obj.id
            save_device(db, device)

        if device.blacklisted:
            raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="This device has been blacklisted.")

        activation = create_activation_record(
            db,
            license_id=license_obj.id,
            device_id=device.id,
            school_id=license_obj.school_id,
            machine_id=payload.machine_fingerprint,
            computer_name=None,
            ip_address=payload.ip_address,
        )
        license_obj.activation_count += 1
        license_obj.last_activation_at = utcnow()
        device.activation_count += 1
        db.add_all([license_obj, device])

    package_document = license_package_document(license_obj)
    consume_token(db, activation_token)
    record_audit_event(
        db,
        action="activation_token_consumed",
        entity_type="license",
        entity_id=str(license_obj.id),
        description="Signed license downloaded through public activation API.",
        ip_address=payload.ip_address,
    )
    db.commit()
    db.refresh(activation)

    return ActivationTokenResponse(
        success=True,
        message="Activation successful.",
        license=package_document,
        license_package=json.loads(package_document),
        expires_at=license_obj.expiry_at,
        activation_id=activation.id,
    )
