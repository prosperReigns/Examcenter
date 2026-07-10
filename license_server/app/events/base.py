from dataclasses import dataclass
from datetime import datetime
from uuid import UUID
import uuid
from datetime import datetime, timezone

@dataclass(slots=True)
class DomainEvent:
    id: UUID
    occurred_at: datetime

    def create_event():

        return DomainEvent(

            id=uuid.uuid4(),

            occurred_at=datetime.now(
                timezone.utc,
            ),

        )