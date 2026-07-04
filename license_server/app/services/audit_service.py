from sqlalchemy.orm import Session

from app.models.admin import Admin
from app.repositories.audit_log_repository import create_audit_log


def record_audit_event(
    db: Session,
    *,
    admin: Admin | None,
    action: str,
    entity_type: str | None = None,
    entity_id: str | None = None,
    description: str | None = None,
    ip_address: str | None = None,
    user_agent: str | None = None,
) -> None:
    create_audit_log(
        db,
        admin_id=admin.id if admin is not None else None,
        action=action,
        entity_type=entity_type,
        entity_id=entity_id,
        description=description,
        ip_address=ip_address,
        user_agent=user_agent,
    )
