from fastapi import APIRouter, Depends, Request, status, File, UploadFile
from sqlalchemy.orm import Session

from app.core.roles import Roles
from app.auth.dependencies import require_roles
from app.database.session import get_db
from app.schemas.setting import SettingRead, SettingUpsert
from app.services.setting_service import get_setting, get_settings_list, upsert_setting_record, delete_setting, export_settings, import_settings, reset_category, reset_setting

router = APIRouter(prefix="/api/settings", tags=["settings"])


@router.get("", response_model=list[SettingRead])
def list_settings_endpoint(db: Session = Depends(get_db), admin=Depends(require_roles(Roles.SUPER_ADMIN))):
    return get_settings_list(db)


@router.get("/{key}", response_model=SettingRead)
def get_setting_endpoint(key: str, db: Session = Depends(get_db), admin=Depends(require_roles(Roles.SUPER_ADMIN))):
    return get_setting(db, key)


@router.post("", response_model=SettingRead, status_code=status.HTTP_201_CREATED)
def upsert_setting_endpoint(payload: SettingUpsert, request: Request, db: Session = Depends(get_db), admin=Depends(require_roles(Roles.SUPER_ADMIN))):
    return upsert_setting_record(db, payload, admin=admin, request=request)

@router.delete("/{key}")
def delete_setting_endpoint(
    key: str,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):
    delete_setting(
        db,
        key,
        admin=admin,
        request=request,
    )

@router.post("/{key}/reset")
def reset_setting_endpoint(
    key: str,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):
    return reset_setting(
        db,
        key,
        admin=admin,
        request=request,
    )

@router.post("/reset-category/{category}")
def reset_category_endpoint(
    category: str,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):
    return reset_category(
        db,
        category,
        admin=admin,
        request=request,
    )

@router.get("/export")
def export_settings_endpoint(
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):
    return export_settings(db)

@router.post("/import")
def import_settings_endpoint(
    file: UploadFile = File(...),
    request: Request = None,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):
    return import_settings(
        db,
        file,
        admin=admin,
        request=request,
    )