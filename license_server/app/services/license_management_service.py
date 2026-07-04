from datetime import datetime, timezone
from uuid import UUID

from fastapi import HTTPException, status
from sqlalchemy.orm import Session

from app.models.license import License
from app.repositories.license_repository import create_license_record, get_license_by_id, list_licenses, persist_license
from app.repositories.school_repository import get_school_by_id
from app.schemas.license import LicenseCreateRequest, LicenseStatusUpdateRequest
from app.services.audit_service import record_audit_event
from app.services.license_service import build_license_expiry, create_signed_license, normalize_license_type, verify_signed_license


def get_licenses(db: Session, *, search: str | None, page: int, page_size: int) -> tuple[list[License], int]:
    offset = (page - 1) * page_size
    return list_licenses(db, search=search, offset=offset, limit=page_size)


def get_license(db: Session, license_id: UUID) -> License:
    license_obj = get_license_by_id(db, license_id)
    if license_obj is None or license_obj.deleted_at is not None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="License not found")
    return license_obj


def issue_license(db: Session, payload: LicenseCreateRequest, *, admin=None, request=None) -> License:
    school = get_school_by_id(db, payload.school_id)
    if school is None or school.deleted_at is not None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="School not found")

    license_document = create_signed_license(
        school=school.name,
        machine=payload.machine_fingerprint,
        license_type=payload.license_type,
        issued_at=datetime.now(timezone.utc),
        version=payload.version,
    )

    license_obj = create_license_record(
        db,
        school_id=payload.school_id,
        machine_fingerprint=payload.machine_fingerprint,
        license_type=normalize_license_type(payload.license_type),
        issued_at=license_document.issued_at,
        expiry_at=license_document.expiry,
        signed_license=license_document.model_dump_json(),
        version=payload.version,
    )
    db.commit()
    db.refresh(license_obj)
    record_audit_event(
        db,
        admin=admin,
        action="license_generated",
        entity_type="license",
        entity_id=str(license_obj.id),
        description=f"Generated {license_obj.license_type} license for school {school.name}",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )
    db.commit()
    return license_obj


def verify_license_document(license_document: str | dict) -> dict:
    result = verify_signed_license(license_document)
    return result.model_dump()


def update_license_status(db: Session, license_id: UUID, payload: LicenseStatusUpdateRequest, *, admin=None, request=None) -> License:
    license_obj = get_license(db, license_id)
    normalized_status = payload.status.strip().lower()
    if normalized_status not in {"active", "suspended", "revoked", "expired"}:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="Invalid license status")

    license_obj.status = normalized_status
    now = datetime.now(timezone.utc)
    if normalized_status == "revoked":
        license_obj.revoked_at = now
    elif normalized_status == "suspended":
        license_obj.suspended_at = now
    elif normalized_status == "expired":
        license_obj.expiry_at = now

    persist_license(db, license_obj)
    db.commit()
    db.refresh(license_obj)
    record_audit_event(
        db,
        admin=admin,
        action=f"license_{normalized_status}",
        entity_type="license",
        entity_id=str(license_obj.id),
        description=f"License {license_obj.id} moved to {normalized_status}",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )
    db.commit()
    return license_obj