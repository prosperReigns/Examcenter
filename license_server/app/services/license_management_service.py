from datetime import datetime, timezone, timedelta
from uuid import UUID

from fastapi import HTTPException, status
from sqlalchemy import func
from sqlalchemy.orm import Session

from app.models.license import License
from app.repositories.license_repository import create_license_record, list_licenses, persist_license,  soft_delete_license
from app.repositories.school_repository import get_school_by_id
from app.schemas.license import ( LicenseCreateRequest,
LicenseStatusUpdateRequest,)
from app.services.audit_service import record_audit_event
from app.services.license_service import( create_signed_license, normalize_license_type, verify_signed_license,)
from sqlalchemy.orm import selectinload

from app.repositories.license_history_repository import create_history_record

def get_licenses(db: Session, *, search: str | None, page: int, page_size: int) -> tuple[list[License], int]:
    offset = (page - 1) * page_size
    return list_licenses(db, search=search, offset=offset, limit=page_size)


def get_license(db: Session, license_id: UUID) -> License:
    license_obj = (
        db.query(License)
        .options(
            selectinload(License.school),
            selectinload(License.activations),
        )
        .filter(License.id == license_id)
        .first()
    )

    if license_obj is None or license_obj.deleted_at is not None:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="License not found",
        )

    return license_obj

def download_license(db: Session, license_id: UUID) -> tuple[str, str]:
    """
    Returns the signed license document and a suitable filename.
    """
    license_obj = get_license(db, license_id)

    school_name = (
        license_obj.school.name.replace(" ", "_")
        if license_obj.school
        else "school"
    )

    filename = (
        f"{school_name}_"
        f"{license_obj.license_type}_"
        f"v{license_obj.version}.license.json"
    )

    return license_obj.signed_license, filename

def issue_license(db: Session, payload: LicenseCreateRequest, *, admin=None, request=None) -> License:
    school = get_school_by_id(db, payload.school_id)
    if school is None or school.deleted_at is not None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="School not found")

    license_document = create_signed_license(
        school=school.name,
        machine=payload.machine_fingerprint,
        license_type=payload.license_type,
        plan_name=payload.plan_name,
        duration_months=payload.duration_months,
        is_trial=payload.is_trial,
        issued_at=datetime.now(timezone.utc),
        version=payload.version,
    )

    license_obj = create_license_record(
    db,
        school_id=payload.school_id,
        machine_fingerprint=payload.machine_fingerprint,

        license_type=normalize_license_type(
            payload.license_type
        ),

        plan_name=payload.plan_name,
        duration_months=payload.duration_months,
        is_trial=payload.is_trial,
        issued_at=license_document.issued_at,
        expiry_at=license_document.expiry,
        payment_status="paid" if not payload.is_trial else "trial",
        flutterwave_transaction_id=None,
        flutterwave_reference=None,
        amount_paid=payload.amount_paid,
        currency=payload.currency,
        signed_license=license_document.model_dump_json(),
        activation_count=0,
        max_activations=payload.max_activations,
        version=payload.version,
    )

    db.commit()
    db.refresh(license_obj)

    create_history_record(
        db,
        license_id=license_obj.id,
        version=license_obj.version,
        license_type=license_obj.license_type,
        issued_at=license_obj.issued_at,
        expiry_at=license_obj.expiry_at,
        signed_license=license_obj.signed_license,
    )
    
    record_audit_event(
        db,
        admin=admin,
        action="license_generated",
        entity_type="license",
        entity_id=str(license_obj.id),
        description=(
            f"Generated {license_obj.plan_name} "
            f"license for {school.name}"
        ),
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
    if normalized_status == "active":
        license_obj.payment_status = "paid"
        license_obj.revoked_at = None
        license_obj.suspended_at = None
    elif normalized_status == "revoked":
        license_obj.revoked_at = now
        license_obj.payment_status = "revoked"
    elif normalized_status == "suspended":
        license_obj.suspended_at = now
        license_obj.payment_status = "suspended"
    elif normalized_status == "expired":
        license_obj.expiry_at = now
        license_obj.payment_status = "expired"

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

def delete_license(
    db: Session,
    license_id: UUID,
    *,
    admin=None,
    request=None,
) -> License:

    license_obj = get_license(db, license_id)

    if license_obj.status == "deleted":
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="License already deleted.",
        )

    soft_delete_license(
        db,
        license_obj,
    )

    db.commit()
    db.refresh(license_obj)

    record_audit_event(
        db,
        admin=admin,
        action="license_deleted",
        entity_type="license",
        entity_id=str(license_obj.id),
        description=f"Soft deleted license {license_obj.id}",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )

    db.commit()

    return license_obj

