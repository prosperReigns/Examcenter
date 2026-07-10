from datetime import datetime

from sqlalchemy import Boolean, DateTime, String, Text
from sqlalchemy.orm import mapped_column

from app.database.base import Base
from app.models.base import TimestampMixin, UUIDPrimaryKeyMixin

class PaymentWebhook(
    UUIDPrimaryKeyMixin,
    TimestampMixin,
    Base,
):
    __tablename__ = "payment_webhooks"

    gateway = mapped_column(String(30), index=True)

    event_type = mapped_column(String(100), index=True)

    gateway_reference = mapped_column(
        String(150),
        index=True,
    )

    gateway_transaction_id = mapped_column(
        String(150),
        nullable=True,
        index=True,
    )

    signature = mapped_column(
        String(255),
        nullable=True,
    )

    payload = mapped_column(Text)

    processed = mapped_column(
        Boolean,
        default=False,
        index=True,
    )

    processed_at = mapped_column(
        DateTime(timezone=True),
        nullable=True,
    )

    error_message = mapped_column(
        Text,
        nullable=True,
    )