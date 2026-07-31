from uuid import UUID

from fastapi import APIRouter, Depends, Request
from fastapi.responses import RedirectResponse

from app.auth.dependencies import require_roles
from app.database.session import SessionLocal
from app.web.templates import templates

from app.services.outbox_service import (
    get_outbox_list,
    get_outbox_message,
)

router = APIRouter(tags=["Web Outbox"])


@router.get("/outbox")
def outbox_page(
    request: Request,
    admin=Depends(require_roles("Super Admin", "Staff")),
):

    with SessionLocal() as db:

        messages, total = get_outbox_list(
            db,
            page=1,
            page_size=100,
        )

    return templates.TemplateResponse(
        "outbox.html",
        {
            "request": request,
            "title": "Outbox",
            "messages": messages,
            "total": total,
            "admin": admin,
        },
    )


@router.get("/outbox/{message_id}")
def outbox_details_page(
    message_id: UUID,
    request: Request,
    admin=Depends(require_roles("Super Admin", "Staff")),
):

    with SessionLocal() as db:

        message = get_outbox_message(
            db,
            message_id,
        )

    if message is None:

        return RedirectResponse(
            "/outbox",
            status_code=303,
        )

    return templates.TemplateResponse(
        "outbox_details.html",
        {
            "request": request,
            "title": "Outbox Message",
            "message": message,
            "admin": admin,
        },
    )