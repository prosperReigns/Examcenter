from sqlalchemy import (
    Column,
    Integer,
    String,
    DateTime
)

from datetime import datetime

from app.database import Base



class RequestNonce(Base):

    __tablename__ = "request_nonces"


    id = Column(
        Integer,
        primary_key=True
    )


    nonce = Column(
        String(255),
        unique=True,
        nullable=False,
        index=True
    )


    created_at = Column(
        DateTime,
        default=datetime.utcnow,
        nullable=False
    )