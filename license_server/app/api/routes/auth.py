from fastapi import APIRouter, Depends, HTTPException, Response, status, Request
from sqlalchemy.orm import Session

from app.auth.dependencies import get_current_admin
from app.database.session import get_db
from app.schemas.auth import AdminProfile, LoginRequest, TokenResponse
from app.services.auth_service import authenticate_admin, issue_admin_token
from services.audit_service import record_audit_event


router = APIRouter(prefix="/api", tags=["auth"])


@router.post("/login", response_model=TokenResponse, summary="Administrator Login", description="Authenticate an administrator and return a JWT access token.")
def login(payload: LoginRequest,request: Request, db: Session = Depends(get_db)) -> TokenResponse:
    admin = authenticate_admin(db, payload.email, payload.password, request=request)

    if admin is None:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid email or password")

    record_audit_event(
        db,
        admin=admin,
        action="login",
        entity_type="admin",
        entity_id=str(admin.id),
        description="Administrator logged in.",
        ip_address=request.client.host if request.client else None,
        user_agent=request.headers.get("user-agent"),
    )

    db.commit()
    return TokenResponse(access_token=issue_admin_token(admin))


@router.get("/me", response_model=AdminProfile, summary="Current Administrator", description="Return the currently authenticated administrator.")
def read_current_admin(admin=Depends(get_current_admin)) -> AdminProfile:
    return AdminProfile(id=admin.id, full_name=admin.full_name, email=admin.email, role=admin.role)


@router.post("/logout", status_code=status.HTTP_204_NO_CONTENT, summary="Administrator Logout", description="Log out the current administrator.")
def logout_api(request: Request,
    db: Session = Depends(get_db),
    admin=Depends(get_current_admin)) -> Response:
    record_audit_event(
        db,
        admin=admin,
        action="logout",
        entity_type="admin",
        entity_id=str(admin.id),
        description="Administrator logged out.",
        ip_address=request.client.host if request.client else None,
        user_agent=request.headers.get("user-agent"),
    )

    db.commit()
    return Response(status_code=status.HTTP_204_NO_CONTENT)
