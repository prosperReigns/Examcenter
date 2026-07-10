from fastapi import HTTPException, status
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session
import json
from pathlib import Path

from app.models.setting import Setting
from app.repositories.setting_repository import get_setting_by_key, list_settings, upsert_setting, delete_setting, persist_setting
from app.schemas.setting import SettingUpsert
from app.services.audit_service import record_audit_event
from core.default_settings import DEFAULT_SETTINGS

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

def delete_setting_record(
    db: Session,
    key: str,
    *,
    admin=None,
    request=None,
):

    setting = get_setting(
        db,
        key,
    )

    if setting.is_system:

        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="System settings cannot be deleted.",
        )

    delete_setting(
        db,
        setting,
    )

    record_audit_event(
        db,
        admin=admin,
        action="setting_deleted",
        entity_type="setting",
        entity_id=setting.key,
        description=f"Deleted setting {setting.key}",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )

    db.commit()

def reset_setting(
    db: Session,
    key: str,
    *,
    default_value,
    admin=None,
    request=None,
):

    setting = get_setting(
        db,
        key,
    )

    setting.value = DEFAULT_SETTINGS[setting.key]

    persist_setting(
        db,
        setting,
    )

    record_audit_event(
        db,
        admin=admin,
        action="setting_reset",
        entity_type="setting",
        entity_id=setting.key,
        description=f"Reset setting {setting.key}",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )

    db.commit()

    db.refresh(setting)

    return setting

def reset_category(
    db: Session,
    category: str,
    *,
    default_values: dict,
    admin=None,
    request=None,
):

    settings = list_settings(
        db,
        category=category,
    )

    for setting in settings:

        if setting.key in default_values:

            setting.value = default_values[
                setting.key
            ]

            persist_setting(
                db,
                setting,
            )

    record_audit_event(
        db,
        admin=admin,
        action="setting_category_reset",
        entity_type="setting",
        entity_id=category,
        description=f"Reset {category} settings",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )

    db.commit()

    return len(settings)

def export_settings(
    db: Session,
    path: str | None = None,
):

    settings = list_settings(
        db,
    )

    data = [

        {

            "key": setting.key,

            "value": setting.value,

            "category": setting.category,

            "description": setting.description,

            "is_system": setting.is_system,

        }

        for setting in settings

    ]

    export_path = Path(
        path or "storage/settings.json"
    )

    export_path.parent.mkdir(
        parents=True,
        exist_ok=True,
    )

    export_path.write_text(

        json.dumps(
            data,
            indent=4,
        ),

        encoding="utf-8",
    )

    return export_path

def import_settings(
    db: Session,
    file_path: str,
    *,
    overwrite: bool = False,
    admin=None,
    request=None,
):

    data = json.loads(

        Path(file_path).read_text(
            encoding="utf-8",
        )

    )

    imported = 0

    for item in data:

        existing = get_setting_by_key(
            db,
            item["key"],
        )

        if existing:

            if overwrite:

                existing.value = item["value"]

                existing.category = item.get(
                    "category"
                )

                existing.description = item.get(
                    "description"
                )

                existing.is_system = item.get(
                    "is_system",
                    False,
                )

                persist_setting(
                    db,
                    existing,
                )

                imported += 1

            continue

        upsert_setting(
            db,
            key=item["key"],
            value=item["value"],
            category=item.get("category"),
            description=item.get("description"),
            is_system=item.get(
                "is_system",
                False,
            ),
        )

        imported += 1

    record_audit_event(
        db,
        admin=admin,
        action="settings_imported",
        entity_type="setting",
        entity_id="settings",
        description=f"Imported {imported} settings",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )

    db.commit()

    return imported