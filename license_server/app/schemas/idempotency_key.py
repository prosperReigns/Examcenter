from datetime import datetime
from uuid import UUID

from pydantic import BaseModel


class IdempotencyKeyRead(BaseModel):
    id: UUID

    key: str

    request_method: str

    request_path: str

    request_hash: str

    response_status: int

    response_body: str

    expires_at: datetime

    created_at: datetime
    updated_at: datetime

    model_config = {"from_attributes": True}