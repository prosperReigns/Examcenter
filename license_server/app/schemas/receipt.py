from datetime import datetime
from uuid import UUID

from pydantic import BaseModel
from decimal import Decimal


class ReceiptRead(BaseModel):
    id: UUID
    receipt_number: str

    invoice_id: UUID
    payment_id: UUID

    customer_id: UUID
    school_id: UUID

    amount: Decimal
    currency: str

    status: str

    pdf_path: str | None = None

    issued_at: datetime
    created_at: datetime

    updated_at: datetime

    model_config = {
        "from_attributes": True,
    }