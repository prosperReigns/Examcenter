from .signer import sign_license

def export_license(

    license,

):

    payload = {

        "license_id": str(license.id),

        "school": license.school.name,

        "product": license.product.name,

        "plan": license.plan_name,

        "expiry": license.expiry_at.isoformat(),

        "machine": license.machine_fingerprint,

        "features": license.features,

    }

    signature = sign_license(
        payload
    )

    return {

        "payload": payload,

        "signature": signature,

    }