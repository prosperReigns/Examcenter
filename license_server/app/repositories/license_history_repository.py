from uuid import UUID

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.models.license_history import LicenseHistory


def create_history_record(
    db: Session,
    *,
    license_id: UUID,
    version: int,
    license_type: str,
    issued_at,
    expiry_at,
    signed_license: str,
):
    history = LicenseHistory(
        license_id=license_id,
        version=version,
        license_type=license_type,
        issued_at=issued_at,
        expiry_at=expiry_at,
        signed_license=signed_license,
    )

    db.add(history)

    db.flush()

    return history


def list_license_history(
    db: Session,
    *,
    license_id: UUID,
):
    return (
        db.scalars(
            select(LicenseHistory)
            .where(LicenseHistory.license_id == license_id)
            .order_by(LicenseHistory.version.desc())
        )
        .all()
    )