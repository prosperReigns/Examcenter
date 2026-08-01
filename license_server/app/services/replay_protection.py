from datetime import datetime, timedelta

from sqlalchemy.orm import Session

from app.models.request_nonce import RequestNonce



MAX_AGE_SECONDS = 300



def check_nonce(
    db: Session,
    nonce: str
):


    existing = (
        db.query(RequestNonce)
        .filter(
            RequestNonce.nonce == nonce
        )
        .first()
    )


    if existing:

        return False



    record = RequestNonce(

        nonce=nonce,

        created_at=datetime.utcnow()

    )


    db.add(record)

    db.commit()


    return True





def cleanup_old_nonces(
    db: Session
):


    expiry =
        datetime.utcnow() - timedelta(
            seconds=MAX_AGE_SECONDS
        )


    db.query(RequestNonce)\
      .filter(
          RequestNonce.created_at < expiry
      )\
      .delete()


    db.commit()