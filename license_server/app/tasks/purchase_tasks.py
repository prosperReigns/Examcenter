from __future__ import annotations

import logging
from typing import Any
from uuid import UUID

from fastapi import HTTPException

from app.celery_app import celery_app
from app.database.session import SessionLocal
from app.repositories.purchase_session_repository import (
    get_purchase_session_by_id_for_update,
    get_purchase_session_by_reference,
    expire_stale_purchase_sessions,
)
from app.repositories.payment_repository import delete_orphan_pending_payments, expire_stale_pending_payments

from app.services.purchase_orchestration_service import complete_purchase
from app.services.purchase_session_service import recover_pending_purchases

from app.tasks.activation_token_tasks import cleanup_activation_tokens

logger = logging.getLogger(__name__)


@celery_app.task(
    queue="purchase",
    bind=True,
    name="purchases.orchestrate",
    max_retries=5,
)
def orchestrate_purchase(
    self,
    *,
    session_id: str | None = None,
    payment_reference: str | None = None,
) -> dict[str, Any]:
    """Run self-service purchase orchestration outside the webhook request."""

    if session_id is None and payment_reference is None:
        raise ValueError("session_id or payment_reference is required")

    db = SessionLocal()
    try:
        purchase_session = None
        if session_id is not None:
            purchase_session = get_purchase_session_by_id_for_update(db, UUID(str(session_id)))
        if purchase_session is None and payment_reference is not None:
            purchase_session = get_purchase_session_by_reference(db, payment_reference)
        if purchase_session is None:
            raise ValueError("Purchase session not found")

        return complete_purchase(db, purchase_session)
    except HTTPException as exc:
        db.rollback()
        if exc.status_code >= 500:
            logger.warning("Retrying purchase orchestration after HTTP %s", exc.status_code)
            raise self.retry(exc=exc, countdown=min(300, 30 * (self.request.retries + 1)))
        raise
    except Exception as exc:
        db.rollback()
        logger.exception("Retrying purchase orchestration task")
        raise self.retry(exc=exc, countdown=min(300, 30 * (self.request.retries + 1)))
    finally:
        db.close()


@celery_app.task(
    bind=True,
    name="purchases.recover_pending",
    max_retries=3,
)
def recover_pending_purchase_sessions(self, *, limit: int = 50) -> dict[str, int]:
    """Resume paid purchase sessions left incomplete by retries or browser exits."""

    db = SessionLocal()
    try:
        return recover_pending_purchases(db, limit=limit)
    except Exception as exc:
        db.rollback()
        logger.exception("Retrying pending purchase recovery task")
        raise self.retry(exc=exc, countdown=min(300, 60 * (self.request.retries + 1)))
    finally:
        db.close()


@celery_app.task(
    bind=True,
    name="purchase.maintenance",
    max_retries=3,
)
def purchase_maintenance(
    self,
):

    db = SessionLocal()

    try:

        expired_sessions = (
            expire_stale_purchase_sessions(
                db
            )
        )

        expired_payments = (
            expire_stale_pending_payments(
                db
            )
        )

        orphan_payments = (
            delete_orphan_pending_payments(
                db
            )
        )

        activation_tokens = (
            cleanup_activation_tokens(
                db
            )
        )

        db.commit()

        return {

            "expired_sessions":
                expired_sessions,

            "expired_payments":
                expired_payments,

            "deleted_orphan_payments":
                orphan_payments,

            "deleted_activation_tokens":
                activation_tokens,

        }

    except Exception as exc:

        db.rollback()

        raise self.retry(

            exc=exc,

            countdown=60,

        )

    finally:

        db.close()