from uuid import UUID

from sqlalchemy.orm import Session

from app.repositories.license_renewal_repository import (
    get_license_renewals,
)


def get_renewal_history(
    db: Session,
    license_id: UUID,
):
    return get_license_renewals(
        db,
        license_id,
    )