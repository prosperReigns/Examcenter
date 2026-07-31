from __future__ import annotations

import json
from datetime import datetime, timezone
from pathlib import Path

from app.celery_app import celery_app
from app.database.session import SessionLocal
from app.repositories.dashboard_repository import get_dashboard_stats

BACKUP_DIR = Path("storage") / "backups"


@celery_app.task(name="backups.database_metadata", queue="backups")
def database_metadata_backup() -> dict[str, str]:
    """Write a lightweight operational snapshot for backup monitoring."""

    db = SessionLocal()
    try:
        BACKUP_DIR.mkdir(parents=True, exist_ok=True)
        created_at = datetime.now(timezone.utc)
        path = BACKUP_DIR / f"metadata-{created_at:%Y%m%d%H%M%S}.json"
        path.write_text(
            json.dumps(
                {
                    "created_at": created_at.isoformat(),
                    "dashboard": get_dashboard_stats(db),
                },
                default=str,
                indent=2,
            ),
            encoding="utf-8",
        )
        return {"status": "created", "path": str(path)}
    finally:
        db.close()

def run_database_backup():
    pass

def cleanup_old_backups():
    pass

def verify_backup():
    pass

def backup_uploaded_files():
    pass