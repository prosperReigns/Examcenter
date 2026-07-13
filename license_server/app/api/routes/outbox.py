from uuid import UUID

from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.orm import Session

from app.auth.dependencies import require_roles
from app.database.session import get_db
from app.core.roles import Roles
from app.services.outbox_service import (
    get_outbox_event,
    get_pending_outbox_events,
    get_failed_events,
    retry_outbox_event,
    process_pending_outbox_events,
    cleanup_processed_events,
)

router = APIRouter(
    prefix="/api/outbox",
    tags=["Outbox"],
)

@router.get("/pending")
def list_pending_events_endpoint(
    limit: int = 100,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):

    return get_pending_outbox_events(
        db,
        limit=limit,
    )

@router.get("/failed")
def list_failed_events_endpoint(
    retry_count: int = 3,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):

    return get_failed_events(
        db,
        minimum_retry=retry_count,
    )

@router.get("/{event_id}")
def get_event_endpoint(
    event_id: UUID,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):

    event = get_outbox_event(
        db,
        event_id,
    )

    if event is None:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Outbox event not found",
        )

    return event

@router.post("/{event_id}/retry")
def retry_event_endpoint(
    event_id: UUID,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):

    return retry_outbox_event(
        db,
        event_id,
    )

@router.post("/process")
def process_pending_events_endpoint(
    limit: int = 100,
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):

    processed = process_pending_outbox_events(
        db,
        limit=limit,
    )

    return {
        "processed": processed,
        "message": "Pending events processed successfully.",
    }

@router.delete("/processed")
def cleanup_processed_events_endpoint(
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):

    deleted = cleanup_processed_events(
        db,
    )

    return {
        "deleted": deleted,
        "message": "Processed events removed successfully.",
    }