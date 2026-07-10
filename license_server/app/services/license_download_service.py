from uuid import UUID

from fastapi import HTTPException, status
from sqlalchemy.orm import Session

from app.services.license_management_service import get_license


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
        + "_license.dat"
    )

    return (
        filename,
        license_obj.signed_license,
    )