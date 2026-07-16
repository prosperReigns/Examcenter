from app.models.activation import Activation
from app.models.license import License


def _start_payload(payment_reference: str | None = None) -> dict:
    payload = {
        "fingerprint": "machine-fingerprint-public-001",
        "product_code": "cbt",
        "version": "1.0",
        "plan_code": "annual",
        "duration_months": 12,
        "amount": "25000.00",
        "currency": "NGN",
        "customer_name": "Public School",
        "customer_email": "admin@public-school.test",
        "customer_phone": "08000000003",
        "school_name": "Public School",
        "gateway": "flutterwave",
    }
    if payment_reference:
        payload["payment_reference"] = payment_reference
    return payload


def test_public_activation_flow_uses_single_use_token(client, testing_session_local):
    start_response = client.post("/public/start-activation", json=_start_payload())
    assert start_response.status_code == 200
    session_id = start_response.json()["id"]

    pending_status = client.get(f"/public/status?session_id={session_id}")
    assert pending_status.status_code == 200
    assert pending_status.json()["license_ready"] is False

    complete_response = client.post(
        "/public/complete-payment",
        json={
            "session_id": session_id,
            "payment_reference": "FW-PUBLIC-001",
            "gateway_reference": "FLW-PUBLIC-001",
            "gateway_transaction_id": "123456",
            "gateway_response": '{"status":"successful"}',
        },
    )
    assert complete_response.status_code == 200
    complete_payload = complete_response.json()
    assert complete_payload["status"] == "completed"
    assert complete_payload["activation_token"]

    completed_status = client.get("/public/status?payment_reference=FW-PUBLIC-001")
    assert completed_status.status_code == 200
    assert completed_status.json()["license_ready"] is True
    assert completed_status.json()["activation_token"] == complete_payload["activation_token"]

    license_response = client.get(
        "/public/license",
        params={
            "activation_token": complete_payload["activation_token"],
            "fingerprint": "machine-fingerprint-public-001",
        },
    )
    assert license_response.status_code == 200
    license_payload = license_response.json()
    assert license_payload["success"] is True
    assert license_payload["license"]
    assert license_payload["license_package"]["package_type"] == "cbt_offline_license"
    assert license_payload["license_package"]["signature"]
    assert license_payload["license_package"]["checksum"]
    assert license_payload["license_package"]["public_key_version"] == "v1"
    assert license_payload["license_package"]["license"]["machine"] == "machine-fingerprint-public-001"
    assert license_payload["license_package"]["license"]["product_code"] == "cbt"
    assert license_payload["license_package"]["license"]["plan_code"] == "annual"
    assert license_payload["activation_id"]

    reused_token_response = client.get(
        "/public/license",
        params={
            "activation_token": complete_payload["activation_token"],
            "fingerprint": "machine-fingerprint-public-001",
        },
    )
    assert reused_token_response.status_code == 403

    used_status = client.get("/public/status?payment_reference=FW-PUBLIC-001")
    assert used_status.status_code == 200
    assert used_status.json()["activation_token"] is None

    with testing_session_local() as db:
        license_obj = db.get(License, complete_payload["license_id"])
        activation = db.get(Activation, license_payload["activation_id"])
        assert license_obj is not None
        assert activation is not None
        assert license_obj.activation_count == 1


def test_public_check_renewal_rejects_wrong_fingerprint(client):
    start_response = client.post(
        "/public/start-activation",
        json=_start_payload("FW-PUBLIC-002"),
    )
    assert start_response.status_code == 200

    complete_response = client.post(
        "/public/complete-payment",
        json={"payment_reference": "FW-PUBLIC-002"},
    )
    assert complete_response.status_code == 200
    complete_payload = complete_response.json()

    renewal_response = client.post(
        "/public/check-renewal",
        json={
            "activation_token": complete_payload["activation_token"],
            "machine_fingerprint": "different-machine-fingerprint",
        },
    )
    assert renewal_response.status_code == 403

    valid_response = client.post(
        "/public/check-renewal",
        json={
            "license_key": complete_payload["license_id"],
            "machine_fingerprint": "machine-fingerprint-public-001",
        },
    )
    assert valid_response.status_code == 200
    assert valid_response.json()["valid"] is True
