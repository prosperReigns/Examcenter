from sqlalchemy.orm import Session

from app.models.audit_log import AuditLog
from app.repositories.audit_log_repository import list_audit_logs


def get_audit_logs(db: Session, *, page: int, page_size: int) -> tuple[list[AuditLog], int]:
    offset = (page - 1) * page_size
    return list_audit_logs(db, offset=offset, limit=page_size)
