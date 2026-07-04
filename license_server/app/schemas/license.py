from datetime import datetime
from typing import Any
from uuid import UUID

from pydantic import BaseModel, Field


class LicensePayload(BaseModel):
    school: str
    machine: str
    license_type: str
    issued_at: datetime
    expiry: datetime | None
    version: int = Field(default=1, ge=1)


class SignedLicenseResponse(LicensePayload):
    signature: str


class LicenseVerificationResult(BaseModel):
    valid: bool
    payload: LicensePayload | None = None
    error: str | None = None
    metadata: dict[str, Any] | None = None


class LicenseCreateRequest(BaseModel):
    school_id: UUID
    machine_fingerprint: str = Field(min_length=1, max_length=255)
    license_type: str = Field(min_length=1, max_length=50)
    version: int = Field(default=1, ge=1)


class LicenseStatusUpdateRequest(BaseModel):
    status: str = Field(min_length=1, max_length=30)


class LicenseRead(BaseModel):
    id: UUID
    school_id: UUID
    machine_fingerprint: str
    license_type: str
    issued_at: datetime
    expiry_at: datetime | None
    status: str
    signed_license: str
    version: int
    revoked_at: datetime | None = None
    suspended_at: datetime | None = None

    model_config = {"from_attributes": True}
