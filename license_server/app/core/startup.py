import logging
from app.repositories.admin_repository import get_admin_count, create_admin
from app.core.config import get_settings, validate_production_settings
from app.auth.security import get_password_hash
from app.database.session import SessionLocal

logger = logging
settings = get_settings()

def bootstrap_application():
    with SessionLocal() as db:
        if (
            get_admin_count(db) == 0
            and settings.bootstrap_admin_email
            and settings.bootstrap_admin_password
            and settings.bootstrap_admin_full_name
        ):
            create_admin(
                db,
                full_name=settings.bootstrap_admin_full_name,
                email=settings.bootstrap_admin_email,
                password_hash=get_password_hash(
                    settings.bootstrap_admin_password
                ),
                role=settings.bootstrap_admin_role,
            )

            db.commit()

async def production_startup_checks():
    logger.info("Running production startup checks")

    # Verify configuration
    validate_production_settings(settings)

    # Verify database connectivity
    db = SessionLocal()
    try:
        db.execute(text("SELECT 1"))
    finally:
        db.close()

    logger.info("Production startup checks completed successfully")
