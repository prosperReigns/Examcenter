from datetime import datetime, timezone, timedelta

import app.auth.security as security
from app.services.license_service import build_license_expiry, create_signed_license, is_activation_allowed, normalize_license_type, verify_signed_license


def test_password_hash_round_trip():
    hashed = security.get_password_hash("StrongPassword123!")
    assert security.verify_password("StrongPassword123!", hashed)
    assert not security.verify_password("WrongPassword", hashed)


def test_license_sign_and_verify_round_trip():
    issued_at = datetime.now(timezone.utc)
    signed = create_signed_license(
        school="Example School",
        machine="MACHINE-123",
        license_type="Monthly",
        issued_at=issued_at,
        version=1,
    )

    verification = verify_signed_license(signed.model_dump())
    assert verification.valid is True
    assert verification.payload is not None
    assert verification.payload.school == "Example School"
    assert verification.payload.machine == "MACHINE-123"


def test_license_signature_rejects_tampering():
    signed = create_signed_license(
        school="Example School",
        machine="MACHINE-123",
        license_type="monthly",
    )
    tampered = signed.model_dump()
    tampered["machine"] = "OTHER-MACHINE"

    verification = verify_signed_license(tampered)
    assert verification.valid is False


def test_license_type_and_expiry_helpers():
    assert normalize_license_type("Annual") == "annual"
    expiry = build_license_expiry("monthly", issued_at=datetime(2026, 1, 1, tzinfo=timezone.utc))
    assert expiry == datetime(2026, 1, 31, tzinfo=timezone.utc)
    assert is_activation_allowed(0)
    assert not is_activation_allowed(1, activation_limit=1)
    assert is_activation_allowed(5, activation_limit=0)
