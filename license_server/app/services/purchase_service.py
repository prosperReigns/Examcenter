from sqlalchemy.orm import Session

from app.schemas.payment import PaymentInitializationResponse

from app.services.customer_service import (
    create_customer_if_not_exists,
)

from app.services.school_service import (
    create_school_if_not_exists,
)

from app.services.license_management_service import (
    issue_license,
)

from app.services.activation_service import (
    activate_license,
)

from app.services.invoice_service import (
    create_invoice_record,
)

from app.services.receipt_service import (
    create_receipt_record,
)

from app.services.notification_service import (
    queue_notification,
)

from app.services.audit_service import (
    record_audit_event,
)

from app.repositories.customer_repository import (
    get_customer_by_email,
)

from app.services.customer_service import (
    create_customer,
)

from app.repositories.school_repository import (
    get_school_by_name,
)

from app.services.school_service import (
    create_school,
)

from app.repositories.payment_repository import (
    get_payment_by_reference,
)

from app.services.payment_service import (
    create_payment_record,
)

from app.repositories.invoice_repository import (
    get_invoice_by_payment,
)

from app.services.invoice_service import (
    create_invoice,
)

from app.schemas.license import (
    LicenseCreateRequest,
)

from app.repositories.license_device_repository import (
    get_device_by_fingerprint,
)

from app.services.device_service import (
    register_device,
)
from app.services.activation_service import (
    create_activation,
)

from app.repositories.receipt_repository import (
    get_receipt_by_payment,
)

from app.services.receipt_service import (
    create_receipt,
)

from app.tasks.notification_tasks import (
    queue_notification,
)

from app.services.audit_service import (
    record_audit_event,
)

from app.services.purchase_service import (
    PurchaseContext,
    complete_purchase,
)

from dataclasses import dataclass


@dataclass
class PurchaseContext:

    fingerprint: str

    product_code: str

    version: str

    plan: str

    school_name: str

    customer_name: str

    customer_email: str

    customer_phone: str

    payment_reference: str

    gateway: str

    amount: float

    currency: str

    def complete_purchase(
        db: Session,
        context: PurchaseContext,
    ):
        """
        Complete an end-to-end purchase.

        This function is the single entry point
        for every successful payment.

        Responsibilities:

        1. Customer
        2. School
        3. Invoice
        4. Payment
        5. License
        6. Device
        7. Activation
        8. Receipt
        9. Notifications
        10. Audit
        """

        try:
            customer = _create_customer(db, context)

            school = _create_school(db, context, customer)

            invoice = _create_invoice(db, context, customer, school, payment)

            payment = _record_payment(db, context, customer)

            license = _issue_license(db, context, school, payment, invoice)

            device = _register_device(db, context, school)

            activation = _activate_license(db, license, device)

            receipt = _create_receipt(db, payment, invoice)

            _queue_notifications(customer, school, license, invoice, receipt)

            _write_audit_log(db, customer, school, license)

            db.commit()
            return license
        except Exception:
            db.rollback()
            raise

        def _create_customer(
            db: Session,
            context: PurchaseContext,
        ):
            """
            Returns an existing customer if one already
            exists for the supplied email, otherwise
            creates a new customer.
            """

            customer = get_customer_by_email(
                db,
                context.customer_email,
            )

            if customer is not None:
                return customer

            customer = create_customer(
                db=db,
                full_name=context.customer_name,
                email=context.customer_email,
                phone=context.customer_phone,
            )

            return customer


        def _create_school(
            db: Session,
            context: PurchaseContext,
            customer,
        ):
            """
            Returns an existing school if it already
            exists, otherwise creates one and links it
            to the customer.
            """

            school = get_school_by_name(
                db,
                context.school_name,
            )

            if school is not None:
                return school

            school = create_school(
                db=db,
                customer_id=customer.id,
                school_name=context.school_name,
            )

            return school


        def _create_invoice(
            db: Session,
            context: PurchaseContext,
            customer,
            school,
            payment,
        ):
            """
            Creates an invoice for this purchase if one
            has not already been created.
            """

            invoice = get_invoice_by_payment(
                db,
                payment.id,
            )

            if invoice is not None:
                return invoice

            invoice = create_invoice(
                db=db,
                customer_id=customer.id,
                school_id=school.id,
                payment_id=payment.id,
                amount=context.amount,
                currency=context.currency,
                plan=context.plan,
            )

            return invoice


        def _record_payment(
            db: Session,
            context: PurchaseContext,
            customer,
        ):
            """
            Records the successful payment if it has
            not already been stored.
            """

            payment = get_payment_by_reference(
                db,
                context.payment_reference,
            )

            if payment is not None:
                return payment

            payment = create_payment_record(
                db=db,
                customer_id=customer.id,
                reference=context.payment_reference,
                gateway=context.gateway,
                amount=context.amount,
                currency=context.currency,
                status="successful",
            )

            return payment


        def _issue_license(
            db: Session,
            context: PurchaseContext,
            school,
            payment,
            invoice,
        ):
            """
            Issues the production license.

            If the purchase has already produced a
            license, reuse it.
            """

            existing = payment.license

            if existing is not None:
                return existing

            payload = LicenseCreateRequest(

                school_id=school.id,

                product_code=context.product_code,

                version=context.version,

                plan=context.plan,

                machine_fingerprint=context.fingerprint,

                payment_id=payment.id,

                invoice_id=invoice.id,
            )

            license = issue_license(
                db=db,
                payload=payload,
            )

            return license


        def _register_device(
            db: Session,
            context: PurchaseContext,
            school,
        ):
            """
            Registers the purchasing computer.

            If the device already exists, it is reused.
            """

            device = get_device_by_fingerprint(
                db,
                context.fingerprint,
            )

            if device is not None:
                return device

            device = register_device(
                db=db,
                school_id=school.id,
                fingerprint=context.fingerprint,
                product_code=context.product_code,
                version=context.version,
            )

            return device


        def _activate_license(
            db: Session,
            license,
            device,
        ):
            """
            Activates the issued license on the
            purchasing device.

            Safe to call multiple times.
            """

            activation = create_activation(
                db=db,
                license_id=license.id,
                device_id=device.id,
            )

            return activation


        def _create_receipt(
            db: Session,
            payment,
            invoice,
        ):
            """
            Creates a receipt for a successful payment.

            Safe for retries.
            """

            receipt = get_receipt_by_payment(
                db,
                payment.id,
            )

            if receipt is not None:
                return receipt

            receipt = create_receipt(
                db=db,
                payment_id=payment.id,
                invoice_id=invoice.id,
            )

            return receipt


        def _queue_notifications(
            customer,
            school,
            license,
            invoice,
            receipt,
        ):
            """
            Queue all notifications for this purchase.
            """

            queue_notification.delay(

                event="license_created",

                customer_id=str(customer.id),

                school_id=str(school.id),

                license_id=str(license.id),

                invoice_id=str(invoice.id),

                receipt_id=str(receipt.id),

            )


        def _write_audit_log(
            db: Session,
            customer,
            school,
            license,
        ):
            """
            Record the completed purchase.
            """

            record_audit_event(

                db=db,

                action="license_purchase",

                entity_type="license",

                entity_id=str(license.id),

                description=(
                    f"License purchased by "
                    f"{customer.email} "
                    f"for {school.name}"
                ),

            )