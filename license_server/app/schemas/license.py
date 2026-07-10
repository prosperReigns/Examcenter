from datetime import datetime
from decimal import Decimal
from typing import Any
from uuid import UUID

from pydantic import BaseModel, Field


class LicensePayload(BaseModel):
    school: str
    machine: str
    license_type: str
    plan_name: str
    duration_months: int
    is_trial: bool = False
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
    plan_name: str = Field(min_length=1, max_length=100)
    duration_months: int = Field(ge=0, le=24)
    is_trial: bool = False
    amount_paid: Decimal = Field(default=0, ge=0)
    currency: str = Field(default="NGN", max_length=10)
    max_activations: int = Field(default=1, ge=1)
    version: int = Field(default=1, ge=1)

class LicenseStatusUpdateRequest(BaseModel):
    status: str = Field(min_length=1, max_length=30)


class LicenseRead(BaseModel):
    id: UUID
    school_id: UUID
    machine_fingerprint: str
    license_type: str
    plan_name: str
    duration_months: int
    is_trial: bool
    issued_at: datetime
    expiry_at: datetime | None
    status: str
    payment_status: str
    flutterwave_transaction_id: str | None = None
    flutterwave_reference: str | None = None
    amount_paid: int
    currency: str
    signed_license: str
    activation_count: int
    max_activations: int
    last_activation_at: datetime | None = None
    version: int
    renewal_count: int
    last_renewed_at: datetime | None = None
    next_renewal_due: datetime | None = None
    renewed_from: UUID | None = None
    revoked_at: datetime | None = None
    suspended_at: datetime | None = None
    created_at: datetime
    updated_at: datetime 

    model_config = {
        "from_attributes": True
    }

class LicenseVerifyRequest(BaseModel):
    license_key: str
    machine_fingerprint: str

class LicenseRenewalCreate(BaseModel):
    license_id: UUID
    plan: str
    amount: Decimal
    duration_days: int
    payment_id: UUID | None = None
    notes: str | None = None


class LicenseRenewalRead(BaseModel):
    id: UUID
    license_id: UUID
    payment_id: UUID | None
    renewed_by: UUID | None
    plan: str
    amount: Decimal
    currency: str
    duration_days: int
    old_expiry: datetime | None
    new_expiry: datetime | None
    renewed_at: datetime
    notes: str | None

    model_config = {"from_attributes": True}