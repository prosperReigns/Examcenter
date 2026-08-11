import json
import base64
from datetime import datetime, timezone

import jwt

from fastapi import HTTPException

from app.core.config import get_settings

settings = get_settings()

def decode_license(
    license_data: str,
) -> dict:
    """
    Decode the downloaded license.

    Raises an exception if malformed.
    """

    try:

        payload = jwt.decode(

            license_data,

            settings.public_key,

            algorithms=["RS256"],

        )

    except Exception:

        raise HTTPException(
            401,
            "Invalid license.",
        )

    return payload

def verify_signature(
    license_data: str,
):
    """
    Verify digital signature.
    """

    return decode_license(
        license_data,
    )

def verify_expiry(
    payload: dict,
):
    """
    Ensure license has not expired.
    """

    expiry = datetime.fromisoformat(
        payload["expiry_at"]
    )

    if expiry < datetime.now(timezone.utc):

        raise HTTPException(
            403,
            "License expired.",
        )

def verify_machine(
    payload: dict,
    fingerprint: str,
):
    """
    Verify machine fingerprint.
    """

    if payload["fingerprint"] != fingerprint:

        raise HTTPException(
            403,
            "Machine mismatch.",
        )


def verify_product(
    payload: dict,
    product_code: str,
):
    """
    Verify licensed product.
    """

    if payload["product_code"] != product_code:

        raise HTTPException(
            403,
            "Wrong product.",
        )

def verify_version(
    payload: dict,
    version: str,
):
    """
    Prevent using another product version.
    """

    if payload["version"] != version:

        raise HTTPException(
            403,
            "Version mismatch.",
        )

def verify_features(
    payload: dict,
):
    """
    Return licensed features.
    """

    return payload.get(
        "features",
        [],
    )

def verify_plan(
    payload: dict,
):
    """
    Return purchased plan.
    """

    return payload.get(
        "plan_name",
    )

def verify_school(
    payload: dict,
    school_code: str,
):
    """
    Ensure license belongs
    to the current school.
    """

    if payload["school_code"] != school_code:

        raise HTTPException(
            403,
            "Wrong school.",
        )

def verify_license(

    license_data: str,

    fingerprint: str,

    product_code: str,

    version: str,

    school_code: str,

):
    """
    Complete offline verification.
    """

    payload = verify_signature(
        license_data,
    )

    verify_machine(
        payload,
        fingerprint,
    )

    verify_product(
        payload,
        product_code,
    )

    verify_version(
        payload,
        version,
    )

    verify_school(
        payload,
        school_code,
    )

    verify_expiry(
        payload,
    )

    features = verify_features(
        payload,
    )

    plan = verify_plan(
        payload,
    )

    return {

        "status": "valid",

        "plan": plan,

        "features": features,

        "payload": payload,

    }