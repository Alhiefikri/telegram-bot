import pytest
from unittest.mock import patch, MagicMock, mock_open
import json
import urllib.error

from currency_calc.rates import fetch_latest_rates, convert_currency

def test_convert_currency_standard():
    rates = {"USD": 1.0, "IDR": 16200.0, "EUR": 0.92}
    
    # USD to IDR
    assert convert_currency(100.0, "USD", "IDR", rates) == 1620000.0
    
    # IDR to USD
    assert convert_currency(16200.0, "IDR", "USD", rates) == 1.0
    
    # EUR to IDR: 100 / 0.92 * 16200
    expected = (100.0 / 0.92) * 16200.0
    assert pytest.approx(convert_currency(100.0, "EUR", "IDR", rates)) == expected
    
    # Case insensitivity
    assert convert_currency(100.0, "usd", "idr", rates) == 1620000.0

def test_convert_currency_unknown_currency():
    rates = {"USD": 1.0, "IDR": 16200.0}
    assert convert_currency(50.0, "UNKNOWN", "IDR", rates) == 50.0
    assert convert_currency(50.0, "USD", "UNKNOWN", rates) == 50.0
    assert convert_currency(50.0, "ABC", "XYZ", rates) == 50.0

def test_convert_currency_invalid_rate():
    rates = {"USD": 0.0, "IDR": 16200.0}
    assert convert_currency(50.0, "USD", "IDR", rates) == 50.0

@patch("urllib.request.urlopen")
@patch("pathlib.Path.open", new_callable=mock_open)
@patch("pathlib.Path.mkdir")
def test_fetch_latest_rates_success(mock_mkdir, mock_file_open, mock_urlopen):
    api_response = {
        "amount": 1.0,
        "base": "USD",
        "date": "2026-07-28",
        "rates": {"IDR": 16000.0, "EUR": 0.90}
    }
    mock_response = MagicMock()
    mock_response.status = 200
    mock_response.read.return_value = json.dumps(api_response).encode("utf-8")
    mock_response.__enter__.return_value = mock_response
    mock_urlopen.return_value = mock_response

    rates = fetch_latest_rates("USD")
    
    assert rates["USD"] == 1.0
    assert rates["IDR"] == 16000.0
    assert rates["EUR"] == 0.90
    mock_urlopen.assert_called_once()
    
    # Check request details
    req = mock_urlopen.call_args[0][0]
    assert req.full_url == "https://api.frankfurter.app/latest?from=USD"
    assert req.headers.get("User-agent") == "CurrencyCalc/1.0" or req.headers.get("User-Agent") == "CurrencyCalc/1.0"
    assert mock_urlopen.call_args[1].get("timeout") == 5

@patch("urllib.request.urlopen", side_effect=urllib.error.URLError("Offline"))
@patch("pathlib.Path.exists", return_value=True)
@patch("pathlib.Path.open")
def test_fetch_latest_rates_api_failure_with_cache(mock_file_open, mock_exists, mock_urlopen):
    cached_data = {
        "timestamp": 123456789.0,
        "base": "USD",
        "rates": {"USD": 1.0, "IDR": 15500.0, "EUR": 0.91}
    }
    mock_file_open.return_value.__enter__.return_value.read.return_value = json.dumps(cached_data)

    rates = fetch_latest_rates("USD")
    assert rates == {"USD": 1.0, "IDR": 15500.0, "EUR": 0.91}

@patch("urllib.request.urlopen", side_effect=urllib.error.URLError("Offline"))
@patch("pathlib.Path.exists", return_value=False)
def test_fetch_latest_rates_api_failure_no_cache(mock_exists, mock_urlopen):
    rates = fetch_latest_rates("USD")
    expected_fallback = {"USD": 1.0, "IDR": 16200.0, "EUR": 0.92, "SGD": 1.35, "JPY": 155.0}
    assert rates == expected_fallback
