from __future__ import annotations

import base64
import hashlib
import json
from datetime import datetime, timezone
from pathlib import Path
from typing import Any
from uuid import UUID

from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding
from pydantic import ValidationError

from app.schemas.license import LicensePackage, LicensePayload, LicenseVerificationResult, SignedLicenseResponse

BASE_DIR = Path(__file__).resolve().parents[2]
KEYS_DIR = BASE_DIR / "keys"
PRIVATE_KEY_PATH = KEYS_DIR / "private.pem"
PUBLIC_KEY_PATH = KEYS_DIR / "public.pem"


def _canonical_json(data: dict[str, Any]) -> bytes:
    return json.dumps(data, sort_keys=True, separators=(",", ":")).encode("utf-8")


def _payload_checksum(payload: LicensePayload) -> str:
    return hashlib.sha256(_canonical_json(payload.model_dump(mode="json"))).hexdigest()


def _as_aware(value: datetime) -> datetime:
    if value.tzinfo is None:
        return value.replace(tzinfo=timezone.utc)
    return value


def _load_private_key() -> Any:
    private_key_bytes = PRIVATE_KEY_PATH.read_bytes()
    return serialization.load_pem_private_key(private_key_bytes, password=None)


def _load_public_key() -> Any:
    public_key_bytes = PUBLIC_KEY_PATH.read_bytes()
    return serialization.load_pem_public_key(public_key_bytes)


def generate_license_package(
    *,
    school: str,
    machine: str,
    license_type: str,
    license_id: UUID | str | None = None,
    school_id: UUID | str | None = None,
    school_code: str | None = None,
    product_code: str = "cbt",
    product_name: str = "CBT Examination Software",
    plan_code: str | None = None,
    plan_name: str = "standard",
    duration_months: int = 0,
    is_trial: bool = False,
    features: dict[str, Any] | None = None,
    issued_at: datetime | None = None,
    expiry: datetime | None = None,
    public_key_version: str = "v1",
    package_version: int = 1,
    version: int = 1,
) -> LicensePackage:
    """
    Generates the signed offline package expected by the PHP application.
    """

    issued_at = issued_at or datetime.now(timezone.utc)
    payload = LicensePayload(
        license_id=license_id,
        school_id=school_id,
        school=school,
        school_code=school_code,
        product_code=product_code,
        product_name=product_name,
        machine=machine,
        license_type=license_type,
        plan_code=plan_code or license_type,
        plan_name=plan_name,
        duration_months=duration_months,
        is_trial=is_trial,
        features=features or {},
        issued_at=issued_at,
        expiry=expiry,
        public_key_version=public_key_version,
        package_version=package_version,
        version=version,
    )
    checksum = _payload_checksum(payload)
    private_key = _load_private_key()
    signature = private_key.sign(
        _canonical_json(payload.model_dump(mode="json")),
        padding.PKCS1v15(),
        hashes.SHA256(),
    )
    return LicensePackage(
        package_version=package_version,
        generated_at=issued_at,
        public_key_version=public_key_version,
        license=payload,
        signature=base64.b64encode(signature).decode(),
        checksum=checksum,
    )


def generate_license(
    *,
    school: str,
    machine: str,
    license_type: str,
    license_id: UUID | str | None = None,
    school_id: UUID | str | None = None,
    school_code: str | None = None,
    product_code: str = "cbt",
    product_name: str = "CBT Examination Software",
    plan_code: str | None = None,
    plan_name: str = "standard",
    duration_months: int = 0,
    is_trial: bool = False,
    features: dict[str, Any] | None = None,
    issued_at: datetime | None = None,
    expiry: datetime | None = None,
    public_key_version: str = "v1",
    package_version: int = 1,
    version: int = 1,
) -> SignedLicenseResponse:
    """
    Generates the legacy flat signed license payload.

    The stored public API remains backward compatible while new callers can use
    generate_license_package for the explicit offline package envelope.
    """

    package = generate_license_package(
        license_id=license_id,
        school_id=school_id,
        school_code=school_code,
        school=school,
        machine=machine,
        product_code=product_code,
        product_name=product_name,
        license_type=license_type,
        plan_code=plan_code,
        plan_name=plan_name,
        duration_months=duration_months,
        is_trial=is_trial,
        features=features,
        issued_at=issued_at,
        expiry=expiry,
        public_key_version=public_key_version,
        package_version=package_version,
        version=version,
    )
    return SignedLicenseResponse(
        **package.license.model_dump(),
        signature=package.signature,
        checksum=package.checksum,
        checksum_algorithm=package.checksum_algorithm,
        signature_algorithm=package.signature_algorithm,
    )


