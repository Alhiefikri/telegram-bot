import pytest
from unittest.mock import AsyncMock, MagicMock, patch
import httpx
from src.camouproxy.ip_detector import detect_ip_metadata

@pytest.mark.asyncio
async def test_detect_ip_metadata_success():
    mock_response = MagicMock()
    mock_response.json.return_value = {
        "status": "success",
        "query": "103.1.2.3",
        "country": "India",
        "countryCode": "IN",
        "city": "Mumbai",
        "isp": "Reliance Jio",
        "timezone": "Asia/Kolkata"
    }

    proxy_config = {
        "server": "http://103.1.2.3:8080",
        "username": "user123",
        "password": "pass456"
    }

    with patch("httpx.AsyncClient.get", new_callable=AsyncMock) as mock_get:
        mock_get.return_value = mock_response
        meta = await detect_ip_metadata(proxy_config)
        assert meta["ip"] == "103.1.2.3"
        assert meta["country"] == "India"
        assert meta["country_code"] == "IN"
        assert meta["city"] == "Mumbai"
        assert meta["isp"] == "Reliance Jio"
        assert meta["timezone"] == "Asia/Kolkata"
        assert isinstance(meta["latency_ms"], int)

@pytest.mark.asyncio
async def test_detect_ip_metadata_failure():
    mock_response = MagicMock()
    mock_response.json.return_value = {
        "status": "fail",
        "message": "invalid query"
    }

    proxy_config = {
        "server": "http://103.1.2.3:8080",
        "username": "",
        "password": ""
    }

    with patch("httpx.AsyncClient.get", new_callable=AsyncMock) as mock_get:
        mock_get.return_value = mock_response
        with pytest.raises(RuntimeError, match="Failed to fetch IP geolocation metadata"):
            await detect_ip_metadata(proxy_config)
