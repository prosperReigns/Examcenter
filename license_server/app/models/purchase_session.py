from uuid import uuid4
from datetime import datetime

from sqlalchemy import (
    String,
    DateTime,
    Boolean,
    Integer,
    Numeric,
)

from sqlalchemy.dialects.postgresql import UUID

from sqlalchemy.orm import Mapped
from sqlalchemy.orm import mapped_column

from app.database.base import Base

class PurchaseSession(Base):

    __tablename__ = "purchase_sessions"

    id: Mapped[UUID] = mapped_column(
        UUID(as_uuid=True),
        primary_key=True,
        default=uuid4,
    )

    fingerprint: Mapped[str]

    product_code: Mapped[str]

    version: Mapped[str]

    plan_code: Mapped[str]

    duration_months: Mapped[int]

    amount: Mapped[float] = mapped_column(
        Numeric(12, 2),
    )

    currency: Mapped[str]

    customer_name: Mapped[str]

    customer_email: Mapped[str]

    customer_phone: Mapped[str]

    school_name: Mapped[str]

    payment_reference: Mapped[str | None]

    status: Mapped[str] = mapped_column(
        default="pending",
    )

    gateway: Mapped[str | None]

    completed: Mapped[bool] = mapped_column(
        Boolean,
        default=False,
    )

    expires_at: Mapped[datetime]

    created_at: Mapped[datetime]

    updated_at: Mapped[datetime]