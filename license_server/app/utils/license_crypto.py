from __future__ import annotations

import base64
import json
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding
from pydantic import ValidationError

from app.schemas.license import LicensePayload, LicenseVerificationResult, SignedLicenseResponse

BASE_DIR = Path(__file__).resolve().parents[2]
KEYS_DIR = BASE_DIR / "keys"
PRIVATE_KEY_PATH = KEYS_DIR / "private.pem"
PUBLIC_KEY_PATH = KEYS_DIR / "public.pem"


def _canonical_json(data: dict[str, Any]) -> bytes:
    return json.dumps(data, sort_keys=True, separators=(",", ":")).encode("utf-8")


def _load_private_key() -> Any:
    private_key_bytes = PRIVATE_KEY_PATH.read_bytes()
    return serialization.load_pem_private_key(private_key_bytes, password=None)


def _load_public_key() -> Any:
    public_key_bytes = PUBLIC_KEY_PATH.read_bytes()
    return serialization.load_pem_public_key(public_key_bytes)


def generate_license(
    *,
    school: str,
    machine: str,
    license_type: str,
    issued_at: datetime | None = None,
    expiry: datetime | None = None,
    version: int = 1,
):
    """
    Generates the exact license format expected
    by the PHP application.
    {
        payload: "...json...",
        signature:"..."
    }
    """

    issued_at = issued_at or datetime.now(timezone.utc)
    payload = LicensePayload(school=school, machine=machine, license_type=license_type, issued_at=issued_at, expiry=expiry, version=version,)
    payload_json = json.dumps(payload.model_dump(mode="json"), separators=(",", ":"), sort_keys=True,)
    private_key = _load_private_key()
    signature = private_key.sign(payload_json.encode(), padding.PKCS1v15(), hashes.SHA256(),)
    document = {"payload": payload_json, "signature": base64.b64encode(signature).decode(),}

    return base64.b64encode(
        json.dumps(document).encode()
    ).decode()


def verify_license(license_document: str | dict[str, Any]) -> LicenseVerificationResult:
    try:
        data = json.loads(license_document) if isinstance(license_document, str) else dict(license_document)
    except json.JSONDecodeError as exc:
        return LicenseVerificationResult(valid=False, error=f"Invalid JSON: {exc.msg}")

    signature = data.pop("signature", None)
    if not signature:
        return LicenseVerificationResult(valid=False, error="Missing signature")

    try:
        payload = LicensePayload.model_validate(data)
    except ValidationError as exc:
        return LicenseVerificationResult(valid=False, error="Invalid license payload", metadata={"details": exc.errors()})

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

    if payload.expiry is not None and payload.expiry < datetime.now(timezone.utc):
        return LicenseVerificationResult(valid=False, payload=payload, error="License expired")

    return LicenseVerificationResult(valid=True, payload=payload)
