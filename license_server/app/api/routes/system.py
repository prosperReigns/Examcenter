from fastapi import APIRouter, Depends

from app.auth.dependencies import require_roles

from app.services.admin_service import admin_statistics
from app.services.device_service import device_statistics
from app.services.customer_service import customer_statistics
from app.services.school_service import school_statistics

router = APIRouter(
    prefix="/api/system",
    tags=["System"],
)

@router.get("/dashboard")
def dashboard(
    admin=Depends(require_roles("Super Admin")),
):
    return {
        "admins": admin_statistics(),
        "customers": customer_statistics(),
        "schools": school_statistics(),
        "devices": device_statistics(),
    }