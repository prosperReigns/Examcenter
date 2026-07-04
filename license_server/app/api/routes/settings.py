from fastapi import APIRouter, Depends, Request, status
from sqlalchemy.orm import Session

from app.auth.dependencies import require_roles
from app.database.session import get_db
from app.schemas.setting import SettingRead, SettingUpsert
from app.services.setting_service import get_setting, get_settings_list, upsert_setting_record

router = APIRouter(prefix="/api/settings", tags=["settings"])


@router.get("", response_model=list[SettingRead])
def list_settings_endpoint(db: Session = Depends(get_db), admin=Depends(require_roles("Super Admin"))):
    return get_settings_list(db)


@router.get("/{key}", response_model=SettingRead)
def get_setting_endpoint(key: str, db: Session = Depends(get_db), admin=Depends(require_roles("Super Admin"))):
    return get_setting(db, key)


@router.post("", response_model=SettingRead, status_code=status.HTTP_201_CREATED)
def upsert_setting_endpoint(payload: SettingUpsert, request: Request, db: Session = Depends(get_db), admin=Depends(require_roles("Super Admin"))):
    return upsert_setting_record(db, payload, admin=admin, request=request)
