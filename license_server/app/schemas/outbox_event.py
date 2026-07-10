from datetime import datetime
from uuid import UUID

from pydantic import BaseModel


class OutboxEventRead(BaseModel):
    id: UUID

    event_type: str

    aggregate_type: str

    aggregate_id: UUID

    payload: str

    processed: bool

    processed_at: datetime | None

    retry_count: int

    error_message: str | None

    created_at: datetime
    updated_at: datetime

    model_config = {"from_attributes": True}