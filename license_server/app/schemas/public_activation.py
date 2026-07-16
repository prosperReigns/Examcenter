from pydantic import BaseModel, Field


class PublicLicenseValidationRequest(BaseModel):
    license_key: str = Field(min_length=10, max_length=255)
    machine_id: str = Field(min_length=5, max_length=255)
    fingerprint: str = Field(min_length=20, max_length=255)


class PublicLicenseValidationResponse(BaseModel):
    valid: bool
    status: str
    expires_at: str | None = None
    message: str


class PublicRenewalCheckRequest(BaseModel):
    license_key: str | None = Field(default=None, min_length=10, max_length=255)
    activation_token: str | None = Field(default=None, min_length=20, max_length=255)
    machine_fingerprint: str = Field(min_length=5, max_length=255)
