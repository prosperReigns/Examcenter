from datetime import datetime
import uuid

from sqlalchemy import DateTime, ForeignKey, Integer, String, Text, func, Numeric
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.database.base import Base
from app.models.base import TimestampMixin, UUIDPrimaryKeyMixin
from app.enums.payment_status import PaymentStatus


class Payment(UUIDPrimaryKeyMixin, TimestampMixin, Base):
    __tablename__ = "payments"

    customer_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("customers.id", ondelete="RESTRICT"), nullable=False, index=True)
    school_id: Mapped[uuid.UUID | None] = mapped_column(UUID(as_uuid=True), ForeignKey("schools.id", ondelete="SET NULL"), nullable=True, index=True)
    invoice_id = mapped_column(
        UUID(as_uuid=True),
        ForeignKey("invoices.id", ondelete="RESTRICT"),
        nullable=False,
        index=True,
    )
    amount: Mapped[int] = mapped_column(Numeric(12,2), nullable=False)
    currency: Mapped[str] = mapped_column(String(10), nullable=False, default="USD")
    status: Mapped[str] = mapped_column(String(30), nullable=False, default=PaymentStatus.PENDING.value, index=True)
    payment_type: Mapped[str] = mapped_column(String(50), nullable=False, index=True)
    payment_reference: Mapped[str] = mapped_column(
        String(50),
        unique=True,
        nullable=False,
        index=True,
    )
    gateway: Mapped[str | None] = mapped_column(
        String(50),
        nullable=False,
        index=True
    )

    gateway_reference: Mapped[str | None] = mapped_column(
        String(255),
        nullable=True,
    )
    paid_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True),
        nullable=True,
    )
    gateway_response: Mapped[str | None] = mapped_column(
        Text,
        nullable=True,
    )
    gateway_transaction_id: Mapped[str | None] = mapped_column(
        String(150),
        nullable=True,
        unique=True,
        index=True,
    )

    gateway_payment_url: Mapped[str | None] = mapped_column(
        String(500),
        nullable=True,
    )
    payment_method: Mapped[str | None] = mapped_column(
        String(40),
        nullable=True,
    )
    invoice_path: Mapped[str | None] = mapped_column(String(500), nullable=True)
    verified_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    raw_payload: Mapped[str | None] = mapped_column(Text, nullable=True)

    renewals = relationship(
        "LicenseRenewal",
        back_populates="payment",
    )
    invoice = relationship(
        "Invoice",
        back_populates="payments",
    )
    school = relationship(
        "School",
        back_populates="payments",
    )
    customer = relationship("Customer", back_populates="payments")
    receipt = relationship(
        "Receipt",
        back_populates="payment",
        uselist=False,
    )