from sqlalchemy import (
    Column,
    Integer,
    String,
    DateTime,
    Boolean,
    ForeignKey
)
from sqlalchemy.dialects.postgresql import UUID
from datetime import datetime

from app.database.base import Base



class LicenseDownload(Base):

    __tablename__ = "license_downloads"


    id = Column(
        Integer,
        primary_key=True
    )


    license_id = Column(
        UUID(as_uuid=True),
        ForeignKey("licenses.id"),
        nullable=False,
    )


    token = Column(
        String(255),
        unique=True,
        nullable=False,
        index=True
    )


    expires_at = Column(
        DateTime,
        nullable=False
    )


    downloaded = Column(
        Boolean,
        default=False
    )


    downloaded_at = Column(
        DateTime,
        nullable=True
    )


    created_at = Column(
        DateTime,
        default=datetime.utcnow
    )