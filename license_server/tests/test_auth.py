from jose import jwt

from app.core.config import get_settings


settings = get_settings()


def test_api_login_returns_jwt_token(client, seeded_admin, admin_password):
    response = client.post(
        "/api/login",
        json={"email": "admin@example.com", "password": admin_password},
    )

    assert response.status_code == 200
    body = response.json()
    assert body["token_type"] == "bearer"
    payload = jwt.decode(body["access_token"], settings.jwt_secret, algorithms=[settings.jwt_algorithm])
    assert payload["sub"] == str(seeded_admin.id)


def test_web_login_sets_cookie_and_redirects(client, seeded_admin, admin_password, monkeypatch):
    monkeypatch.setattr("app.main.record_audit_event", lambda *args, **kwargs: None)

    response = client.post(
        "/login",
        data={"email": "admin@example.com", "password": admin_password},
        follow_redirects=False,
    )

    assert response.status_code == 303
    assert response.headers["location"] == "/dashboard"
    assert settings.access_token_cookie_name in response.cookies
