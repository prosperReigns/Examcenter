from datetime import datetime, timedelta

import jwt
import secrets

from fastapi import HTTPException
from app.core.config import get_settings

settings = get_settings()

def create_activation_token(
    license,
):

    payload = {

        "license_id": str(license.id),

        "fingerprint": license.machine_fingerprint,

        "exp": datetime.utcnow() + timedelta(minutes=10),

    }

    return jwt.encode(

        payload,

        settings.secret_key,

        algorithm="HS256",

    )

def generate_token():

    return secrets.token_urlsafe(48)

def create_activation_token(

    db,

    purchase_session,

    license,

    fingerprint,

):
    token = ActivationToken(

        token=generate_token(),

        purchase_session_id=purchase_session.id,

        license_id=license.id,

        machine_fingerprint=fingerprint,

        expires_at=datetime.utcnow() + timedelta(minutes=15),

    )

    return activation_token_repository.create(
        db,
        token,
    )

def validate_token(
    activation_token,
):
    if activation_token is None:

        raise HTTPException(
            404,
            "Activation token not found.",
        )

    if activation_token.revoked_at:

        raise HTTPException(
            403,
            "Activation token revoked.",
        )

    if activation_token.used_at:

        raise HTTPException(
            403,
            "Activation token already used.",
        )

    if activation_token.expires_at < datetime.utcnow():

        raise HTTPException(
            403,
            "Activation token expired.",
        )

def validate_machine(

    activation_token,

    fingerprint,

):
    if (
        activation_token.machine_fingerprint
        != fingerprint
    ):

        raise HTTPException(
            403,
            "Machine fingerprint mismatch.",
        )

def consume_token(

    db,

    activation_token,

):
    return activation_token_repository.mark_used(

        db,

        activation_token,

    )