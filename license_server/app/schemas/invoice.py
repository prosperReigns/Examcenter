from datetime import datetime
from decimal import Decimal
from uuid import UUID

from pydantic import BaseModel, Field


class InvoiceCreateRequest(BaseModel):

    license_id: UUID

    description: str = Field(
        min_length=1,
        max_length=500,
    )

    amount: Decimal

    due_days: int = Field(
        default=7,
        ge=1,
        le=365,
    )


class InvoiceRead(BaseModel):

    id: UUID

    license_id: UUID

    school_id: UUID

    invoice_number: str

    description: str

    amount: Decimal

    currency: str

    status: str

    due_date: datetime | None

    paid_at: datetime | None

    created_at: datetime

    updated_at: datetime

    model_config = {
        "from_attributes": True
    }