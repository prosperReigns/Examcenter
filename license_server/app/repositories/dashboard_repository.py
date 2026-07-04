from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.models.activation import Activation
from app.models.customer import Customer
from app.models.license import License
from app.models.payment import Payment
from app.models.school import School


def get_dashboard_stats(db: Session) -> dict[str, int]:
    total_customers = db.scalar(select(func.count()).select_from(Customer).where(Customer.deleted_at.is_(None))) or 0
    total_schools = db.scalar(select(func.count()).select_from(School).where(School.deleted_at.is_(None))) or 0
    active_licenses = db.scalar(select(func.count()).select_from(License).where(License.deleted_at.is_(None), License.status == "active")) or 0
    expired_licenses = db.scalar(select(func.count()).select_from(License).where(License.deleted_at.is_(None), License.status == "expired")) or 0
    revenue = db.scalar(
        select(func.coalesce(func.sum(Payment.amount), 0)).where(Payment.status == "successful")
    ) or 0
    pending_renewals = db.scalar(
        select(func.count()).select_from(License).where(License.deleted_at.is_(None), License.status == "active", License.expiry_at.is_not(None))
    ) or 0
    recent_payments = db.scalar(select(func.count()).select_from(Payment).where(Payment.status == "successful")) or 0
    recent_activations = db.scalar(select(func.count()).select_from(Activation).where(Activation.status == "active")) or 0

    return {
        "total_customers": int(total_customers),
        "total_schools": int(total_schools),
        "active_licenses": int(active_licenses),
        "expired_licenses": int(expired_licenses),
        "revenue": int(revenue),
        "pending_renewals": int(pending_renewals),
        "recent_payments": int(recent_payments),
        "recent_activations": int(recent_activations),
    }
