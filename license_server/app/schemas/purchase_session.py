from datetime import datetime
from decimal import Decimal
from uuid import UUID

from pydantic import BaseModel, EmailStr, Field


class PurchaseSessionCreate(BaseModel):
    fingerprint: str = Field(min_length=5, max_length=255)
    product_code: str = Field(default="cbt", min_length=1, max_length=80)
    version: str = Field(default="1.0", min_length=1, max_length=50)
    plan_code: str = Field(min_length=1, max_length=50)
    duration_months: int = Field(default=12, ge=0, le=120)
    amount: Decimal = Field(ge=0)
    currency: str = Field(default="NGN", min_length=3, max_length=10)
    customer_name: str = Field(min_length=1, max_length=150)
    customer_email: EmailStr
    customer_phone: str | None = Field(default=None, max_length=50)
    school_name: str = Field(min_length=1, max_length=150)
    gateway: str | None = Field(default=None, max_length=50)
    payment_reference: str | None = Field(default=None, max_length=100)


class PurchaseSessionRead(BaseModel):
    id: UUID
    fingerprint: str
    product_code: str
    version: str
    plan_code: str
    duration_months: int
    amount: Decimal
    currency: str
    customer_name: str
    customer_email: str
    customer_phone: str | None
    school_name: str
    payment_reference: str | None
    gateway: str | None
    gateway_response: str | None = None
    status: str
    completed: bool
    retry_count: int
    expires_at: datetime
    completed_at: datetime | None
    created_at: datetime
    updated_at: datetime | None = None
    activation_token: str | None = None

    model_config = {"from_attributes": True}


class CompletePaymentRequest(BaseModel):
    session_id: UUID | None = None
    payment_reference: str | None = Field(default=None, max_length=100)
    gateway_reference: str | None = Field(default=None, max_length=255)
    gateway_transaction_id: str | None = Field(default=None, max_length=150)
    gateway_response: str | None = None


class PublicActivationStartRequest(PurchaseSessionCreate):
    pass


class PublicStatusResponse(BaseModel):
    session: PurchaseSessionRead
    license_ready: bool = False
    activation_token: str | None = None
