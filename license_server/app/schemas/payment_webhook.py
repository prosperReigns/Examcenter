from datetime import datetime
from uuid import UUID

from pydantic import BaseModel


class PaymentWebhookRead(BaseModel):
    id: UUID

    gateway: str

    event_type: str

    gateway_reference: str

    gateway_transaction_id: str | None

    signature: str | None

    payload: str

    processed: bool

    processed_at: datetime | None

    error_message: str | None

    created_at: datetime
    updated_at: datetime

    model_config = {"from_attributes": True}