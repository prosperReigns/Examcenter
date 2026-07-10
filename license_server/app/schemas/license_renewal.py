from datetime import datetime
from uuid import UUID

from pydantic import BaseModel, Field
from decimal import Decimal

class LicenseRenewRequest(BaseModel):
    plan: str = Field(
        min_length=1,
        max_length=50,
    )
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
    notes: str

    model_config = {
        "from_attributes": True
    }
