from uuid import UUID

from sqlalchemy import func, select
from sqlalchemy.orm import Session
from sqlalchemy import or_
from app.models.admin import Admin


def get_admin_by_email(db: Session, email: str) -> Admin | None:
    statement = select(Admin).where(Admin.email == email.lower().strip())
    return db.scalar(statement)


def get_admin_by_id(db: Session, admin_id: UUID) -> Admin | None:
    return db.get(Admin, admin_id)


def get_admin_count(db: Session) -> int:
    return db.scalar(select(func.count()).select_from(Admin)) or 0


def get_active_admin_count(db: Session) -> int:
    return db.scalar(select(func.count()).select_from(Admin).where(Admin.is_active.is_(True))) or 0


def create_admin(
    db: Session,
    *,
    full_name: str,
    email: str,
    password_hash: str,
    role: str,
) -> Admin:

    admin = Admin(
        full_name=full_name.strip(),
        email=email.lower().strip(),
        password_hash=password_hash,
        role=role,
    )

    db.add(admin)
    db.flush()

    return admin


def persist_admin(
    db: Session,
    admin: Admin,
) -> Admin:

    db.add(admin)
    db.flush()

    return admin

def list_admins(
    db: Session,
    *,
    search: str | None = None,
    offset: int = 0,
    limit: int = 20,
):

    statement = select(Admin)

    if search:

        search = f"%{search.strip()}%"

        statement = statement.where(

            or_(

                Admin.full_name.ilike(search),

                Admin.email.ilike(search),

                Admin.role.ilike(search),

            )

        )

    statement = (

        statement

        .offset(offset)

        .limit(limit)

        .order_by(Admin.full_name)

    )

    return db.scalars(statement).all()

def save_admin(
    db: Session,
    admin: Admin,
):

    db.add(admin)

    db.flush()

    return admin

def delete_admin(
    db: Session,
    admin: Admin,
):

    db.delete(admin)

    db.flush()
