from uuid import UUID

from fastapi import APIRouter, Depends, Request, status
from sqlalchemy.orm import Session

from app.auth.dependencies import require_roles
from app.database.session import get_db

from app.schemas.notification import (
    NotificationCreate,
    NotificationRead,
)

from app.core.roles import Roles
from app.services.notification_service import (
    list_notifications,
    get_notification,
    mark_as_read,
    mark_all_read,
    delete_notification,
    send_system_notification,
)

router = APIRouter(
    prefix="/api/notifications",
    tags=["Notifications"],
)

@router.get(
    "",
    response_model=list[NotificationRead],
)
def list_notifications_endpoint(
    unread_only: bool = False,
    page: int = 1,
    page_size: int = 20,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):

    notifications, _ = list_notifications(
        db,
        unread_only=unread_only,
        page=page,
        page_size=page_size,
    )

    return notifications

@router.get(
    "/{notification_id}",
    response_model=NotificationRead,
)
def get_notification_endpoint(
    notification_id: UUID,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):

    return get_notification(
        db,
        notification_id,
    )

@router.post(
    "/{notification_id}/read",
    response_model=NotificationRead,
)
def mark_notification_read_endpoint(
    notification_id: UUID,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):

    return mark_as_read(
        db,
        notification_id,
        admin=admin,
        request=request,
    )

@router.post("/read-all")
def mark_all_notifications_read_endpoint(
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):

    count = mark_all_read(
        db,
        admin=admin,
        request=request,
    )

    return {
        "success": True,
        "updated": count,
    }

@router.delete(
    "/{notification_id}",
    status_code=status.HTTP_204_NO_CONTENT,
)
def delete_notification_endpoint(
    notification_id: UUID,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):

    delete_notification(
        db,
        notification_id,
        admin=admin,
        request=request,
    )

@router.post(
    "",
    response_model=NotificationRead,
    status_code=status.HTTP_201_CREATED,
)
def create_system_notification_endpoint(
    payload: NotificationCreate,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):

    return send_system_notification(
        db,
        payload,
        admin=admin,
        request=request,
    )