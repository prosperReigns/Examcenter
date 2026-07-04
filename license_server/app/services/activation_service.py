from uuid import UUID

from fastapi import HTTPException, status
from sqlalchemy.orm import Session

from app.models.activation import Activation
from app.repositories.activation_repository import count_active_activations, create_activation_record, deactivate_activation, get_activation_by_id, get_activation_for_machine, upsert_license_device
from app.repositories.license_repository import get_license_by_id
from app.schemas.activation import ActivationRequest, LicenseValidationResponse
from app.services.license_service import is_activation_allowed, verify_signed_license

def validate_license_for_machine(db: Session, license_id: UUID, machine_id: str) -> LicenseValidationResponse:
    license_obj = get_license_by_id(db, license_id)
    if license_obj is None or license_obj.deleted_at is not None:
        return LicenseValidationResponse(valid=False, message="License not found")
    if license_obj.status != "active":
        return LicenseValidationResponse(valid=False, message=f"License is {license_obj.status}")

    verification = verify_signed_license(license_obj.signed_license)
    if not verification.valid:
        return LicenseValidationResponse(valid=False, message=verification.error or "License verification failed")

    if verification.payload and verification.payload.machine != machine_id:
        return LicenseValidationResponse(valid=False, message="Machine fingerprint mismatch")

    existing_activation = get_activation_for_machine(db, license_id, machine_id)
    if existing_activation is not None:
        return LicenseValidationResponse(valid=True, message="License already activated on this machine")

    current_activation_count = count_active_activations(db, license_id)
    if not is_activation_allowed(current_activation_count):
        return LicenseValidationResponse(valid=False, message="Activation limit reached")

    return LicenseValidationResponse(valid=True, message="License is valid for activation")


def activate_license(db: Session, license_id: UUID, payload: ActivationRequest) -> Activation:
    validation = validate_license_for_machine(db, license_id, payload.machine_id)
    if not validation.valid:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail=validation.message)

    license_obj = get_license_by_id(db, license_id)
    if license_obj is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="License not found")

    existing_activation = get_activation_for_machine(db, license_id, payload.machine_id)
    if existing_activation is not None:
        return existing_activation

    activation = create_activation_record(
        db,
        license_id=license_id,
        school_id=license_obj.school_id,
        machine_id=payload.machine_id,
        computer_name=payload.computer_name,
        ip_address=payload.ip_address,
    )
    upsert_license_device(
        db,
        license_id=license_id,
        machine_id=payload.machine_id,
        computer_name=payload.computer_name,
        ip_address=payload.ip_address,
    )
    db.commit()
    db.refresh(activation)
    return activation


def get_activation(db: Session, activation_id: UUID) -> Activation:
    activation = get_activation_by_id(db, activation_id)
    if activation is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Activation not found")
    return activation


def deactivate_license_activation(db: Session, activation_id: UUID) -> Activation:
    activation = get_activation(db, activation_id)
    deactivate_activation(db, activation)
    db.commit()
    db.refresh(activation)
    return activation
