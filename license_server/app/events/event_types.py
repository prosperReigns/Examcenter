from dataclasses import dataclass
from uuid import UUID

from app.events.base import DomainEvent


@dataclass(slots=True)
class PaymentVerifiedEvent(DomainEvent):

    payment_id: UUID


@dataclass(slots=True)
class InvoicePaidEvent(DomainEvent):

    invoice_id: UUID


@dataclass(slots=True)
class LicenseRenewedEvent(DomainEvent):

    license_id: UUID


@dataclass(slots=True)
class ReceiptGeneratedEvent(DomainEvent):

    receipt_id: UUID


@dataclass(slots=True)
class LicenseExpiredEvent(DomainEvent):

    license_id: UUID