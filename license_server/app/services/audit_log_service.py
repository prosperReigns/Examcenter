from datetime import datetime
from uuid import UUID

from sqlalchemy.orm import Session

from app.models.audit_log import AuditLog
from app.repositories.audit_log_repository import list_audit_logs


def get_audit_logs(
    db: Session,
    *,
    page: int,
    page_size: int,
    action: str | None = None,
    entity_type: str | None = None,
    admin_id: UUID | None = None,
    start_date: datetime | None = None,
    end_date: datetime | None = None,
) -> tuple[list[AuditLog], int]:
    offset = (page - 1) * page_size
    return list_audit_logs(
        db,
        action=action,
        entity_type=entity_type,
        admin_id=admin_id,
        start_date=start_date,
        end_date=end_date,
        offset=offset,
        limit=page_size,
    )
