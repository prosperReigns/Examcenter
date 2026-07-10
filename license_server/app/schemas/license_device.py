from datetime import datetime
from uuid import UUID

from pydantic import BaseModel, Field


class LicenseDeviceCreate(BaseModel):
    machine_id: str = Field(min_length=1, max_length=255)

    computer_name: str | None = None

    windows_version: str | None = None

    cpu_id: str | None = None

    motherboard_serial: str | None = None

    disk_serial: str | None = None

    mac_address: str | None = None

    ip_address: str | None = None

    last_user: str | None = None


class LicenseDeviceRead(BaseModel):
    id: UUID

    license_id: UUID

    machine_id: str

    computer_name: str | None = None

    renamed_to: str | None = None

    windows_version: str | None = None

    cpu_id: str | None = None

    motherboard_serial: str | None = None

    disk_serial: str | None = None

    mac_address: str | None = None

    ip_address: str | None = None

    last_user: str | None = None

    activation_count: int

    first_seen: datetime

    last_seen: datetime

    blacklisted: bool

    blacklist_reason: str | None = None

    notes: str | None = None

    status: str
    created_at: datetime

    updated_at: datetime

    model_config = {
        "from_attributes": True
    }


class DeviceRenameRequest(BaseModel):
    renamed_to: str = Field(
        min_length=1,
        max_length=255,
    )


class DeviceBlacklistRequest(BaseModel):
    blacklist_reason: str | None = None


class DeviceNotesRequest(BaseModel):
    notes: str

class DeviceHeartbeatRequest(BaseModel):
    machine_id: str
    ip_address: str | None = None
    last_user: str | None = None