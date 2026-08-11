from datetime import datetime, timedelta, timezone

from sqlalchemy.orm import Session

from app.celery_app import celery_app

from app.database.session import SessionLocal

from app.repositories import (
    activation_token_repository,
)

from app.services.audit_service import (
    record_audit_event,
)

from app.services.activation_token_service import (
    ActivationTokenService,
)

from app.repositories.activation_token_repository import (

    delete_expired,
    delete_used_before,

)

import logging

logger = logging.getLogger(__name__)

def get_db() -> Session:
    """
    Create a database session for background tasks.
    """

    db = SessionLocal()

    try:
        yield db
    finally:
        db.close()

@celery_app.task(
    name="activation_tokens.purge_expired",
)
def purge_expired_activation_tokens():
    """
    Delete activation tokens that have expired.
    """

    db = SessionLocal()

    try:

        deleted = (
            activation_token_repository
            .delete_expired(db)
        )

        db.commit()

        return {
            "deleted": deleted,
        }

    finally:

        db.close()

@celery_app.task(
    name="activation_tokens.revoke_stale",
)
def revoke_stale_tokens():
    """
    Revoke activation tokens that have remained
    unused beyond the configured lifetime.
    """

    db = SessionLocal()

    try:

        tokens = (
            activation_token_repository
            .find_stale_tokens(
                db=db,
                older_than_hours=24,
            )
        )

        count = 0

        for token in tokens:

            activation_token_repository.revoke(
                db,
                token,
            )

            count += 1

        db.commit()

        return {

            "revoked": count,

        }

    finally:

        db.close()

@celery_app.task(
    name="activation_tokens.audit_cleanup",
)
def audit_activation_cleanup():
    """
    Record cleanup statistics.
    """

    db = SessionLocal()

    try:

        expired = (
            activation_token_repository
            .count_expired(db)
        )

        used = (
            activation_token_repository
            .count_used(db)
        )

        record_audit_event(

            db=db,

            action="activation_token_cleanup",

            entity_type="activation_token",

            entity_id=None,

            description=(
                f"Expired={expired}, "
                f"Used={used}"
            ),

        )

        db.commit()

    finally:

        db.close()

@celery_app.task(
    name="activation_tokens.delete_used",
)
def delete_old_used_tokens():
    """
    Delete activation tokens that were used
    more than 30 days ago.
    """

    db = SessionLocal()

    try:

        deleted = (
            activation_token_repository
            .delete_used_before(

                db=db,

                before=datetime.utcnow()
                - timedelta(days=30),

            )
        )

        db.commit()

        return {

            "deleted": deleted,

        }

    finally:

        db.close()

@celery_app.task(
    name="activation_tokens.statistics",
)
def activation_token_statistics():
    """
    Return activation token statistics.
    """

    db = SessionLocal()

    try:

        return {

            "active": activation_token_repository.count_active(db),

            "expired": activation_token_repository.count_expired(db),

            "revoked": activation_token_repository.count_revoked(db),

            "used": activation_token_repository.count_used(db),

        }

    finally:

        db.close()

@celery_app.task(

    bind=True,

    autoretry_for=(Exception,),

    retry_backoff=True,

    retry_kwargs={"max_retries": 5},

)
def generate_activation_token_task(

    self,

    purchase_session_id: str,

):

    db = SessionLocal()

    try:

        service = (
            ActivationTokenService(
                db
            )
        )

        token = (
            service.create_for_purchase(
                purchase_session_id
            )
        )

        return {

            "token_id":
            str(token.id),

            "status":
            "success",

        }

    finally:

        db.close()

@celery_app.task(

    bind=True,

    name="activation.cleanup",

)

def cleanup_activation_tokens(self):

    db = SessionLocal()

    try:

        expired = delete_expired(db)

        old_used = delete_used_before(

            db,

            datetime.now(timezone.utc)

            - timedelta(days=30),

        )

        db.commit()

        logger.info(
            "Activation cleanup complete. "
            "Expired=%s Used=%s",
            expired,
            old_used,
        )

        return {
            "expired": expired,
            "used": old_used,
        }

    except Exception:

        db.rollback()

        raise

    finally:

        db.close()
