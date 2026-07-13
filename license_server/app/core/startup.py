from app.repositories.admin_repository import get_admin_count, create_admin
from app.core.config import get_settings
from app.auth.security import get_password_hash
from app.database.session import SessionLocal

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
