from __future__ import annotations

import json
import logging
from typing import Any

from fastapi import HTTPException, status

from app.models.license import License
from app.schemas.license import LicensePackage
from app.services.license_service import create_license_package, verify_signed_license

logger = logging.getLogger(__name__)


def _package_to_document(package: LicensePackage) -> str:
    return package.model_dump_json()


def _stored_document_is_valid(document: str) -> bool:
    verification = verify_signed_license(document)
    return verification.valid


def parse_license_package(document: str | dict[str, Any]) -> LicensePackage:
    """
    Parse and verify a nested offline license package.
    """

    try:
        data = json.loads(document) if isinstance(document, str) else dict(document)
    except json.JSONDecodeError as exc:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail=f"Invalid license package JSON: {exc.msg}",
        ) from exc

    if "license" not in data:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Document is a legacy flat license, not a license package.",
        )

    verification = verify_signed_license(data)
    if not verification.valid:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail=verification.error or "License package verification failed.",
        )

    return LicensePackage.model_validate(data)


def generate_package_for_license(license_obj: License) -> LicensePackage:
    """
    Build a signed offline package from the persisted license aggregate.
    """

    if license_obj.school is None:
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail="License is missing its school relationship.",
        )

    return create_license_package(
        license_id=license_obj.id,
        school_id=license_obj.school_id,
        school_code=license_obj.school.code,
        school=license_obj.school.name,
        machine=license_obj.machine_fingerprint,
        product_code="cbt",
        product_name="CBT Examination Software",
        license_type=license_obj.license_type,
        plan_code=license_obj.license_type,
        plan_name=license_obj.plan_name,
        duration_months=license_obj.duration_months,
        is_trial=license_obj.is_trial,
        features={},
        issued_at=license_obj.issued_at,
        expiry=license_obj.expiry_at,
        public_key_version="v1",
        package_version=1,
        version=license_obj.version,
    )


def license_package_document(license_obj: License) -> str:
    """
    Return a nested signed package document suitable for offline verification.

    Legacy flat documents are intentionally regenerated into the nested package
    envelope; the flat verifier still accepts them for backward compatibility.
    """

    if license_obj.signed_license:
        try:
            data = json.loads(license_obj.signed_license)
        except json.JSONDecodeError:
            logger.warning("Stored license %s is not valid JSON; regenerating package.", license_obj.id)
        else:
            if "license" in data and _stored_document_is_valid(license_obj.signed_license):
                return license_obj.signed_license
            if "license" in data:
                logger.warning("Stored package for license %s failed verification; regenerating.", license_obj.id)

    return _package_to_document(generate_package_for_license(license_obj))
