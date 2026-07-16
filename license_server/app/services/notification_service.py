from __future__ import annotations

from datetime import datetime, timezone
from uuid import UUID

from fastapi import HTTPException
from sqlalchemy.orm import Session

from app.models.notification import Notification
from app.repositories.notification_repository import (
    create_notification,
    get_notification,
    list_notifications as list_notification_records,
    persist_notification,
)
from app.services.audit_service import record_audit_event

EMAIL = "email"
SMS = "sms"
WHATSAPP = "whatsapp"
SYSTEM = "system"


def _request_ip(request) -> str | None:
    return request.client.host if request and request.client else None


def _request_user_agent(request) -> str | None:
    return request.headers.get("user-agent") if request else None


def _audit_notification(
    db: Session,
    notification: Notification,
    *,
    admin=None,
    request=None,
    action: str,
    description: str,
) -> None:
    record_audit_event(
        db,
        admin=admin,
        action=action,
        entity_type="notification",
        entity_id=str(notification.id),
        description=description,
        ip_address=_request_ip(request),
        user_agent=_request_user_agent(request),
    )


def _deliver_notification(
    db: Session,
    notification: Notification,
    *,
    admin=None,
    request=None,
) -> Notification:
    try:
        # Provider integrations plug in here. Until configured, marking sent
        # keeps internal workflows deterministic.
        notification.status = "sent"
        notification.sent_at = datetime.now(timezone.utc)
        notification.error_message = None
    except Exception as exc:
        notification.status = "failed"
        notification.error_message = str(exc)

    persist_notification(db, notification)
    _audit_notification(
        db,
        notification,
        admin=admin,
        request=request,
        action=f"notification_{notification.channel}",
        description=f"{notification.channel.title()} notification sent to {notification.recipient}",
    )
    db.commit()
    db.refresh(notification)
    return notification


def create_notification_record(
    db: Session,
    *,
    customer_id=None,
    school_id=None,
    channel: str,
    recipient: str,
    subject: str | None = None,
    message: str,
) -> Notification:
    notification = Notification(
        customer_id=customer_id,
        school_id=school_id,
        channel=channel,
        recipient=recipient,
        subject=subject,
        message=message,
        status="pending",
    )
    create_notification(db, notification)
    db.commit()
    db.refresh(notification)
    return notification


def send_email(
    db: Session,
    *,
    recipient: str,
    subject: str,
    message: str,
    customer_id=None,
    school_id=None,
    admin=None,
    request=None,
) -> Notification:
    notification = create_notification_record(
        db,
        customer_id=customer_id,
        school_id=school_id,
        channel=EMAIL,
        recipient=recipient,
        subject=subject,
        message=message,
    )
    return _deliver_notification(db, notification, admin=admin, request=request)


def send_sms(
    db: Session,
    *,
    recipient: str,
    message: str,
    customer_id=None,
    school_id=None,
    admin=None,
    request=None,
) -> Notification:
    notification = create_notification_record(
        db,
        customer_id=customer_id,
        school_id=school_id,
        channel=SMS,
        recipient=recipient,
        message=message,
    )
    return _deliver_notification(db, notification, admin=admin, request=request)


def send_whatsapp(
    db: Session,
    *,
    recipient: str,
    message: str,
    customer_id=None,
    school_id=None,
    admin=None,
    request=None,
) -> Notification:
    notification = create_notification_record(
        db,
        customer_id=customer_id,
        school_id=school_id,
        channel=WHATSAPP,
        recipient=recipient,
        message=message,
    )
    return _deliver_notification(db, notification, admin=admin, request=request)


def retry_notification(
    db: Session,
    notification: Notification,
    *,
    admin=None,
    request=None,
) -> Notification:
    notification.status = "pending"
    notification.error_message = None
    notification.sent_at = None
    persist_notification(db, notification)
    return _deliver_notification(db, notification, admin=admin, request=request)


def notify_license_renewed(db: Session, customer, school, license_obj):
    return send_email(
        db,
        recipient=customer.email,
        subject="License Renewed",
        message=(
            f"Your license for {school.name} has been renewed "
            f"until {license_obj.expiry_at:%d %B %Y}."
        ),
        customer_id=customer.id,
        school_id=school.id,
    )


