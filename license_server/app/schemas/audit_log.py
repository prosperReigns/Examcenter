from datetime import datetime
from uuid import UUID

from pydantic import BaseModel


class AuditLogRead(BaseModel):
    id: UUID
    admin_id: int | None = None
    action: str
    entity_type: str | None = None
    entity_id: str | None = None
    description: str | None = None
    ip_address: str | None = None
    user_agent: str | None = None
    occurred_at: datetime

    model_config = {"from_attributes": True}
