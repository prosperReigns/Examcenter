from uuid import UUID

from fastapi import APIRouter, Depends, Request, Form
from fastapi.responses import HTMLResponse, RedirectResponse
from fastapi.templating import Jinja2Templates
from sqlalchemy.orm import Session
from app.database.session import SessionLocal

from app.core.roles import Roles
from app.auth.dependencies import require_roles
from app.database.session import get_db
from app.services.activation_service import get_activation
from app.repositories.activation_repository import (list_activations, get_activation_details,)
from app.services.license_management_service import renew_license
from app.services.activation_service import (
        deactivate_license_activation,
    )

from app.utils.flash import flash
from app.core.config import get_settings

router = APIRouter(
    prefix="/activations", 
    tags=[" Web - Activations"],
)
settings = get_settings()

from app.web.templates import templates


@router.get("/{activation_id:uuid}", response_class=HTMLResponse,)
def activation_details(
    request: Request,
    activation_id: UUID,
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
):

    with SessionLocal() as db:
        activation = get_activation(
            db,
            activation_id,
        )

    if activation is None:
        flash(
            request,
            "Activation not found.",
            "danger",
        )

        return RedirectResponse(
            "/activations",
            status_code=303,
        )

    return templates.TemplateResponse(
        "activation_details.html",
        {
            "request": request,
            "settings": settings,
            "title": "Activation Details",
            "admin": admin,
            "activation": activation,
        },
    )

@router.get("/activation", response_class=HTMLResponse)
def activations_page(request: Request, admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF))) -> HTMLResponse:
    with SessionLocal() as db:
        activations, _ = list_activations(db, offset=0, limit=100)
    return templates.TemplateResponse(
        "activations.html",
        {
            "request": request,
            "settings": settings,
            "title": "Activations",
            "admin": admin,
            "activations": activations,
        },
    )

@router.post("/{activation_id:uuid}/deactivate")
def deactivate_activation_submit(
    activation_id: str,
    request: Request,
    admin=Depends(require_roles(Roles.SUPER_ADMIN, Roles.STAFF)),
) -> RedirectResponse:
    with SessionLocal() as db:
        deactivate_license_activation(
            db,
            UUID(activation_id),
        )

    flash(
        request,
        "Activation successfully deactivated.",
        "success",
    )

    return RedirectResponse(
        url="/activations",
        status_code=303,
    )
