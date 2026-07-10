from uuid import UUID

from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.models.school import School


def get_school_by_id(db: Session, school_id: UUID) -> School | None:
    return db.get(School, school_id)


def list_schools(db: Session, *, search: str | None = None, offset: int = 0, limit: int = 20) -> tuple[list[School], int]:
    statement = select(School).where(School.deleted_at.is_(None))
    count_statement = select(func.count()).select_from(School).where(School.deleted_at.is_(None))

    if search:
        term = f"%{search.strip()}%"
        statement = statement.where(School.name.ilike(term))
        count_statement = count_statement.where(School.name.ilike(term))

    total = db.scalar(count_statement) or 0
    items = db.scalars(statement.order_by(School.created_at.desc()).offset(offset).limit(limit)).all()
    return items, total


def create_school(
    db: Session,
    *,
    customer_id,
    name: str,
    code: str | None,
    address: str | None,
    contact_email: str | None,
    contact_phone: str | None,
    is_active: bool,
) -> School:
    school = School(
        customer_id=customer_id,
        name=name.strip(),
        code=code.strip() if code else None,
        address=address.strip() if address else None,
        contact_email=contact_email.lower().strip() if contact_email else None,
        contact_phone=contact_phone.strip() if contact_phone else None,
        is_active=is_active,
    )
    db.add(school)
    db.flush()
    return school


def soft_delete_school(db: Session, school: School) -> School:
    school.deleted_at = func.now()
    school.is_active = False
    db.add(school)
    db.flush()
    return school

def persist_school(
    db: Session,
    school: School,
) -> School:

    db.add(school)

    db.flush()

    return school