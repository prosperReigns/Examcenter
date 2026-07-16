from fastapi import APIRouter, Depends
from sqlalchemy.orm import Session

from app.auth.dependencies import require_roles

from app.core.roles import Roles
from app.database.session import get_db
from app.services.admin_service import admin_statistics
from app.services.device_service import get_device_statistics
from app.services.customer_service import customer_statistics
from app.services.school_service import school_statistics

router = APIRouter(
    prefix="/api/system",
    tags=["System"],
)

@router.get("/dashboard")
def dashboard(
    db: Session = Depends(get_db),
    admin=Depends(require_roles(Roles.SUPER_ADMIN)),
):
    return {
        "admins": admin_statistics(db),
        "customers": customer_statistics(db),
        "schools": school_statistics(db),
        "devices": get_device_statistics(db),
    }