def _load_license_document(license_document: str | dict[str, Any]) -> dict[str, Any]:
    try:
        return json.loads(license_document) if isinstance(license_document, str) else dict(license_document)
    except json.JSONDecodeError as exc:
        raise ValueError(f"Invalid JSON: {exc.msg}") from exc


def _split_document(data: dict[str, Any]) -> tuple[dict[str, Any], str | None, str | None, dict[str, Any]]:
    """
    Accepts both the new nested package and the legacy flat package.
    """

    if "license" in data and isinstance(data["license"], dict):
        metadata = {
            "package_type": data.get("package_type"),
            "package_version": data.get("package_version"),
            "generated_at": data.get("generated_at"),
            "public_key_version": data.get("public_key_version"),
            "signature_algorithm": data.get("signature_algorithm"),
            "checksum_algorithm": data.get("checksum_algorithm"),
        }
        return dict(data["license"]), data.get("signature"), data.get("checksum"), metadata

    legacy = dict(data)
    signature = legacy.pop("signature", None)
    checksum = legacy.pop("checksum", None)
    checksum_algorithm = legacy.pop("checksum_algorithm", None)
    signature_algorithm = legacy.pop("signature_algorithm", None)
    metadata = {
        "package_type": "legacy_flat_license",
        "package_version": legacy.get("package_version"),
        "public_key_version": legacy.get("public_key_version"),
        "signature_algorithm": signature_algorithm,
        "checksum_algorithm": checksum_algorithm,
    }
    return legacy, signature, checksum, metadata


def verify_license(license_document: str | dict[str, Any]) -> LicenseVerificationResult:
    try:
        data = _load_license_document(license_document)
    except ValueError as exc:
        return LicenseVerificationResult(valid=False, error=str(exc))

    payload_data, signature, checksum, metadata = _split_document(data)
    if not signature:
        return LicenseVerificationResult(valid=False, error="Missing signature")
    if not checksum:
        return LicenseVerificationResult(valid=False, error="Missing checksum")

    try:
        payload = LicensePayload.model_validate(payload_data)
    except ValidationError as exc:
        return LicenseVerificationResult(valid=False, error="Invalid license payload", metadata={"details": exc.errors()})

    expected_checksum = _payload_checksum(payload)
    if checksum != expected_checksum:
        return LicenseVerificationResult(
            valid=False,
            payload=payload,
            error="Checksum mismatch",
            metadata={"expected_checksum": expected_checksum},
        )

    try:
        signature_bytes = base64.b64decode(signature)
    except Exception as exc:  # noqa: BLE001
        return LicenseVerificationResult(valid=False, payload=payload, error=f"Invalid signature encoding: {exc}")

    try:
        public_key = _load_public_key()
        public_key.verify(
            signature_bytes,
            _canonical_json(payload.model_dump(mode="json")),
            padding.PKCS1v15(),
            hashes.SHA256(),
        )
    except Exception as exc:  # noqa: BLE001
        return LicenseVerificationResult(valid=False, payload=payload, error=f"Signature verification failed: {exc}")

    if payload.expiry is not None and _as_aware(payload.expiry) < datetime.now(timezone.utc):
        return LicenseVerificationResult(valid=False, payload=payload, error="License expired")

    return LicenseVerificationResult(
        valid=True,
        payload=payload,
        metadata={
            "checksum": checksum,
            "public_key_version": payload.public_key_version,
            "signature_algorithm": "rsa-pkcs1v15-sha256",
            **{key: value for key, value in metadata.items() if value is not None},
        },
    )
