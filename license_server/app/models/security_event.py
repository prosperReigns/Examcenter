from sqlalchemy import (
    Column,
    Integer,
    String,
    DateTime,
    JSON,
    ForeignKey
)

from datetime import datetime

from app.database import Base



class SecurityEvent(Base):

    __tablename__ = "security_events"


    id = Column(
        Integer,
        primary_key=True
    )


    license_id = Column(
        Integer,
        ForeignKey(
            "licenses.id"
        ),
        nullable=True
    )


    event = Column(
        String(100),
        nullable=False
    )


    message = Column(
        String(500)
    )


    installation_id = Column(
        String(255)
    )


    fingerprint = Column(
        String(255)
    )


    context = Column(
        JSON
    )


    created_at = Column(
        DateTime,
        default=datetime.utcnow
    )