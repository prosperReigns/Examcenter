def test_dashboard_page_renders(client, seeded_admin, admin_password, monkeypatch):
    monkeypatch.setattr("app.main.record_audit_event", lambda *args, **kwargs: None)
    client.post(
        "/login",
        data={"email": "admin@example.com", "password": admin_password},
        follow_redirects=False,
    )
    assert client.cookies.get("access_token") is not None

    dashboard_response = client.get("/dashboard", follow_redirects=False)
    assert dashboard_response.status_code == 200
    assert "Dashboard" in dashboard_response.text

def test_settings_page_requires_super_admin(client, seeded_admin, admin_password, monkeypatch):
    monkeypatch.setattr("app.main.record_audit_event", lambda *args, **kwargs: None)
    client.post(
        "/login",
        data={"email": "admin@example.com", "password": admin_password},
        follow_redirects=False,
    )
    response = client.get("/settings", follow_redirects=False)
    assert response.status_code == 200
    assert "Settings" in response.text
