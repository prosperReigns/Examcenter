from datetime import datetime
from decimal import Decimal
from uuid import UUID

from pydantic import BaseModel, Field


class LicenseProductCreate(BaseModel):
    name: str = Field(min_length=1, max_length=150)
    license_type: str = Field(min_length=1, max_length=50)
    duration_days: int | None = Field(default=None, ge=1)
    price: Decimal = Field(ge=0)
    currency: str = Field(default="NGN", max_length=10)
    features_json: str | None = None
    is_active: bool = True


class LicenseProductUpdate(BaseModel):
    name: str | None = Field(default=None, min_length=1, max_length=150)
    license_type: str | None = Field(default=None, min_length=1, max_length=50)
    duration_days: int | None = Field(default=None, ge=1)
    price: Decimal | None = Field(default=None, ge=0)
    currency: str | None = Field(default=None, max_length=10)
    features_json: str | None = None
    is_active: bool | None = None


class LicenseProductRead(BaseModel):
    id: UUID
    name: str
    license_type: str
    duration_days: int | None
    price: Decimal
    currency: str
    features_json: str | None
    is_active: bool

    created_at: datetime
    updated_at: datetime

    model_config = {"from_attributes": True}