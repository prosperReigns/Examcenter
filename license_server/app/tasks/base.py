from contextlib import contextmanager

from app.database.session import SessionLocal


@contextmanager
def db_session():

    db = SessionLocal()

    try:

        yield db

        db.commit()

    except Exception:

        db.rollback()

        raise

    finally:

        db.close()