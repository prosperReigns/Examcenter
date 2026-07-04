from uuid import UUID

from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.models.license import License


def get_license_by_id(db: Session, license_id: UUID) -> License | None:
    return db.get(License, license_id)


def list_licenses(db: Session, *, search: str | None = None, offset: int = 0, limit: int = 20) -> tuple[list[License], int]:
    statement = select(License)
    count_statement = select(func.count()).select_from(License)

    if search:
        term = f"%{search.strip()}%"
        statement = statement.where(License.machine_fingerprint.ilike(term))
        count_statement = count_statement.where(License.machine_fingerprint.ilike(term))

    total = db.scalar(count_statement) or 0
    items = db.scalars(statement.order_by(License.created_at.desc()).offset(offset).limit(limit)).all()
    return items, total


def create_license_record(
    db: Session,
    *,
    school_id: UUID,
    machine_fingerprint: str,
    license_type: str,
    issued_at,
    expiry_at,
    signed_license: str,
    version: int,
) -> License:
    license_obj = License(
        school_id=school_id,
        machine_fingerprint=machine_fingerprint.strip(),
        license_type=license_type.strip().lower(),
        issued_at=issued_at,
        expiry_at=expiry_at,
        status="active",
        signed_license=signed_license,
        version=version,
    )
    db.add(license_obj)
    db.flush()
    return license_obj


def persist_license(db: Session, license_obj: License) -> License:
    db.add(license_obj)
    db.flush()
    return license_obj