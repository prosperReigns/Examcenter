from datetime import datetime
from uuid import UUID

from pydantic import BaseModel, Field


class ActivationRequest(BaseModel):
    machine_id: str = Field(
        min_length=1,
        max_length=255,
    )

    computer_name: str | None = Field(
        default=None,
        max_length=255,
    )

    windows_version: str | None = None

    cpu_id: str | None = None

    motherboard_serial: str | None = None

    disk_serial: str | None = None

    mac_address: str | None = None

    ip_address: str | None = Field(
        default=None,
        max_length=50,
    )

    last_user: str | None = None


class ActivationRead(BaseModel):
    id: UUID
    license_id: UUID
    device_id: UUID | None = None
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
    status: str
    message: str
    expires_at: datetime | None = None
    renewal_required: bool = False
    license_id: UUID | None = None
    school_id: UUID | None = None
    remaining_activations: int | None = None
