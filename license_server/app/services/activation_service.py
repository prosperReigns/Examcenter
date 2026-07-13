from uuid import UUID
from datetime import datetime, timezone
from fastapi import HTTPException, status
from sqlalchemy.orm import Session

from app.models.activation import Activation
from app.repositories.activation_repository import count_active_activations, create_activation_record, deactivate_activation, get_activation_by_id, get_activation_for_machine, upsert_license_device, activation_statistics
from app.repositories.license_repository import get_license_by_id, persist_license
from app.schemas.activation import ActivationRequest, LicenseValidationResponse
from app.services.license_service import is_activation_allowed, verify_signed_license
from app.services.audit_service import record_audit_event

def validate_license_for_machine(db: Session, license_id: UUID, machine_id: str) -> LicenseValidationResponse:
    license_obj = get_license_by_id(db, license_id)

    if license_obj is None or license_obj.deleted_at is not None:
        record_audit_event(
            db,
            action="license_validation_failed",
            entity_type="license",
            entity_id=str(license_id),
            description="Validation failed: License not found.",
        )
        return LicenseValidationResponse(valid=False, status="not found", message="License not found", renewal_required=True,)
    
    if license_obj.status != "active":
        record_audit_event(
            db,
            action="license_validation_failed",
            entity_type="license",
            entity_id=str(license_id),
            description="Validation failed: License suspended.",
        )
        return LicenseValidationResponse(valid=False, status="inactive", message=f"License is {license_obj.status}")
    
    if license_obj.status == "revoked":
        record_audit_event(
            db,
            action="license_validation_failed",
            entity_type="license",
            entity_id=str(license_id),
            description="Validation failed: License has been revoked.",
        )
        return LicenseValidationResponse(
            valid=False,
            status="revoked",
            message="This license has been revoked.",renewal_required=True,
        )
    if license_obj.status == "suspended":
        record_audit_event(
            db,
            action="license_validation_failed",
            entity_type="license",
            entity_id=str(license_id),
            description="Validation failed: License suspended.",
        )

        return LicenseValidationResponse(
            valid=False,
            status="suspended",
            message="This license is currently suspended.", renewal_required=True)
    
    if (
    license_obj.expiry_at is not None
    and license_obj.expiry_at < datetime.now(timezone.utc)
):
        license_obj.status = "expired"

        persist_license(db, license_obj)

        db.commit()

        record_audit_event(
            db,
            action="license_validation_failed",
            entity_type="license",
            entity_id=str(license_id),
            description="Validation failed: License has expired.",
        )
        
        return LicenseValidationResponse(
            valid=False,
            status="expired",
            message="License has expired.",
            expires_at=license_obj.expiry_at,
            renewal_required=True)

    verification = verify_signed_license(license_obj.signed_license)

    if not verification.valid:
        record_audit_event(
            db,
            action="license_validation_failed",
            entity_type="license",
            entity_id=str(license_id),
            description="Validation failed: License verification failed.",
        )
        return LicenseValidationResponse(valid=False, status="verification failed", message=verification.error or "License verification failed")

    if verification.payload and verification.payload.machine != machine_id:
        record_audit_event(
            db,
            action="license_validation_failed",
            entity_type="license",
            entity_id=str(license_id),
            description="Validation failed: fingerprint mismatch.",
        )
        return LicenseValidationResponse(valid=False, status="fingerprint mismatch", message="Machine fingerprint mismatch")

    # if license_obj.machine_fingerprint != payload.machine_id:
    #     return LicenseValidationResponse(
    #         valid=False,
    #         message="Machine fingerprint mismatch."
    #     )

    existing_activation = get_activation_for_machine(db, license_id, machine_id)
    if existing_activation is not None:
        record_audit_event(
            db,
            action="license_validation_failed",
            entity_type="license",
            entity_id=str(license_id),
            description="Validation failed: License already activated.",
            renewal_required=False,
        )
        return LicenseValidationResponse(valid=True, status="already activated", message="License already activated on this machine",renewal_required=False)

    current_activation_count = count_active_activations(db, license_id)

    if not is_activation_allowed(current_activation_count):
        record_audit_event(
            db,
            action="license_validation_failed",
            entity_type="license",
            entity_id=str(license_id),
            description="Validation failed: License activation limit exceeded.",
        )
        return LicenseValidationResponse(valid=False, status="limit reached",message="Activation limit reached",renewal_required=True)

    record_audit_event(
        db,
        action="license_validation_succeeded",
        entity_type="license",
        entity_id=str(license_id),
        description="Validation failed: License invalid for activation.",
    )
    return LicenseValidationResponse(valid=True, status="valid for activation", message="License is valid for activation",renewal_required=True)


def activate_license(db: Session, license_id: UUID, payload: ActivationRequest) -> Activation:
    validation = validate_license_for_machine(db, license_id, payload.machine_id)
    if not validation.valid:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail=validation.message)

    license_obj = get_license_by_id(db, license_id)
    if license_obj is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="License not found")

    if license_obj.status == "revoked":
        raise HTTPException(
            status_code=403,
            detail="License has been revoked."
        )

    if license_obj.status == "suspended":
        raise HTTPException(
            status_code=403,
            detail="License has been suspended."
        )

    if (
        license_obj.expiry_at
        and license_obj.expiry_at < datetime.now(timezone.utc)
    ):
        raise HTTPException(
            status_code=403,
            detail="License has expired."
        )

    existing_activation = get_activation_for_machine(db, license_id, payload.machine_id)
    if existing_activation is not None:
        return existing_activation

    activation = create_activation_record(
        db,
        license_id=license_id,
        device_id=device.id,
        school_id=license_obj.school_id,
        machine_id=payload.machine_id,
        computer_name=payload.computer_name,
        ip_address=payload.ip_address,
    )
    device = upsert_license_device(
        db,
        license_id=license_obj.id,
        # device_id=payload.
        machine_id=payload.machine_id,
        computer_name=payload.computer_name,
        windows_version=payload.windows_version,
        cpu_id=payload.cpu_id,
        motherboard_serial=payload.motherboard_serial,
        disk_serial=payload.disk_serial,
        mac_address=payload.mac_address,
        ip_address=payload.ip_address,
        last_user=payload.last_user,
    )

    if device.blacklisted:
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="This device has been blacklisted.",
        )
    record_audit_event(
        db,
        action="license_activated",
        entity_type="license",
        entity_id=str(license_obj.id),
        description=f"Activated on {payload.machine_id}",
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
    record_audit_event(
        db,
        action="license_deactivated",
        entity_type="license",
        entity_id=str(license.id),
        description=f"Deactivated on ",
    )
    db.commit()
    db.refresh(activation)
    return activation

def get_activation_statistics(
    db: Session,
):

    return activation_statistics(db)

def validate_public_license():
    pass
def create_activation():
    pass