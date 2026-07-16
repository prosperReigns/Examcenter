from datetime import datetime
from decimal import Decimal
from typing import Any
from uuid import UUID

from pydantic import BaseModel, Field


class LicensePayload(BaseModel):
    license_id: UUID | None = None
    school_id: UUID | None = None
    school: str
    school_code: str | None = None
    product_code: str = "cbt"
    product_name: str = "CBT Examination Software"
    machine: str
    license_type: str
    plan_code: str | None = None
    plan_name: str
    duration_months: int
    is_trial: bool = False
    features: dict[str, Any] = Field(default_factory=dict)
    issued_at: datetime
    expiry: datetime | None
    public_key_version: str = "v1"
    package_version: int = Field(default=1, ge=1)
    version: int = Field(default=1, ge=1)


class SignedLicenseResponse(LicensePayload):
    signature: str
    checksum: str
    checksum_algorithm: str = "sha256"
    signature_algorithm: str = "rsa-pkcs1v15-sha256"


class LicensePackage(BaseModel):
    package_type: str = "cbt_offline_license"
    package_version: int = Field(default=1, ge=1)
    generated_at: datetime
    public_key_version: str = "v1"
    checksum_algorithm: str = "sha256"
    signature_algorithm: str = "rsa-pkcs1v15-sha256"
    license: LicensePayload
    checksum: str
    signature: str

    model_config = {
        "populate_by_name": True
    }


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
