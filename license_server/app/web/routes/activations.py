from uuid import UUID

from fastapi import APIRouter, Depends, Request, Form
from fastapi.responses import HTMLResponse, RedirectResponse
from fastapi.templating import Jinja2Templates
from sqlalchemy.orm import Session
from app.database.session import SessionLocal

from app.auth.dependencies import require_roles
from app.database.session import get_db
from app.services.activation_service import get_activation
from app.repositories.activation_repository import (list_activations, get_activation_details,)
from app.services.license_management_service import renew_license

from app.utils.flash import flash
from app.core.config import get_settings

settings = get_settings()

router = APIRouter(
    prefix="/activations",
    tags=["Activation Pages"],
)

from app.web.templates import templates


@router.get("/{activation_id}", response_class=HTMLResponse,)
def activation_details(
    request: Request,
    activation_id: UUID,
    db: Session = Depends(get_db),
    admin=Depends(require_roles("Super Admin", "Staff")),
):

    activation = get_activation(
        db,
        activation_id,
    )

    return templates.TemplateResponse(
        "activation_details.html",
        {
            "request": request,
            "activation": activation,
        },
    )



@router.get("/activations", response_class=HTMLResponse)
def activations_page(request: Request, admin=Depends(require_roles("Super Admin", "Staff"))) -> HTMLResponse:
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



@router.get("/activations/{activation_id}", response_class=HTMLResponse)
def activation_details_page(
    activation_id: str,
    request: Request,
    admin=Depends(
        require_roles(
            "Super Admin",
            "Staff",
        )
    ),
):
    with SessionLocal() as db:
        activation = get_activation_details(
            db,
            UUID(activation_id),
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

@router.post("/activations/{activation_id}/deactivate")
def deactivate_activation_submit(
    activation_id: str,
    request: Request,
    admin=Depends(require_roles("Super Admin", "Staff")),
) -> RedirectResponse:

    from uuid import UUID

    from app.services.activation_service import (
        deactivate_license_activation,
    )

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


@router.post("/licenses/{license_id}/renew")
def renew_license_submit(
    license_id: UUID,
    request: Request,
    plan: str = Form(...),
    notes: str = Form(""),
    admin=Depends(require_roles("Super Admin", "Staff")),
):

    with SessionLocal() as db:

        renew_license(
            db=db,
            license_id=license_id,
            plan=plan,
            notes=notes,
            admin=admin,
            request=request,
        )

    flash(
        request,
        "License renewed successfully.",
        "success",
    )

    return RedirectResponse(
        f"/licenses/{license_id}",
        status_code=303,
    )