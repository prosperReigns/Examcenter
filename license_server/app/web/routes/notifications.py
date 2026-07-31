from uuid import UUID

from fastapi import APIRouter, Depends, Request
from fastapi.responses import RedirectResponse

from app.auth.dependencies import require_roles
from app.database.session import SessionLocal
from app.web.templates import templates

from app.services.notification_service import (
    get_notification_list,
    get_notification,
)

router = APIRouter(tags=["Web Notifications"])


@router.get(
    "/notifications",
)
def notifications_page(
    request: Request,
    admin=Depends(
        require_roles(
            "Super Admin",
            "Staff",
        )
    ),
):

    with SessionLocal() as db:

        notifications, total = get_notification_list(
            db,
            page=1,
            page_size=100,
        )

    return templates.TemplateResponse(
        "notifications.html",
        {
            "request": request,
            "title": "Notifications",
            "notifications": notifications,
            "total": total,
            "admin": admin,
        },
    )


@router.get(
    "/notifications/{notification_id}",
)
def notification_details_page(
    notification_id: UUID,
    request: Request,
    admin=Depends(
        require_roles(
            "Super Admin",
            "Staff",
        )
    ),
):

    with SessionLocal() as db:

        notification = get_notification(
            db,
            notification_id,
        )

    if notification is None:

        return RedirectResponse(
            "/notifications",
            status_code=303,
        )

    return templates.TemplateResponse(
        "notification_details.html",
        {
            "request": request,
            "title": "Notification",
            "notification": notification,
            "admin": admin,
        },
    )


@router.get(
    "/notifications/compose",
)
def compose_notification_page(
    request: Request,
    admin=Depends(require_roles("Super Admin")),
):

    return templates.TemplateResponse(
        "notification_compose.html",
        {
            "request": request,
            "title": "Compose Notification",
            "admin": admin,
        },
    )