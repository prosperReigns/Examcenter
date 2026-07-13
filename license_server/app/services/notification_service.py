from __future__ import annotations

from datetime import datetime, timezone
from uuid import UUID
from fastapi import HTTPException
from sqlalchemy.orm import Session

from app.models.notification import Notification

from app.repositories.notification_repository import (
    create_notification,
    persist_notification,
    list_notifications,
    get_notification,
)

from app.services.audit_service import (
    record_audit_event,
)

EMAIL = "email"
SMS = "sms"
WHATSAPP = "whatsapp"
SYSTEM = "system"

def create_notification_record(
    db: Session,
    *,
    customer_id=None,
    school_id=None,
    channel: str,
    recipient: str,
    subject: str | None = None,
    message: str,
):

    notification = Notification(
        customer_id=customer_id,
        school_id=school_id,
        channel=channel,
        recipient=recipient,
        subject=subject,
        message=message,
        status="pending",
    )

    create_notification(
        db,
        notification,
    )

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
):

    notification = create_notification_record(
        db,
        customer_id=customer_id,
        school_id=school_id,
        channel=EMAIL,
        recipient=recipient,
        subject=subject,
        message=message,
    )

    try:
        # smtp.send(...)
        # sendgrid.send(...)
        notification.status = "sent"

        notification.sent_at = datetime.now(
            timezone.utc,
        )

    except Exception as exc:
        notification.status = "failed"
        notification.error_message = str(exc)

    persist_notification(
        db,
        notification,
    )

    record_audit_event(
        db,
        admin=admin,
        action="notification_email",
        entity_type="notification",
        entity_id=str(notification.id),
        description=f"Email sent to {recipient}",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )

    db.commit()
    db.refresh(notification)

    return notification

def send_sms(
    db: Session,
    *,
    recipient: str,
    message: str,
    customer_id=None,
    school_id=None,
    admin=None,
    request=None,
):

    notification = create_notification_record(
        db,
        customer_id=customer_id,
        school_id=school_id,
        channel=SMS,
        recipient=recipient,
        message=message,
    )

    try:
        # twilio.send()
        notification.status = "sent"
        notification.sent_at = datetime.now(
            timezone.utc,
        )
    except Exception as exc:
        notification.status = "failed"
        notification.error_message = str(exc)
    persist_notification(
        db,
        notification,
    )
    record_audit_event(
        db,
        admin=admin,
        action="notification_email",
        entity_type="notification",
        entity_id=str(notification.id),
        description=f"Email sent to {recipient}",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )

    db.commit()

    return notification

def send_whatsapp(
    db: Session,
    *,
    recipient: str,
    message: str,
    customer_id=None,
    school_id=None,
):

    notification = create_notification_record(
        db,
        customer_id=customer_id,
        school_id=school_id,
        channel=WHATSAPP,
        recipient=recipient,
        message=message,
    )

    try:
        # whatsapp.send()
        notification.status = "sent"
        notification.sent_at = datetime.now(
            timezone.utc,
        )

    except Exception as exc:
        notification.status = "failed"
        notification.error_message = str(exc)

    persist_notification(
        db,
        notification,
    )

    record_audit_event(
        db,
        admin=admin,
        action="notification_email",
        entity_type="notification",
        entity_id=str(notification.id),
        description=f"Email sent to {recipient}",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )
    db.commit()

    return notification

def retry_notification(
    db: Session,
    notification: Notification,
):

    notification.status = "pending"
    notification.error_message = None

    persist_notification(
        db,
        notification,
    )
    record_audit_event(
        db,
        admin=admin,
        action="notification_email",
        entity_type="notification",
        entity_id=str(notification.id),
        description=f"Email sent to {recipient}",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )
    db.commit()

    return notification

def notify_license_renewed(
    db: Session,
    customer,
    school,
    license_obj,
):

    return send_email(
        db,
        recipient=customer.email,
        subject="License Renewed",

        message=(
            f"Your license for "
            f"{school.name} has been renewed "
            f"until {license_obj.expires_at:%d %B %Y}."
        ),

        customer_id=customer.id,
        school_id=school.id,

    )

def notify_receipt_generated(
    db: Session,
    receipt,
):

    return send_email(
        db,
        recipient=receipt.customer.email,
        subject="Payment Receipt",
        message=(
            f"Your payment receipt "
            f"{receipt.receipt_number} "
            "has been generated."
        ),

        customer_id=receipt.customer.id,
        school_id=receipt.school.id,

    )

def notify_invoice_created(
    db: Session,
    invoice,
):

    return send_email(
        db,
        recipient=invoice.customer.email,
        subject="Invoice Created",
        message=(
            f"Invoice "
            f"{invoice.invoice_number} "
            "has been created."
        ),
        customer_id=invoice.customer.id,
        school_id=invoice.school.id,

    )

def get_notification_record(
    db: Session,
    notification_id: UUID,
):

    notification = get_notification(
        db,
        notification_id,
    )

    if notification is None:

        raise HTTPException(
            status_code=404,
            detail="Notification not found.",
        )

    return notification

def get_notification_list(
    db: Session,
    *,
    customer_id=None,
    school_id=None,
    channel=None,
    status_filter=None,
    search=None,
    page: int = 1,
    page_size: int = 20,
):

    offset = (page - 1) * page_size

    return list_notifications(
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
):

    notification = get_notification_record(
        db,
        notification_id,
    )

    notification.status = "read"

    notification.read_at = datetime.now(
        timezone.utc,
    )

    persist_notification(
        db,
        notification,
    )

    record_audit_event(
        db,
        admin=admin,
        action="notification_read",
        entity_type="notification",
        entity_id=str(notification.id),
        description=f"Notification {notification.id} marked as read.",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
    )

    db.commit()

    db.refresh(notification)

    return notification

def mark_all_read(
    db: Session,
    *,
    customer_id=None,
    school_id=None,
):

    notifications, _ = list_notifications(
        db,
        customer_id=customer_id,
        school_id=school_id,
        offset=0,
        limit=100000,
    )

    now = datetime.now(
        timezone.utc,
    )

    for notification in notifications:

        notification.status = "read"

        notification.read_at = now

        persist_notification(
            db,
            notification,
        )

    db.commit()

    return len(notifications)

def delete_notification(
    db: Session,
    notification_id: UUID,
    *,
    admin=None,
    request=None,
):

    notification = get_notification_record(
        db,
        notification_id,
    )

    db.delete(notification)

    record_audit_event(
        db,
        admin=admin,
        action="notification_deleted",
        entity_type="notification",
        entity_id=str(notification.id),
        description="Notification deleted.",
        ip_address=request.client.host if request and request.client else None,
        user_agent=request.headers.get("user-agent") if request else None,
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
):

    return create_notification_record(
        db,
        customer_id=customer_id,
        school_id=school_id,
        channel=SYSTEM,
        recipient="system",
        subject=subject,
        message=message,
    )

def queue_notification():
    pass