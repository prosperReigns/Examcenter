from datetime import datetime
import uuid

from sqlalchemy import Boolean, DateTime, ForeignKey, Integer, String, Text, func, Numeric
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.database.base import Base
from app.models.base import SoftDeleteMixin, TimestampMixin, UUIDPrimaryKeyMixin


class License(UUIDPrimaryKeyMixin, TimestampMixin, SoftDeleteMixin, Base):
    __tablename__ = "licenses"

    school_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("schools.id", ondelete="RESTRICT"), nullable=False, index=True)
    machine_fingerprint: Mapped[str] = mapped_column(String(255), nullable=False, index=True)
    license_type: Mapped[str] = mapped_column(String(50), nullable=False, index=True)
    plan_name: Mapped[str] = mapped_column(String(100),  nullable=False, default="Free Trial")
    duration_months: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    is_trial: Mapped[bool] = mapped_column(Boolean, nullable=False, default=False)
    issued_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now(), nullable=False)
    expiry_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True, index=True)
    status: Mapped[str] = mapped_column(String(30), nullable=False, default="active", index=True)
    payment_status: Mapped[str] = mapped_column(String(30), nullable=False, default="pending")
    flutterwave_transaction_id: Mapped[str | None] = mapped_column(String(255),nullable=True)
    flutterwave_reference: Mapped[str | None] = mapped_column(String(255), nullable=True)
    amount_paid: Mapped[int] = mapped_column(Numeric(12,2),nullable=False, default=0)
    currency: Mapped[str] = mapped_column(String(10), nullable=False, default="NGN")
    signed_license: Mapped[str] = mapped_column(Text, nullable=False)
    version: Mapped[int] = mapped_column(Integer, nullable=False, default=1)
    renewal_count: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    last_renewed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True,)
    activation_count: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    max_activations: Mapped[int] = mapped_column(Integer, nullable=False, default=1)
    last_activation_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    renewed_from: Mapped[uuid.UUID | None] = mapped_column(UUID(as_uuid=True), ForeignKey("licenses.id"), nullable=True)
    revoked_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    suspended_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)

    school = relationship("School", back_populates="licenses")
    activations = relationship("Activation", back_populates="license", cascade="all, delete-orphan", lazy="selectin",)
    devices = relationship(
        "LicenseDevice",
        back_populates="license",
        cascade="all, delete-orphan",
    )
    renewals = relationship("LicenseRenewal", back_populates="license", cascade="all, delete-orphan",)
    history = relationship(
    "LicenseHistory",
    back_populates="license",
    cascade="all, delete-orphan",
    order_by="LicenseHistory.version.desc()",)
    invoices = relationship(
        "Invoice",
        back_populates="license",
        cascade="all, delete-orphan",
    )
