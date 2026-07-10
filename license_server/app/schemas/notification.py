from datetime import datetime
from uuid import UUID

from pydantic import BaseModel


class NotificationRead(BaseModel):
    id: UUID

    customer_id: UUID | None = None

    school_id: UUID | None = None

    channel: str

    recipient: str

    subject: str | None = None

    message: str

    status: str

    sent_at: datetime | None = None

    error_message: str | None = None
    created_at: datetime

    updated_at: datetime

    model_config = {
        "from_attributes": True,
    }

class NotificationCreate(BaseModel):
    title: str
    message: str
    notification_type: str = "system"