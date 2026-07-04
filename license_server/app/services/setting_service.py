from fastapi import HTTPException, status
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from app.models.setting import Setting
from app.repositories.setting_repository import get_setting_by_key, list_settings, upsert_setting
from app.schemas.setting import SettingUpsert
from app.services.audit_service import record_audit_event


def get_settings_list(db: Session, *, category: str | None = None) -> list[Setting]:
    return list_settings(db, category=category)


def get_setting(db: Session, key: str) -> Setting:
    setting = get_setting_by_key(db, key)
    if setting is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Setting not found")
    return setting


def upsert_setting_record(db: Session, payload: SettingUpsert, *, admin=None, request=None) -> Setting:
    try:
        setting = upsert_setting(
            db,
            key=payload.key.strip(),
            value=payload.value,
            category=payload.category.strip() if payload.category else None,
            description=payload.description,
            is_system=payload.is_system,
        )
        db.commit()
        db.refresh(setting)
        record_audit_event(
            db,
            admin=admin,
            action="setting_upserted",
            entity_type="setting",
            entity_id=setting.key,
            description=f"Upserted setting {setting.key}",
            ip_address=request.client.host if request and request.client else None,
            user_agent=request.headers.get("user-agent") if request else None,
        )
        db.commit()
        return setting
    except IntegrityError as exc:
        db.rollback()
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="Unable to save setting") from exc
