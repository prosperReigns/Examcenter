from uuid import UUID, uuid4
from datetime import datetime, timezone

from sqlalchemy import func, select
from sqlalchemy.orm import Session, joinedload
from sqlalchemy.orm import selectinload
from sqlalchemy import or_
from app.models.license import License

def get_license_by_id(db: Session, license_id: UUID) -> License | None:
    return db.get(License, license_id)

def get_license_details(
    db: Session,
    license_id: UUID,
) -> License | None:
    statement = (
        select(License)
        .options(
            joinedload(License.school),
            joinedload(License.activations),
        )
        .where(
            License.id == license_id
        )
    )

    return db.scalar(statement)

def list_licenses(db: Session, *, search: str | None = None, offset: int = 0, limit: int = 20) -> tuple[list[License], int]:
    statement = (
        select(License).where(License.deleted_at.is_(None))
        .options(
            selectinload(License.school),
            selectinload(License.activations),
        )
    )
    count_statement = (select(func.count()).select_from(License).where(License.deleted_at.is_(None)))

    if search:
        term = f"%{search.strip()}%"
        statement = statement.where(
            condition = or_(
                License.machine_fingerprint.ilike(term),
                License.plan_name.ilike(term),
                License.license_type.ilike(term)
            )
        )

        count_statement = count_statement.where(
            License.machine_fingerprint.ilike(term)|
            License.plan_name.ilike(term)|
            License.license_type.ilike(term)
        )

    total = db.scalar(count_statement) or 0
    items = db.scalars(statement.order_by(License.created_at.desc()).offset(offset).limit(limit)).all()
    return items, total


def create_license_record(
    db: Session,
    *,
    school_id: UUID,
    machine_fingerprint: str,
    license_type: str,
    plan_name: str,
    duration_months: int,
    is_trial: bool,
    issued_at,
    expiry_at,
    payment_status: str,
    flutterwave_transaction_id: str | None,
    flutterwave_reference: str | None,
    amount_paid: int,
    currency: str,
    signed_license: str,
    activation_count: int,
    max_activations: int,
    version: int,
    renewed_from: UUID | None = None,
) -> License:
    license_obj = License(
        id=uuid4(),
        school_id=school_id,
        machine_fingerprint=machine_fingerprint.strip(),
        license_type=license_type.strip().lower(),
        plan_name=plan_name.strip(),
        duration_months=duration_months,
        is_trial=is_trial,
        issued_at=issued_at,
        expiry_at=expiry_at,
        status="active",
        payment_status=payment_status,
        flutterwave_transaction_id=flutterwave_transaction_id,
        flutterwave_reference=flutterwave_reference,
        amount_paid=amount_paid,
        currency=currency,
        signed_license=signed_license,
        activation_count=activation_count,
        max_activations=max_activations,
        last_activation_at=None,
        renewed_from=renewed_from,
        version=version,
    )

    return license_obj


def persist_license(db: Session, license_obj: License) -> License:
    db.add(license_obj)
    db.flush()
    return license_obj

def renew_license_record(
    db: Session,
    license_obj: License,
    *,
    new_expiry: datetime | None,
):
    license_obj.expiry_at = new_expiry
    license_obj.last_renewed_at = datetime.now(timezone.utc)
    license_obj.renewal_count += 1
    license_obj.status = "active"

    db.add(license_obj)
    db.flush()

    return license_obj


def update_license(
    db: Session,
    license_obj: License,
    *,
    license_type: str,
    expiry_at: datetime | None,
    signed_license: str,
    version: int,
):
    license_obj.license_type = license_type
    license_obj.expiry_at = expiry_at
    license_obj.signed_license = signed_license
    license_obj.version = version

    db.add(license_obj)
    db.flush()

    return license_obj

def soft_delete_license(
    db: Session,
    license_obj: License,
) -> License:
    """
    Soft delete a license instead of permanently removing it.
    """

    license_obj.deleted_at = datetime.now(timezone.utc)
    license_obj.status = "deleted"

    db.add(license_obj)
    db.flush()

    return license_obj


def get_license(
    db: Session,
    license_id: UUID,
) -> License | None:
    return get_license_by_id(db, license_id)


def list_expired_licenses(db: Session) -> list[License]:
    now = datetime.now(timezone.utc)
    statement = select(License).where(
        License.deleted_at.is_(None),
        License.expiry_at.is_not(None),
        License.expiry_at < now,
    )
    return list(db.scalars(statement).all())
