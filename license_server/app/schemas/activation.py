from datetime import datetime
from uuid import UUID

from pydantic import BaseModel, Field


class ActivationRequest(BaseModel):
    machine_id: str = Field(min_length=1, max_length=255)
    computer_name: str | None = Field(default=None, max_length=255)
    ip_address: str | None = Field(default=None, max_length=50)


class ActivationRead(BaseModel):
    id: UUID
    license_id: UUID
    school_id: UUID
    machine_id: str
    computer_name: str | None = None
    ip_address: str | None = None
    activated_at: datetime
    deactivated_at: datetime | None = None
    status: str

    model_config = {"from_attributes": True}


class LicenseValidationResponse(BaseModel):
    valid: bool
    message: str
