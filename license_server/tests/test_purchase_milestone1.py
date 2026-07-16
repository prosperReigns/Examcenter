from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from decimal import Decimal

from sqlalchemy import func, select

from app.models.activation import Activation
from app.models.customer import Customer
from app.models.invoice import Invoice
from app.models.license import License
from app.models.license_device import LicenseDevice
from app.models.outbox_event import OutboxEvent
from app.models.payment import Payment
from app.models.purchase_session import PurchaseSession
from app.models.receipt import Receipt
from app.schemas.purchase_session import PurchaseSessionCreate
from app.services.purchase_orchestration_service import complete_purchase
from app.services.purchase_session_service import start_purchase


@dataclass
class DummySignedLicense:
    issued_at: datetime
    expiry: datetime

    def model_dump_json(self) -> str:
        return '{"license":"signed"}'


def _patch_signing(monkeypatch):
    def fake_create_signed_license(**kwargs):
        issued_at = kwargs["issued_at"]
        return DummySignedLicense(
            issued_at=issued_at,
            expiry=issued_at + timedelta(days=365),
        )

    monkeypatch.setattr(
        "app.services.purchase_orchestration_service.create_signed_license",
        fake_create_signed_license,
    )


def _count(db, model) -> int:
    return db.scalar(select(func.count()).select_from(model)) or 0


def test_complete_purchase_is_idempotent(testing_session_local, monkeypatch):
    _patch_signing(monkeypatch)

    with testing_session_local() as db:
        purchase_session = PurchaseSession(
            fingerprint="machine-fingerprint-001",
            product_code="cbt",
            version="1.0",
            plan_code="annual",
            duration_months=12,
            amount=Decimal("25000.00"),
            currency="NGN",
            customer_name="Blue School",
            customer_email="admin@blue-school.test",
            customer_phone="08000000000",
            school_name="Blue School",
            payment_reference="FW-REF-001",
            gateway="flutterwave",
            expires_at=datetime.now(timezone.utc) + timedelta(hours=1),
        )
        db.add(purchase_session)
        db.commit()
        db.refresh(purchase_session)

        first_result = complete_purchase(db, purchase_session)
        second_result = complete_purchase(db, purchase_session)

        assert first_result["status"] == "completed"
        assert second_result["status"] == "completed"
        assert first_result["license_id"] == second_result["license_id"]
        assert _count(db, Customer) == 1
        assert _count(db, License) == 1
        assert _count(db, Invoice) == 1
        assert _count(db, Payment) == 1
        assert _count(db, LicenseDevice) == 1
        assert _count(db, Activation) == 1
        assert _count(db, Receipt) == 1
        assert _count(db, OutboxEvent) == 1


def test_start_purchase_reuses_resumable_session(testing_session_local):
    payload = PurchaseSessionCreate(
        fingerprint="machine-fingerprint-002",
        product_code="cbt",
        version="1.0",
        plan_code="annual",
        duration_months=12,
        amount=Decimal("25000.00"),
        currency="NGN",
        customer_name="Green School",
        customer_email="admin@green-school.test",
        customer_phone="08000000001",
        school_name="Green School",
        gateway="flutterwave",
    )

    with testing_session_local() as db:
        first_session = start_purchase(db, payload)
        second_session = start_purchase(db, payload)

        assert first_session.id == second_session.id
        assert first_session.status == "pending"
        assert _count(db, PurchaseSession) == 1


def test_flutterwave_webhook_queues_purchase_orchestration(
    client,
    testing_session_local,
    monkeypatch,
):
    queued: dict[str, str] = {}

    def fake_queue_purchase_orchestration(*, session_id, payment_reference):
        queued["session_id"] = str(session_id)
        queued["payment_reference"] = payment_reference
        return "task-001"

    monkeypatch.setattr("app.services.payment_service.settings.flutterwave_hash", "testhash")
    monkeypatch.setattr(
        "app.services.payment_service.queue_purchase_orchestration",
        fake_queue_purchase_orchestration,
    )

    with testing_session_local() as db:
        purchase_session = PurchaseSession(
            fingerprint="machine-fingerprint-003",
            product_code="cbt",
            version="1.0",
            plan_code="annual",
            duration_months=12,
            amount=Decimal("25000.00"),
            currency="NGN",
            customer_name="Gold School",
            customer_email="admin@gold-school.test",
            customer_phone="08000000002",
            school_name="Gold School",
            payment_reference="FW-REF-003",
            gateway="flutterwave",
            expires_at=datetime.now(timezone.utc) + timedelta(hours=1),
        )
        db.add(purchase_session)
        db.commit()
        session_id = str(purchase_session.id)

    response = client.post(
        "/api/webhooks/flutterwave",
        headers={"verif-hash": "testhash"},
        json={
            "event": "charge.completed",
            "data": {
                "tx_ref": "FW-REF-003",
                "status": "successful",
                "amount": "25000.00",
                "currency": "NGN",
                "id": "12345",
                "flw_ref": "FLW-12345",
            },
        },
    )

    assert response.status_code == 200
    assert response.json()["status"] == "queued"
    assert response.json()["task_id"] == "task-001"
    assert queued == {
        "session_id": session_id,
        "payment_reference": "FW-REF-003",
    }

    with testing_session_local() as db:
        purchase_session = db.get(PurchaseSession, session_id)
        assert purchase_session.status == "payment_verified"
        assert purchase_session.gateway_response is not None
        assert _count(db, License) == 0
