from app.events.bus import event_bus
from app.events.event_types import PaymentVerifiedEvent
from app.database.session import SessionLocal
from app.repositories.customer_repository import get_customer_by_id
from app.repositories.payment_repository import get_payment_by_id
from app.services.license_renewal_service import renew_license_from_payment
from app.services.notification_service import send_email
from app.services.receipt_service import create_receipt_record
from app.tasks.analytics_tasks import analytics_snapshot


def renew_license_handler(event):
    with SessionLocal() as db:
        renew_license_from_payment(db, payment_id=event.payment_id)


def generate_receipt_handler(event):
    with SessionLocal() as db:
        payment = get_payment_by_id(db, event.payment_id)
        if payment is None:
            return
        create_receipt_record(db, payment)


def notify_customer_handler(event):
    with SessionLocal() as db:
        payment = get_payment_by_id(db, event.payment_id)
        if payment is None or payment.customer_id is None:
            return

        customer = get_customer_by_id(db, payment.customer_id)
        if customer is None or not customer.email:
            return

        send_email(
            db,
            recipient=customer.email,
            subject="Payment verified",
            message=f"Payment {payment.payment_reference} has been verified.",
            customer_id=customer.id,
            school_id=payment.school_id,
        )


def analytics_handler(event):
    analytics_snapshot.delay()


event_bus.subscribe(PaymentVerifiedEvent, renew_license_handler)
event_bus.subscribe(PaymentVerifiedEvent, generate_receipt_handler)
event_bus.subscribe(PaymentVerifiedEvent, notify_customer_handler)
event_bus.subscribe(PaymentVerifiedEvent, analytics_handler)