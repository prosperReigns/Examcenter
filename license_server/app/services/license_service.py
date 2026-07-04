from __future__ import annotations

from datetime import datetime, timedelta, timezone
from typing import Any

from app.core.config import get_settings
from app.schemas.license import LicenseVerificationResult, SignedLicenseResponse
from app.utils.license_crypto import generate_license, verify_license

settings = get_settings()

LICENSE_DURATION_MAP = {
    "demo": settings.demo_license_duration_days,
    "monthly": settings.monthly_license_duration_days,
    "quarterly": settings.quarterly_license_duration_days,
    "annual": settings.annual_license_duration_days,
    "lifetime": settings.lifetime_license_duration_days,
}


def normalize_license_type(license_type: str) -> str:
    normalized = license_type.strip().lower()
    if normalized not in LICENSE_DURATION_MAP:
        raise ValueError(f"Unsupported license type: {license_type}")
    return normalized


def get_license_duration_days(license_type: str) -> int | None:
    normalized = normalize_license_type(license_type)
    duration_days = LICENSE_DURATION_MAP[normalized]
    return None if duration_days <= 0 else duration_days


def build_license_expiry(license_type: str, issued_at: datetime | None = None) -> datetime | None:
    duration_days = get_license_duration_days(license_type)
    if duration_days is None:
        return None
    issued_at = issued_at or datetime.now(timezone.utc)
    return issued_at + timedelta(days=duration_days)


def create_signed_license(*, school: str, machine: str, license_type: str, issued_at: datetime | None = None, version: int = 1) -> SignedLicenseResponse:
    normalized_license_type = normalize_license_type(license_type)
    issued_at = issued_at or datetime.now(timezone.utc)
    expiry = build_license_expiry(normalized_license_type, issued_at=issued_at)
    return generate_license(
        school=school,
        machine=machine,
        license_type=normalized_license_type,
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
