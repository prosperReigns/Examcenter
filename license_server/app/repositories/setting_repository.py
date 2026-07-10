from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.models.setting import Setting
from sqlalchemy import delete

def list_settings(db: Session, *, category: str | None = None) -> list[Setting]:
    statement = select(Setting)
    if category:
        statement = statement.where(Setting.category == category)
    return db.scalars(statement.order_by(Setting.key.asc())).all()


def get_setting_by_key(db: Session, key: str) -> Setting | None:
    statement = select(Setting).where(Setting.key == key)
    return db.scalar(statement)


def upsert_setting(
    db: Session,
    *,
    key: str,
    value: str,
    category: str | None = None,
    description: str | None = None,
    is_system: bool = False,
) -> Setting:
    setting = get_setting_by_key(db, key)
    if setting is None:
        setting = Setting(key=key, value=value, category=category, description=description, is_system=is_system)
        db.add(setting)
    else:
        setting.value = value
        setting.category = category
        setting.description = description
        setting.is_system = is_system
        db.add(setting)
    db.flush()
    return setting


def get_setting_count(db: Session) -> int:
    return db.scalar(select(func.count()).select_from(Setting)) or 0

def delete_setting(
    db: Session,
    setting: Setting,
):

    db.delete(setting)
    db.flush()

def persist_setting(
    db: Session,
    setting: Setting,
) -> Setting:

    db.add(setting)
    db.flush()
    return setting