def get_license_statistics(db: Session) -> dict:
    """
    Returns dashboard statistics for the License Management page.
    """

    now = datetime.now(timezone.utc)

    active_count = (
        db.query(func.count(License.id))
        .filter(
            License.deleted_at.is_(None),
            License.status == "active"
        )
        .scalar()
        or 0
    )

    expired_count = (
        db.query(func.count(License.id))
        .filter(
            License.deleted_at.is_(None),
            License.status == "expired"
        )
        .scalar()
        or 0
    )

    revoked_count = (
        db.query(func.count(License.id))
        .filter(
            License.deleted_at.is_(None),
            License.status == "revoked"
        )
        .scalar()
        or 0
    )

    trial_count = (
        db.query(func.count(License.id))
        .filter(
            License.deleted_at.is_(None),
            License.license_type == "trial"
        )
        .scalar()
        or 0
    )

    expiring_count = (
        db.query(func.count(License.id))
        .filter(
            License.deleted_at.is_(None),
            License.status == "active",
            License.expiry_at >= now,
            License.expiry_at <= now + timedelta(days=30)
        )
        .scalar()
        or 0
    )

    return {
        "active_count": active_count,
        "trial_count": trial_count,
        "expired_count": expired_count,
        "revoked_count": revoked_count,
        "expiring_count": expiring_count,
        "revenue": 0
    }

def suspend_license(
    db: Session,
    license_id: UUID,
    *,
    admin=None,
    request=None,
) -> License:

    license_obj = get_license(db, license_id)

    if license_obj.status == "revoked":
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Revoked licenses cannot be suspended.",
        )

    if license_obj.status == "suspended":
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="License is already suspended.",
        )

    license_obj.status = "suspended"
    license_obj.suspended_at = datetime.now(timezone.utc)

    persist_license(db, license_obj)

    db.commit()
    db.refresh(license_obj)

    record_audit_event(
        db,
        admin=admin,
        action="license_suspended",
        entity_type="license",
        entity_id=str(license_obj.id),
        description=f"Suspended license for {license_obj.school.name}",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )

    db.commit()

    return license_obj

def reactivate_license(
    db: Session,
    license_id: UUID,
    *,
    admin=None,
    request=None,
) -> License:

    license_obj = get_license(db, license_id)

    if license_obj.status == "revoked":
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Revoked licenses cannot be reactivated.",
        )

    if license_obj.status != "suspended":
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Only suspended licenses can be reactivated.",
        )

    license_obj.status = "active"
    license_obj.suspended_at = None

    persist_license(db, license_obj)

    db.commit()
    db.refresh(license_obj)

    record_audit_event(
        db,
        admin=admin,
        action="license_reactivated",
        entity_type="license",
        entity_id=str(license_obj.id),
        description=f"Reactivated license for {license_obj.school.name}",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )

    db.commit()

    return license_obj

def revoke_license(
    db: Session,
    license_id: UUID,
    *,
    admin=None,
    request=None,
) -> License:

    license_obj = get_license(db, license_id)

    if license_obj.status == "revoked":
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="License already revoked.",
        )

    license_obj.status = "revoked"
    license_obj.revoked_at = datetime.now(timezone.utc)

    persist_license(db, license_obj)

    db.commit()
    db.refresh(license_obj)

    record_audit_event(
        db,
        admin=admin,
        action="license_revoked",
        entity_type="license",
        entity_id=str(license_obj.id),
        description=f"Revoked license for {license_obj.school.name}",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )

    db.commit()

    return license_obj

def renew_license(
    db: Session,
    license_id: UUID,
    payload=None,
    *,
    plan: str | None = None,
    payment_id: UUID | None = None,
    notes: str | None = None,
    admin=None,
    request=None,
) -> License:
    from app.services.license_renewal_service import renew_license as renew_license_service

    return renew_license_service(
        db,
        license_id,
        payload,
        plan=plan,
        payment_id=payment_id,
        notes=notes,
        admin=admin,
        request=request,
    )
