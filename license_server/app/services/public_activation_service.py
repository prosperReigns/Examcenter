from datetime import datetime

import jwt

from sqlalchemy.orm import Session

from fastapi import HTTPException

from app.core.config import get_settings

from app.models.license import License

from app.repositories.license_repository import (
    get_license_by_id,
)

from app.services.license_download_service import (
    build_signed_license,
)

settings = get_settings()

def verify_activation_token(
    token: str,
):
    """
    Validate the activation token.
    """

    try:

        payload = jwt.decode(
            token,
            settings.secret_key,
            algorithms=["HS256"],
        )

    except jwt.ExpiredSignatureError:

        raise HTTPException(
            401,
            "Activation token expired.",
        )

    except jwt.InvalidTokenError:

        raise HTTPException(
            401,
            "Invalid activation token.",
        )

    return payload

def validate_machine(
    payload,
    fingerprint: str,
):
    """
    Ensure token belongs
    to this machine.
    """

    if payload["fingerprint"] != fingerprint:

        raise HTTPException(
            403,
            "Machine fingerprint mismatch.",
        )
    
def load_license(
    db: Session,
    payload,
) -> License:

    license = get_license_by_id(
        db,
        payload["license_id"],
    )

    if license is None:

        raise HTTPException(
            404,
            "License not found.",
        )

    return license

def validate_license(
    license: License,
):
    """
    Validate license.
    """

    if not license.is_active:

        raise HTTPException(
            403,
            "License inactive.",
        )

    if license.is_revoked:

        raise HTTPException(
            403,
            "License revoked.",
        )

    if license.expiry_at < datetime.utcnow():

        raise HTTPException(
            403,
            "License expired.",
        )

def generate_license(
    license: License,
):
    """
    Build signed license.
    """

    return build_signed_license(
        license,
    )

def activate(
    db: Session,
    activation_token: str,
    fingerprint: str,
):
    """
    Activate CBT installation.
    """

    payload = verify_activation_token(
        activation_token,
    )

    validate_machine(
        payload,
        fingerprint,
    )

    license = load_license(
        db,
        payload,
    )

    validate_license(
        license,
    )

    signed_license = generate_license(
        license,
    )

    return {

        "status": "success",

        "license": signed_license,

        "expires_at": license.expiry_at,

    }