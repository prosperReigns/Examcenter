from sqlalchemy import Boolean, Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.database.base import Base
from app.models.base import TimestampMixin, UUIDPrimaryKeyMixin


class LicenseProduct(UUIDPrimaryKeyMixin, TimestampMixin, Base):
    __tablename__ = "license_products"

    name: Mapped[str] = mapped_column(String(150), nullable=False, unique=True, index=True)
    license_type: Mapped[str] = mapped_column(String(50), nullable=False, index=True)
    duration_days: Mapped[int | None] = mapped_column(Integer, nullable=True)
    price: Mapped[int] = mapped_column(Integer, nullable=False)
    currency: Mapped[str] = mapped_column(String(10), nullable=False, default="USD")
    features_json: Mapped[str | None] = mapped_column(Text, nullable=True)
    is_active: Mapped[bool] = mapped_column(Boolean, nullable=False, default=True)
