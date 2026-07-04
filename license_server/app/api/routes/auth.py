from fastapi import APIRouter, Depends, HTTPException, Response, status
from sqlalchemy.orm import Session

from app.auth.dependencies import get_current_admin
from app.database.session import get_db
from app.schemas.auth import AdminProfile, LoginRequest, TokenResponse
from app.services.auth_service import authenticate_admin, issue_admin_token

router = APIRouter(prefix="/api", tags=["auth"])


@router.post("/login", response_model=TokenResponse)
def login(payload: LoginRequest, db: Session = Depends(get_db)) -> TokenResponse:
    admin = authenticate_admin(db, payload.email, payload.password)
    if admin is None:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid email or password")
    return TokenResponse(access_token=issue_admin_token(admin))


@router.get("/me", response_model=AdminProfile)
def read_current_admin(admin=Depends(get_current_admin)) -> AdminProfile:
    return AdminProfile(id=admin.id, full_name=admin.full_name, email=admin.email, role=admin.role)


@router.post("/logout", status_code=status.HTTP_204_NO_CONTENT)
def logout_api() -> Response:
    return Response(status_code=status.HTTP_204_NO_CONTENT)
