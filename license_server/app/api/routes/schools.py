from uuid import UUID

from fastapi import APIRouter, Depends, Query, Request, status
from sqlalchemy.orm import Session

from app.auth.dependencies import require_roles
from app.database.session import get_db
from app.schemas.school import SchoolCreate, SchoolRead, SchoolUpdate
from app.services.school_service import create_school_record, delete_school_record, get_school, get_schools, update_school_record, activate_school, deactivate_school

router = APIRouter(prefix="/api/schools", tags=["schools"])


@router.get("", response_model=list[SchoolRead])
def list_schools_endpoint(
    search: str | None = Query(default=None, max_length=150),
    page: int = Query(default=1, ge=1),
    page_size: int = Query(default=20, ge=1, le=100),
    db: Session = Depends(get_db),
    admin=Depends(require_roles("Super Admin", "Staff")),
):
    items, _ = get_schools(db, search=search, page=page, page_size=page_size)
    return items


@router.get("/{school_id}", response_model=SchoolRead)
def get_school_endpoint(school_id: UUID, db: Session = Depends(get_db), admin=Depends(require_roles("Super Admin", "Staff"))):
    return get_school(db, school_id)


@router.post("", response_model=SchoolRead, status_code=status.HTTP_201_CREATED)
def create_school_endpoint(payload: SchoolCreate, request: Request, db: Session = Depends(get_db), admin=Depends(require_roles("Super Admin", "Staff"))):
    return create_school_record(db, payload, admin=admin, request=request)


@router.put("/{school_id}", response_model=SchoolRead)
def update_school_endpoint(school_id: UUID, payload: SchoolUpdate, request: Request, db: Session = Depends(get_db), admin=Depends(require_roles("Super Admin", "Staff"))):
    return update_school_record(db, school_id, payload, admin=admin, request=request)


@router.delete("/{school_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_school_endpoint(school_id: UUID, request: Request, db: Session = Depends(get_db), admin=Depends(require_roles("Super Admin", "Staff"))):
    delete_school_record(db, school_id, admin=admin, request=request)

@router.post("/{school_id}/activate")
def activate_school_endpoint(
    school_id: UUID,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles("Super Admin")),
):
    return activate_school(
        db,
        school_id,
        admin=admin,
        request=request,
    )

@router.post("/{school_id}/deactivate")
def deactivate_school_endpoint(
    school_id: UUID,
    request: Request,
    db: Session = Depends(get_db),
    admin=Depends(require_roles("Super Admin")),
):
    return deactivate_school(
        db,
        school_id,
        admin=admin,
        request=request,
    )