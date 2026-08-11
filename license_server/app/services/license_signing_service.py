import json
from datetime import datetime

import jwt

from app.core.config import get_settings

settings = get_settings()

def build_payload(
    license,
):
    """
    Build the payload that will be
    cryptographically signed.
    """

    return {

        "license_id": str(license.id),

        "school_id": str(license.school_id),

        "customer_id": str(license.customer_id),

        "product_code": license.product_code,

        "product_name": license.product_name,

        "version": license.version,

        "plan_name": license.plan_name,

        "plan_code": license.plan_code,

        "expiry_at": license.expiry_at.isoformat(),

        "fingerprint": license.machine_fingerprint,

        "features": license.features,

        "issued_at": datetime.utcnow().isoformat(),

    }

def sign_license(
    payload,
):
    """
    Sign using RSA private key.
    """

    return jwt.encode(

        payload,

        settings.private_key,

        algorithm="RS256",

    )

def verify_signature(
    signed_license,
):
    """
    Verify signature.
    """

    return jwt.decode(

        signed_license,

        settings.public_key,

        algorithms=["RS256"],

    )

def export_license(
    license,
):
    """
    Generate downloadable license.
    """

    payload = build_payload(
        license,
    )

    signed = sign_license(
        payload,
    )

    return signed


class LicenseSigningService:
    def __init__(self, db):
        self.db = db

    def issue_license(
        self,
        *,
        purchase_session_id,
    ):
        from app.models.purchase_session import PurchaseSession
        from app.repositories.license_repository import get_license_by_id
        from app.services.purchase_orchestration_service import complete_purchase

        purchase_session = self.db.get(
            PurchaseSession,
            purchase_session_id,
        )
        if purchase_session is None:
            raise ValueError("Purchase session not found.")

        if purchase_session.license_id:
            license_obj = get_license_by_id(
                self.db,
                purchase_session.license_id,
            )
            if license_obj is not None:
                return license_obj

        complete_purchase(
            self.db,
            purchase_session,
        )
        self.db.refresh(purchase_session)
        return get_license_by_id(
            self.db,
            purchase_session.license_id,
        )

def import_license(
    signed_license,
):
    """
    Read downloaded license.
    """

    return verify_signature(
        signed_license,
    )
