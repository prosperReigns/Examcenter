from datetime import datetime, timezone
from uuid import UUID

from sqlalchemy.orm import Session
from fastapi import HTTPException, status
from app.repositories.license_repository import update_license, get_license_by_id
from app.repositories.license_renewal_repository import create_license_renewal, renewal_statistics
from app.services.license_management_service import get_license
from app.services.license_service import (
    build_license_expiry,
    create_signed_license,
    normalize_license_type,
)
from app.services.audit_service import record_audit_event
from app.core.config import get_settings
from app.repositories.license_history_repository import (
    list_license_history,
)
from core.pricing import LICENSE_PRICES
settings = get_settings()
PLAN_DURATION_MAP = {
    "trial": 7,
    "monthly": 30,
    "quarterly": 90,
    "6_months": 180,
    "annual": 365,
    "24_months": 730,
    "lifetime": None,
}

PLAN_PRICE_MAP = {
    "trial": 0,
    "monthly": 10000,
    "quarterly": 25000,
    "6_months": 50000,
    "annual": 90000,
    "24_months": 170000,
    "lifetime": 350000,
}

def get_plan_duration(plan: str):

    if plan not in PLAN_DURATION_MAP:

        raise HTTPException(
            400,
            "Invalid renewal plan",
        )

    return PLAN_DURATION_MAP[plan]

def renew_license(
    db: Session,
    license_id,
    payload,
    *,
    admin=None,
    request=None,
):
    """
    Renew an existing license.
    This service also supports upgrades.
    """

    license_obj = get_license(db, license_id)
    old_type = license_obj.license_type
    old_expiry = license_obj.expiry_at

    new_type = normalize_license_type(
        payload.license_type
    )

    now = datetime.now(timezone.utc)

    # If the current license is still active,
    # extend from its current expiry date.
    if (
        old_expiry
        and old_expiry > now
    ):
        base_date = old_expiry
    else:
        base_date = now

    new_expiry = build_license_expiry(
        new_type,
        issued_at=base_date,
    )

    signed_license = create_signed_license(
        school=license_obj.school.name,
        machine=license_obj.machine_fingerprint,
        license_type=new_type,
        issued_at=now,
        version=license_obj.version + 1,
    )

    update_license(
        db,
        license_obj,
        license_type=new_type,
        expiry_at=new_expiry,
        signed_license=signed_license.model_dump_json(),
        version=license_obj.version + 1,
    )

    create_license_renewal(
        db,
        license_id=license_obj.id,
        old_license_type=old_type,
        new_license_type=new_type,
        old_expiry=old_expiry,
        new_expiry=new_expiry,
        amount_paid=payload.amount_paid,
        payment_reference=payload.payment_reference,
        renewed_by=admin.full_name if admin else None,
        remarks=payload.remarks,
    )

    record_audit_event(
        db,
        admin=admin,
        action="license_renewed",
        entity_type="license",
        entity_id=str(license_obj.id),
        description=f"Renewed license for {license_obj.school.name}",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )

    db.commit()
    db.refresh(license_obj)

    return license_obj


def get_available_renewal_plans():

    return [
        {
            "plan": "trial",
            "days": 7,
            "price": 0,
        },
        {
            "plan": "monthly",
            "days": 30,
            "price": 10000,
        },
        {
            "plan": "quarterly",
            "days": 90,
            "price": 25000,
        },
        {
            "plan": "6_months",
            "days": 180,
            "price": 50000,
        },
        {
            "plan": "annual",
            "days": 365,
            "price": 90000,
        },
        {
            "plan": "24_months",
            "days": 730,
            "price": 170000,
        },
        {
            "plan": "lifetime",
            "days": None,
            "price": 350000,
        },
    ]

def calculate_plan_price(
    plan: str,
) -> float:

    plan = plan.strip().lower()

    if plan not in LICENSE_PRICES["key"]:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Unsupported license plan.",
        )

    return LICENSE_PRICES[plan]

def get_license_history(
    db: Session,
    license_id: UUID,
):

    license_obj = get_license_by_id(
        db,
        license_id,
    )

    if license_obj is None:
        raise HTTPException(
            status_code=404,
            detail="License not found.",
        )

    return list_license_history(
        db,
        license_id=license_id,
    )

def get_renewal_statistics(
    db: Session,
):

    return renewal_statistics(db)