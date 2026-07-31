from uuid import UUID

from app.celery_app import celery_app
from app.tasks.base import db_session
from app.database.session import SessionLocal

from app.services.license_management_service import (
    suspend_license,
    reactivate_license,
    revoke_license,
)

from app.repositories.license_repository import (
    get_license,
    list_expired_licenses,
)
from app.services.license_signing_service import (
    LicenseSigningService,
)

from app.repositories.purchase_session_repository import (
    PurchaseSessionRepository,
)

@celery_app.task(
    queue="maintenance",
)
def suspend_expired_license(
    license_id: str,
):
    pass

@celery_app.task(
    queue="maintenance",
)
def reactivate_license_task():
    pass

@celery_app.task(
    queue="maintenance",
)
def revoke_license_task():
    pass

@celery_app.task(
    queue="maintenance",
)
def refresh_signed_license():
    pass

@celery_app.task(

    bind=True,

    autoretry_for=(Exception,),

    retry_backoff=True,

    retry_kwargs={"max_retries": 5},

)
def issue_license_task(

    self,

    purchase_session_id: str,

):

    db = SessionLocal()

    try:

        purchase_repo = (
            PurchaseSessionRepository(db)
        )

        purchase = (
            purchase_repo.get_by_id(
                purchase_session_id
            )
        )

        if not purchase:

            raise ValueError(
                "Purchase session not found."
            )

        service = LicenseSigningService(
            db
        )

        license_obj = (
            service.issue_license(
                purchase_session_id=
                purchase_session_id
            )
        )

        return {

            "license_id":
            str(license_obj.id),

            "status":
            "success",

        }

    finally:

        db.close()