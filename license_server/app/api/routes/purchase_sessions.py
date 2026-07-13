from uuid import UUID

from fastapi import APIRouter, Depends
from sqlalchemy.orm import Session

from app.auth.dependencies import require_roles
from app.database.session import get_db

from app.schemas.purchase_session import (
    PurchaseSessionCreate,
    PurchaseSessionRead,
)

from app.services.purchase_session_service import (
    start_purchase,
    get_purchase_session,
    list_purchase_sessions,
    resume_purchase,
    cancel_purchase,
    complete_purchase_session,
)

router = APIRouter(
    prefix="/api/purchase-sessions",
    tags=["Purchase Sessions"],
)

@router.post(
    "",
    response_model=PurchaseSessionRead,
)
def create_purchase_session_endpoint(
    payload: PurchaseSessionCreate,
    db: Session = Depends(get_db),
    admin=Depends(require_roles("Super Admin", "Staff")),
):
    return start_purchase(
        db,
        payload,
    )

@router.get(
    "",
    response_model=list[PurchaseSessionRead],
)
def list_purchase_sessions_endpoint(
    db: Session = Depends(get_db),
    admin=Depends(require_roles("Super Admin", "Staff")),
):
    sessions, _ = list_purchase_sessions(db)

    return sessions

@router.get(
    "/{session_id}",
    response_model=PurchaseSessionRead,
)
def get_purchase_session_endpoint(
    session_id: UUID,
    db: Session = Depends(get_db),
    admin=Depends(require_roles("Super Admin", "Staff")),
):
    return get_purchase_session(
        db,
        session_id,
    )

@router.post("/{session_id}/resume")
def resume_purchase_session_endpoint(
    session_id: UUID,
    db: Session = Depends(get_db),
    admin=Depends(require_roles("Super Admin", "Staff")),
):
    return resume_purchase(
        db,
        session_id,
    )

@router.post("/{session_id}/cancel")
def cancel_purchase_session_endpoint(
    session_id: UUID,
    db: Session = Depends(get_db),
    admin=Depends(require_roles("Super Admin", "Staff")),
):
    return cancel_purchase(
        db,
        session_id,
    )

@router.post("/{session_id}/complete")
def complete_purchase_session_endpoint(
    session_id: UUID,
    db: Session = Depends(get_db),
    admin=Depends(require_roles("Super Admin")),
):
    return complete_purchase_session(
        db,
        session_id,
    )