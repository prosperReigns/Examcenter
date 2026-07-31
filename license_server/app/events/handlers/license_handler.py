from app.services.license_signing_service import (
    LicenseSigningService,
)


class LicenseHandler:

    def __init__(self, db):

        self.service = LicenseSigningService(
            db
        )

    def handle(
        self,
        event,
    ):

        self.service.issue_license(

            purchase_session_id=event.purchase_session_id

        )