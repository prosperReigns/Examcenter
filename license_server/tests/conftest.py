import pytest
from fastapi.testclient import TestClient
from sqlalchemy import create_engine
from sqlalchemy.orm import sessionmaker
from sqlalchemy.pool import StaticPool

import app.database.session as db_session_module
import app.main as main_module
from app.auth.security import get_password_hash
from app.database.base import Base
from app.models.admin import Admin


def _test_password_hash(password: str) -> str:
    return f"test-hash:{password}"


@pytest.fixture()
def testing_session_local(monkeypatch):
    engine = create_engine(
        "sqlite+pysqlite:///:memory:",
        connect_args={"check_same_thread": False},
        poolclass=StaticPool,
    )
    TestingSessionLocal = sessionmaker(bind=engine, autoflush=False, autocommit=False)
    Base.metadata.create_all(bind=engine)
    monkeypatch.setattr(main_module, "SessionLocal", TestingSessionLocal)
    monkeypatch.setattr(db_session_module, "SessionLocal", TestingSessionLocal)
    return TestingSessionLocal


@pytest.fixture()
def client(testing_session_local):
    return TestClient(main_module.app)


@pytest.fixture()
def admin_password() -> str:
    return "StrongPassword123!"


@pytest.fixture()
def seeded_admin(testing_session_local, admin_password):
    with testing_session_local() as db:
        admin = Admin(
            full_name="Test Admin",
            email="admin@example.com",
            password_hash=_test_password_hash(admin_password),
            role="Super Admin",
            is_active=True,
        )
        db.add(admin)
        db.commit()
        db.refresh(admin)
        return admin


@pytest.fixture(autouse=True)
def patch_password_helpers(monkeypatch):
    monkeypatch.setattr("app.auth.security.get_password_hash", _test_password_hash)
    monkeypatch.setattr("app.auth.security.verify_password", lambda plain, hashed: hashed == _test_password_hash(plain))
    monkeypatch.setattr("app.services.auth_service.verify_password", lambda plain, hashed: hashed == _test_password_hash(plain))
    monkeypatch.setattr("app.main.get_password_hash", _test_password_hash)
