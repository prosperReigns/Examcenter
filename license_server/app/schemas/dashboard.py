from pydantic import BaseModel


class DashboardStats(BaseModel):
    total_customers: int = 0
    total_schools: int = 0
    active_licenses: int = 0
    expired_licenses: int = 0
    revenue: int = 0
    pending_renewals: int = 0
    recent_payments: int = 0
    recent_activations: int = 0
