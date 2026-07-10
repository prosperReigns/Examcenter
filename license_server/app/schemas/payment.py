from datetime import datetime
from uuid import UUID

from pydantic import BaseModel, Field
from decimal import Decimal


class PaymentInitializeRequest(BaseModel):
    customer_id: UUID
    school_id: UUID
    payment_type: str = Field(min_length=1, max_length=50)
    invoice_id: UUID


class PaymentVerifyRequest(BaseModel):
    payment_reference: str
    gateway_reference: str | None = None

class PaymentRead(BaseModel):
    id: UUID
    customer_id: UUID
    school_id: UUID
    invoice_id: UUID

    payment_reference: str

    gateway: str | None = None
    gateway_reference: str | None = None

    amount: Decimal
    currency: str

    payment_method: str
    status: str

    paid_at: datetime | None = None
    verified_at: datetime | None = None

    model_config = {
        "from_attributes": True,
    }


class PaymentInitializationResponse(BaseModel):
    payment: PaymentRead
    authorization_url: str | None = None
    payment_reference: str


class FlutterwaveWebhookPayload(BaseModel):
    event: str | None = None
    data: dict

class PaymentCreateRequest(BaseModel):
    invoice_id: UUID
    payment_method: str = Field(min_length=1, max_length=50)
