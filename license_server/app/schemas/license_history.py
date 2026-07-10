from datetime import datetime
from uuid import UUID

from pydantic import BaseModel


class LicenseHistoryRead(BaseModel):
    id: UUID
    license_id: UUID
    version: int
    license_type: str

    issued_at: datetime
    expiry_at: datetime | None

    signed_license: str

    created_at: datetime

    model_config = {"from_attributes": True}