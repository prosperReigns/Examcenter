from __future__ import annotations

from datetime import datetime, timedelta, timezone
from typing import Any

from app.core.config import get_settings
from app.schemas.license import LicenseVerificationResult, SignedLicenseResponse
from app.utils.license_crypto import generate_license, verify_license

settings = get_settings()

LICENSE_DURATION_MAP = {
    "trial": 7,
    "6_month": 180,
    "12_month": 365,
    "24_month": 730
}


def normalize_license_type(license_type: str) -> str:
    normalized = (
        license_type
        .strip()
        .lower()
    )
    allowed = {
        "trial",
        "6_month",
        "12_month",
        "24_month"
    }

    if normalized not in allowed:
        raise ValueError(
            f"Unsupported license type: {license_type}"
        )
    return normalized

def get_license_duration_days(license_type: str) -> int | None:
    normalized = normalize_license_type(license_type)
    duration_days = LICENSE_DURATION_MAP[normalized]
    return None if duration_days <= 0 else duration_days


def build_license_expiry(
    license_type: str,
    issued_at: datetime | None = None
) -> datetime | None:
    issued_at = (issued_at or datetime.now(timezone.utc))
    normalized = normalize_license_type(license_type)

    days = LICENSE_DURATION_MAP[normalized]

    return issued_at + timedelta(days=days)

def calculate_renewal_expiry(
    license_type: str,
    current_expiry: datetime | None,
) -> datetime | None:
    """
    Renewal policy:

    - Active license:
        extend from existing expiry.

    - Expired license:
        extend from today.

    - Lifetime license:
        returns None.
    """

    duration_days = get_license_duration_days(license_type)

    if duration_days is None:
        return None

    now = datetime.now(timezone.utc)

    if current_expiry is None:
        base_date = now

    elif current_expiry > now:
        base_date = current_expiry

    else:
        base_date = now

    return base_date + timedelta(days=duration_days)


def create_signed_license(
    *,
    school: str,
    machine: str,
    license_type: str,
    plan_name: str,
    duration_months: int,
    is_trial: bool,
    issued_at: datetime | None = None,
    version: int = 1,
) -> SignedLicenseResponse:
    issued_at = (issued_at or datetime.now(timezone.utc))
    normalized = normalize_license_type(license_type)
    expiry = build_license_expiry(normalized, issued_at)

    return generate_license(
        school=school,
        machine=machine,
        license_type=normalized,
        plan_name=plan_name,
        duration_months=duration_months,
        is_trial=is_trial,
        issued_at=issued_at,
        expiry=expiry,
        version=version,
    )


def verify_signed_license(license_document: str | dict[str, Any]) -> LicenseVerificationResult:
    return verify_license(license_document)


def is_activation_allowed(current_activation_count: int, activation_limit: int | None = None) -> bool:
    effective_limit = settings.default_license_activation_limit if activation_limit is None else activation_limit
    if effective_limit <= 0:
        return True
    return current_activation_count < effective_limit
