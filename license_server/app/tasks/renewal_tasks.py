from __future__ import annotations

from datetime import datetime, timedelta, timezone

from sqlalchemy import select

from app.celery_app import celery_app
from app.database.session import SessionLocal
from app.models.license import License
from app.models.notification import Notification
from app.services.audit_service import record_audit_event


@celery_app.task(name="renewals.expire_licenses")
def expire_licenses() -> dict[str, int]:
    """Mark active, time-limited licenses as expired once their expiry passes."""

    db = SessionLocal()
    try:
        now = datetime.now(timezone.utc)
        licenses = db.scalars(
            select(License).where(
                License.deleted_at.is_(None),
                License.status == "active",
                License.expiry_at.is_not(None),
                License.expiry_at < now,
            )
        ).all()

        for license_obj in licenses:
            license_obj.status = "expired"
            license_obj.payment_status = "expired"
            db.add(license_obj)
            record_audit_event(
                db,
                action="license_expired",
                entity_type="license",
                entity_id=str(license_obj.id),
                description="License expired automatically in background worker.",
            )

        db.commit()
        return {"expired": len(licenses)}
    except Exception:
        db.rollback()
        raise
    finally:
        db.close()


@celery_app.task(name="renewals.send_expiry_reminders")
def send_expiry_reminders(*, days: int = 30) -> dict[str, int]:
    """Create email notifications for active licenses expiring soon."""

    db = SessionLocal()
    try:
        now = datetime.now(timezone.utc)
        cutoff = now + timedelta(days=days)
        licenses = db.scalars(
            select(License).where(
                License.deleted_at.is_(None),
                License.status == "active",
                License.expiry_at.is_not(None),
                License.expiry_at >= now,
                License.expiry_at <= cutoff,
            )
        ).all()

        queued = 0
        for license_obj in licenses:
            school = license_obj.school
            customer = school.customer if school is not None else None
            recipient = (
                school.contact_email
                if school is not None and school.contact_email
                else customer.email
                if customer is not None
                else None
            )
            if not recipient:
                continue

            subject = "License Expiry Reminder"
            existing = db.scalar(
                select(Notification).where(
                    Notification.channel == "email",
                    Notification.recipient == recipient,
                    Notification.subject == subject,
                    Notification.status.in_(("pending", "sent")),
                    Notification.message.contains(str(license_obj.id)),
                )
            )
            if existing is not None:
                continue

            db.add(
                Notification(
                    customer_id=customer.id if customer is not None else None,
                    school_id=school.id if school is not None else None,
                    channel="email",
                    recipient=recipient,
                    subject=subject,
                    message=(
                        f"License {license_obj.id} for "
                        f"{school.name if school is not None else 'your school'} "
                        f"expires on {license_obj.expiry_at:%Y-%m-%d}."
                    ),
                    status="pending",
                )
            )
            queued += 1

        if queued:
            record_audit_event(
                db,
                action="license_expiry_reminders_queued",
                entity_type="license",
                entity_id=None,
                description=f"Queued {queued} expiry reminder notification(s).",
            )

        db.commit()
        return {"checked": len(licenses), "queued": queued}
    except Exception:
        db.rollback()
        raise
    finally:
        db.close()
