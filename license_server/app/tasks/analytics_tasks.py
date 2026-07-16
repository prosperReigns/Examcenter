from __future__ import annotations

from app.celery_app import celery_app
from app.database.session import SessionLocal
from app.repositories.dashboard_repository import get_dashboard_stats


@celery_app.task(name="analytics.snapshot")
def analytics_snapshot() -> dict:
    """Return a compact dashboard snapshot for scheduled monitoring."""

    db = SessionLocal()
    try:
        return get_dashboard_stats(db)
    finally:
        db.close()
