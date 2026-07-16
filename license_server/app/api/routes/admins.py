from uuid import UUID

from fastapi import (
    APIRouter,
    Depends,
    Request,
    Query,
    status,
)
from sqlalchemy.orm import Session

from app.auth.dependencies import require_roles
from app.database.session import get_db

from app.schemas.admin import (
    AdminCreate,
    AdminRead,
    AdminUpdate,
)
from app.core.roles import Roles
from app.schemas.admin import ChangePasswordRequest, ResetPasswordRequest
from app.services.admin_service import (
    create_admin_record,
    update_admin_record,
    delete_admin_record,
    activate_admin,
    deactivate_admin,
    change_admin_password,
    reset_admin_password,
    get_admin,
    get_admins,
    admin_statistics,
)

router = APIRouter(
    prefix="/api/admins",
    tags=["admins"],
)

@router.get(
    "",
    response_model=list[AdminRead],
    summary="List Administrators",
)
def list_admins_endpoint(
    search: str | None = Query(None),
    page: int = Query(1, ge=1),
    page_size: int = Query(20, ge=1, le=100),
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):
    items, _ = get_admins(
        db,
        search=search,
        page=page,
        page_size=page_size,
    )
    return items

@router.get(
    "/statistics",
    summary="Administrator Statistics",
)
def statistics_endpoint(
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):
    return admin_statistics(db)

@router.get(
    "/{admin_id}",
    response_model=AdminRead,
    summary="Get Administrator",
)
def get_admin_endpoint(
    admin_id: UUID,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):
    return get_admin(
        db,
        admin_id,
    )

@router.post(
    "",
    response_model=AdminRead,
    status_code=status.HTTP_201_CREATED,
    summary="Create Administrator",
)
def create_admin_endpoint(
    payload: AdminCreate,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):
    return create_admin_record(
        db,
        payload,
        admin=admin,
        request=request,
    )

@router.patch(
    "/{admin_id}",
    response_model=AdminRead,
    summary="Update Administrator",
)
def update_admin_endpoint(
    admin_id: UUID,
    payload: AdminUpdate,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):
    return update_admin_record(
        db,
        admin_id,
        payload,
        admin=admin,
        request=request,
    )

@router.delete(
    "/{admin_id}",
    status_code=status.HTTP_204_NO_CONTENT,
    summary="Delete Administrator",
)
def delete_admin_endpoint(
    admin_id: UUID,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):
    delete_admin_record(
        db,
        admin_id,
        admin=admin,
        request=request,
    )

@router.post(
    "/{admin_id}/activate",
    response_model=AdminRead,
    summary="Activate Administrator",
)
def activate_admin_endpoint(
    admin_id: UUID,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):
    return activate_admin(
        db,
        admin_id,
        admin=admin,
        request=request,
    )

@router.post(
    "/{admin_id}/deactivate",
    response_model=AdminRead,
    summary="Deactivate Administrator",
)
def deactivate_admin_endpoint(
    admin_id: UUID,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):
    return deactivate_admin(
        db,
        admin_id,
        admin=admin,
        request=request,
    )

@router.post(
    "/{admin_id}/change-password",
    summary="Change Administrator Password",
)
def change_password_endpoint(
    admin_id: UUID,
    payload: ChangePasswordRequest,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):
    return change_admin_password(
        db,
        admin_id,
        payload.old_password,
        payload.new_password,
        admin=admin,
        request=request,
    )

@router.post(
    "/{admin_id}/reset-password",
    summary="Reset Administrator Password",
)
def reset_password_endpoint(
    admin_id: UUID,
    payload: ResetPasswordRequest,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):
    return reset_admin_password(
        db,
        admin_id,
        payload.new_password,
        admin=admin,
        request=request,
    )

