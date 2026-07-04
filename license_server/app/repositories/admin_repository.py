from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.models.admin import Admin


def get_admin_by_email(db: Session, email: str) -> Admin | None:
    statement = select(Admin).where(Admin.email == email)
    return db.scalar(statement)


def get_admin_by_id(db: Session, admin_id: int) -> Admin | None:
    return db.get(Admin, admin_id)


def get_admin_count(db: Session) -> int:
    return db.scalar(select(func.count()).select_from(Admin)) or 0


def create_admin(db: Session, *, full_name: str, email: str, password_hash: str, role: str) -> Admin:
    admin = Admin(full_name=full_name, email=email.lower().strip(), password_hash=password_hash, role=role)
    db.add(admin)
    db.flush()
    return admin