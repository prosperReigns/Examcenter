from uuid import UUID
import secrets

from datetime import datetime, timedelta

from fastapi import HTTPException, status
from sqlalchemy.orm import Session

from app.services.license_management_service import get_license
from app.services.license_package_service import license_package_document
from app.models.license_download import LicenseDownload

def download_license_document(
    db: Session,
    license_id: UUID,
) -> tuple[str, str]:
    """
    Returns:
        filename,
        signed_license_json
    """

    license_obj = get_license(db, license_id)

    if not license_obj.signed_license:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Signed license not found.",
        )

    school_name = (
        license_obj.school.name
        if license_obj.school
        else "license"
    )

    filename = (
        school_name
        .replace(" ", "_")
        .lower()
        + "_license.package.json"
    )

    return (
        filename,
        license_package_document(license_obj),
    )

TOKEN_EXPIRY_MINUTES = 15



def create_download_token(
    db: Session,
    license_id: int
):


    token = secrets.token_urlsafe(
        48
    )


    record = LicenseDownload(

        license_id=license_id,

        token=token,

        expires_at=
            datetime.utcnow()
            +
            timedelta(
                minutes=
                TOKEN_EXPIRY_MINUTES
            )

    )


    db.add(record)

    db.commit()

    db.refresh(record)


    return token





def validate_download_token(
    db: Session,
    token: str
):


    record = (

        db.query(
            LicenseDownload
        )

        .filter(

            LicenseDownload.token
            ==
            token

        )

        .first()

    )


    if not record:

        return None



    if record.downloaded:

        return None



    if (
        record.expires_at
        <
        datetime.utcnow()
    ):

        return None



    return record