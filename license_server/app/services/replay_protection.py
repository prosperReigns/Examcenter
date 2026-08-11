from datetime import timedelta

from sqlalchemy.orm import Session

from app.models.request_nonce import RequestNonce
from app.utils.time import utcnow


MAX_AGE_SECONDS = 300


def check_nonce(db: Session, nonce: str) -> bool:
    existing = db.query(RequestNonce).filter(RequestNonce.nonce == nonce).first()
    if existing:
        return False

    record = RequestNonce(nonce=nonce, created_at=utcnow())
    db.add(record)
    db.commit()
    return True


def cleanup_old_nonces(db: Session) -> None:
    expiry = utcnow() - timedelta(seconds=MAX_AGE_SECONDS)
    db.query(RequestNonce).filter(RequestNonce.created_at < expiry).delete()
    db.commit()