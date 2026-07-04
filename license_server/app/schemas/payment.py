from datetime import datetime
from uuid import UUID

from pydantic import BaseModel, Field


class PaymentInitializeRequest(BaseModel):
    customer_id: UUID
    school_id: UUID
    payment_type: str = Field(min_length=1, max_length=50)


class PaymentVerifyRequest(BaseModel):
    tx_ref: str = Field(min_length=1, max_length=100)
    transaction_id: str | None = Field(default=None, max_length=100)


class PaymentRead(BaseModel):
    id: UUID
    customer_id: UUID
    school_id: UUID | None = None
    license_id: UUID | None = None
    flutterwave_transaction_id: str | None = None
    flutterwave_tx_ref: str
    amount: int
    currency: str
    status: str
    payment_type: str
    invoice_path: str | None = None
    verified_at: datetime | None = None

    model_config = {"from_attributes": True}


class PaymentInitializationResponse(BaseModel):
    payment: PaymentRead
    authorization_url: str | None = None
    tx_ref: str


class FlutterwaveWebhookPayload(BaseModel):
    event: str | None = None
    data: dict
