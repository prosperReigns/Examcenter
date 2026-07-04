from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.models.audit_log import AuditLog


def create_audit_log(
    db: Session,
    *,
    admin_id: int | None,
    action: str,
    entity_type: str | None = None,
    entity_id: str | None = None,
    description: str | None = None,
    ip_address: str | None = None,
    user_agent: str | None = None,
) -> AuditLog:
    audit_log = AuditLog(
        admin_id=admin_id,
        action=action,
        entity_type=entity_type,
        entity_id=entity_id,
        description=description,
        ip_address=ip_address,
        user_agent=user_agent,
    )
    db.add(audit_log)
    db.flush()
    return audit_log


def list_audit_logs(db: Session, *, offset: int = 0, limit: int = 20) -> tuple[list[AuditLog], int]:
    statement = select(AuditLog)
    count_statement = select(func.count()).select_from(AuditLog)
    total = db.scalar(count_statement) or 0
    items = db.scalars(statement.order_by(AuditLog.occurred_at.desc()).offset(offset).limit(limit)).all()
    return items, total
