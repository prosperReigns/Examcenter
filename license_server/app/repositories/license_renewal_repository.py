from uuid import UUID

from sqlalchemy import select, desc, func
from sqlalchemy.orm import Session

from app.models.license_renewal import LicenseRenewal


def create_license_renewal(db: Session, **kwargs) -> LicenseRenewal:
    renewal = LicenseRenewal(**kwargs)
    db.add(renewal)
    db.flush()
    return renewal


def get_license_renewals(
    db: Session,
    license_id: UUID,
):
    statement = (
        select(LicenseRenewal)
        .where(LicenseRenewal.license_id == license_id)
        .order_by(LicenseRenewal.renewed_at.desc())
    )

    return db.scalars(statement).all()

def save_license_renewal(
    db: Session,
    renewal,
):
    db.add(renewal)
    db.flush()

    return renewal

def get_latest_license_renewal(
    db: Session,
    license_id: UUID,
):

    statement = (
        select(LicenseRenewal)
        .where(
            LicenseRenewal.license_id == license_id
        )
        .order_by(
            desc(LicenseRenewal.renewed_at)
        )
        .limit(1)
    )

    return db.scalar(statement)


def get_license_renewal_by_payment(
    db: Session,
    payment_id: UUID,
) -> LicenseRenewal | None:
    statement = (
        select(LicenseRenewal)
        .where(LicenseRenewal.payment_id == payment_id)
        .order_by(desc(LicenseRenewal.renewed_at))
        .limit(1)
    )

    return db.scalar(statement)

def renewal_statistics(
    db: Session,
):

    total = db.scalar(
        select(func.count())
        .select_from(LicenseRenewal)
    ) or 0

    return {

        "total": total,

        "completed": total,

        "failed": 0,

        "pending": 0,

    }

def get_license_renewal(
    db: Session,
    renewal_id: UUID,
) -> LicenseRenewal | None:
    return db.get(LicenseRenewal, renewal_id)
