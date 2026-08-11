from __future__ import annotations

from app.celery_app import celery_app
from app.database.session import SessionLocal
from app.repositories.dashboard_repository import get_dashboard_stats


@celery_app.task(name="analytics.snapshot", queue="analytics")
def analytics_snapshot() -> dict:
    """Return a compact dashboard snapshot for scheduled monitoring."""

    db = SessionLocal()
    try:
        return get_dashboard_stats(db)
    finally:
        db.close()


@celery_app.task(name="analytics.update_sales", queue="analytics")
def update_sales_analytics(**kwargs) -> dict:
    return analytics_snapshot()

def generate_daily_statistics():
    pass
    
def generate_monthly_statistics():
    pass

def generate_revenue_report():
    pass

def generate_activation_report():
    pass

def generate_customer_growth_report():
    pass
