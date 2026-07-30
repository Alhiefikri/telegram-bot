import pytest
from unittest.mock import patch, MagicMock
from fastapi.testclient import TestClient

from src.camouproxy.server import app
from src.camouproxy.storage import save_proxy_record, get_all_proxies


@pytest.fixture
def client(tmp_path):
    db_file = str(tmp_path / "test_server_history.db")
    app.state.db_path = db_file
    with TestClient(app) as c:
        yield c


MOCK_METADATA = {
    "ip": "103.1.2.3",
    "country": "India",
    "country_code": "IN",
    "city": "Mumbai",
    "isp": "Airtel",
    "timezone": "Asia/Kolkata",
    "latency_ms": 120
}


@patch("src.camouproxy.server.detect_ip_metadata")
def test_api_test_proxy_success(mock_detect, client):
    mock_detect.return_value = MOCK_METADATA

    payload = {"proxy": "http://user123:pass456@gate.cliproxy.com:8080"}
    response = client.post("/api/test-proxy", json=payload)

    assert response.status_code == 200
    data = response.json()
    assert data["status"] == "success"
    assert data["metadata"]["ip"] == "103.1.2.3"
    assert data["config"]["username"] == "user123"

    # Verify saved in history
    history = client.get("/api/history").json()
    assert len(history) == 1
    assert history[0]["raw_proxy"] == payload["proxy"]
    assert history[0]["ip"] == "103.1.2.3"


@patch("src.camouproxy.server.detect_ip_metadata")
def test_api_test_proxy_invalid(mock_detect, client):
    response = client.post("/api/test-proxy", json={"proxy": ""})
    assert response.status_code == 400
    assert "detail" in response.json()


@patch("src.camouproxy.server.run_netflix_trial_loop")
@patch("src.camouproxy.server.launch_camoufox_session")
@patch("src.camouproxy.server.detect_ip_metadata")
def test_api_launch_normal_mode(mock_detect, mock_launch, mock_netflix, client):
    mock_detect.return_value = MOCK_METADATA

    payload = {
        "proxy": "103.1.2.3:8080:user:pass",
        "block_media": True,
        "mode": "normal"
    }
    response = client.post("/api/launch", json=payload)

    assert response.status_code == 200
    data = response.json()
    assert data["status"] == "success"
    assert "normal" in data["message"]
    assert data["metadata"]["ip"] == "103.1.2.3"


@patch("src.camouproxy.server.run_netflix_trial_loop")
@patch("src.camouproxy.server.launch_camoufox_session")
@patch("src.camouproxy.server.detect_ip_metadata")
def test_api_launch_netflix_mode(mock_detect, mock_launch, mock_netflix, client):
    mock_detect.return_value = MOCK_METADATA

    payload = {
        "proxy": "103.1.2.3:8080:user:pass",
        "mode": "netflix"
    }
    response = client.post("/api/launch", json=payload)

    assert response.status_code == 200
    data = response.json()
    assert data["status"] == "success"
    assert "netflix" in data["message"]


@patch("src.camouproxy.server.detect_ip_metadata")
def test_api_launch_invalid_proxy(mock_detect, client):
    response = client.post("/api/launch", json={"proxy": "invalid_proxy_format"})
    assert response.status_code == 400


def test_api_get_history_empty(client):
    response = client.get("/api/history")
    assert response.status_code == 200
    assert response.json() == []


def test_api_delete_history(client):
    db_path = app.state.db_path
    save_proxy_record({
        "raw_proxy": "http://proxy1.com:8080",
        "ip": "1.1.1.1",
        "country": "India"
    }, db_path=db_path)
    save_proxy_record({
        "raw_proxy": "http://proxy2.com:8080",
        "ip": "2.2.2.2",
        "country": "USA"
    }, db_path=db_path)

    history = client.get("/api/history").json()
    assert len(history) == 2
    record_to_delete = history[0]

    delete_resp = client.delete(f"/api/history/{record_to_delete['id']}")
    assert delete_resp.status_code == 200
    assert delete_resp.json()["status"] == "success"

    remaining = client.get("/api/history").json()
    assert len(remaining) == 1
    assert remaining[0]["id"] != record_to_delete["id"]


def test_api_delete_history_not_found(client):
    response = client.delete("/api/history/99999")
    assert response.status_code == 404
    assert response.json()["detail"] == "Proxy record not found"


def test_static_files_served(client):
    resp = client.get("/")
    assert resp.status_code == 200
    assert "CamouProxy" in resp.text

    css_resp = client.get("/style.css")
    assert css_resp.status_code == 200
    assert "--bg-dark" in css_resp.text

    js_resp = client.get("/app.js")
    assert js_resp.status_code == 200
    assert "DOMContentLoaded" in js_resp.text