def notify_receipt_generated(db: Session, receipt):
    return send_email(
        db,
        recipient=receipt.customer.email,
        subject="Payment Receipt",
        message=f"Your payment receipt {receipt.receipt_number} has been generated.",
        customer_id=receipt.customer.id,
        school_id=receipt.school.id,
    )


def notify_invoice_created(db: Session, invoice):
    return send_email(
        db,
        recipient=invoice.customer.email,
        subject="Invoice Created",
        message=f"Invoice {invoice.invoice_number} has been created.",
        customer_id=invoice.customer.id,
        school_id=invoice.school.id,
    )


def get_notification_record(db: Session, notification_id: UUID) -> Notification:
    notification = get_notification(db, notification_id)
    if notification is None:
        raise HTTPException(status_code=404, detail="Notification not found.")
    return notification


def get_notification_list(
    db: Session,
    *,
    customer_id=None,
    school_id=None,
    channel=None,
    status_filter=None,
    unread_only: bool = False,
    search=None,
    page: int = 1,
    page_size: int = 20,
):
    offset = (page - 1) * page_size
    if unread_only and status_filter is None:
        status_filter = "sent"

    return list_notification_records(
        db,
        customer_id=customer_id,
        school_id=school_id,
        channel=channel,
        status=status_filter,
        search=search,
        offset=offset,
        limit=page_size,
    )


def mark_as_read(
    db: Session,
    notification_id: UUID,
    *,
    admin=None,
    request=None,
) -> Notification:
    notification = get_notification_record(db, notification_id)
    notification.status = "read"
    notification.read_at = datetime.now(timezone.utc)
    persist_notification(db, notification)
    _audit_notification(
        db,
        notification,
        admin=admin,
        request=request,
        action="notification_read",
        description=f"Notification {notification.id} marked as read.",
    )
    db.commit()
    db.refresh(notification)
    return notification


def mark_all_read(
    db: Session,
    *,
    customer_id=None,
    school_id=None,
    admin=None,
    request=None,
) -> int:
    notifications, _ = list_notification_records(
        db,
        customer_id=customer_id,
        school_id=school_id,
        offset=0,
        limit=100000,
    )
    now = datetime.now(timezone.utc)
    for notification in notifications:
        notification.status = "read"
        notification.read_at = now
        persist_notification(db, notification)

    if notifications:
        record_audit_event(
            db,
            admin=admin,
            action="notifications_read_all",
            entity_type="notification",
            description=f"Marked {len(notifications)} notifications as read.",
            ip_address=_request_ip(request),
            user_agent=_request_user_agent(request),
        )

    db.commit()
    return len(notifications)


def delete_notification(
    db: Session,
    notification_id: UUID,
    *,
    admin=None,
    request=None,
) -> None:
    notification = get_notification_record(db, notification_id)
    db.delete(notification)
    _audit_notification(
        db,
        notification,
        admin=admin,
        request=request,
        action="notification_deleted",
        description="Notification deleted.",
    )
    db.commit()


def send_system_notification(
    db: Session,
    *,
    customer_id=None,
    school_id=None,
    message: str,
    subject: str = "System Notification",
    admin=None,
    request=None,
) -> Notification:
    notification = create_notification_record(
        db,
        customer_id=customer_id,
        school_id=school_id,
        channel=SYSTEM,
        recipient="system",
        subject=subject,
        message=message,
    )
    _audit_notification(
        db,
        notification,
        admin=admin,
        request=request,
        action="notification_system",
        description="System notification created.",
    )
    db.commit()
    db.refresh(notification)
    return notification


def queue_notification(
    db: Session,
    notification_id: UUID,
    *,
    admin=None,
    request=None,
) -> Notification:
    notification = get_notification_record(db, notification_id)
    notification.status = "pending"
    notification.error_message = None
    persist_notification(db, notification)
    _audit_notification(
        db,
        notification,
        admin=admin,
        request=request,
        action="notification_queued",
        description=f"Queued notification {notification.id}.",
    )
    db.commit()
    db.refresh(notification)
    return notification
