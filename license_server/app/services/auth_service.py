from datetime import timedelta

from sqlalchemy.orm import Session

from app.auth.security import create_access_token, verify_password
from app.models.admin import Admin
from app.repositories.admin_repository import get_admin_by_email


def authenticate_admin(db: Session, email: str, password: str) -> Admin | None:
    admin = get_admin_by_email(db, email.lower().strip())
    if admin is None:
        return None
    if not verify_password(password, admin.password_hash):
        return None
    if not admin.is_active:
        return None
    return admin


def issue_admin_token(admin: Admin, expires_delta: timedelta | None = None) -> str:
    return create_access_token(subject=str(admin.id), expires_delta=expires_delta, extra_claims={"role": admin.role})
