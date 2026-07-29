"""Unit tests for Waybar custom module JSON printer."""

import json
import time
from unittest.mock import patch
import pytest

from currency_calc.waybar_module import get_waybar_payload, main


def test_get_waybar_payload_structure():
    rates = {"USD": 1.0, "IDR": 16200.0}
    payload = get_waybar_payload(rates)
    assert isinstance(payload, dict)
    assert "text" in payload
    assert "tooltip" in payload
    assert "class" in payload
    assert payload["class"] == "currency-calc"


def test_get_waybar_payload_formatting():
    rates = {"USD": 1.0, "IDR": 16200.0}
    payload = get_waybar_payload(rates)
    assert payload["text"] == "💵 USD/IDR 16.2k"
    assert payload["tooltip"] == "1 USD = 16,200.00 IDR\nClick to open Currency Calculator"

    rates_alt = {"USD": 1.0, "IDR": 16500.0}
    payload_alt = get_waybar_payload(rates_alt)
    assert payload_alt["text"] == "💵 USD/IDR 16.5k"
    assert payload_alt["tooltip"] == "1 USD = 16,500.00 IDR\nClick to open Currency Calculator"


def test_get_waybar_payload_fallback():
    # If rates is None or missing IDR, it should fall back safely to 16200.0
    payload_none = get_waybar_payload(None)
    assert "USD/IDR" in payload_none["text"]
    assert payload_none["class"] == "currency-calc"

    payload_empty = get_waybar_payload({})
    assert "USD/IDR" in payload_empty["text"]
    assert payload_empty["class"] == "currency-calc"


def test_main_cli_output(capsys):
    with patch("currency_calc.waybar_module.fetch_latest_rates") as mock_fetch:
        mock_fetch.return_value = {"USD": 1.0, "IDR": 16200.0}
        main()

    captured = capsys.readouterr()
    output_data = json.loads(captured.out.strip())
    assert output_data["text"] == "💵 USD/IDR 16.2k"
    assert output_data["class"] == "currency-calc"


def test_performance_under_200ms():
    rates = {"USD": 1.0, "IDR": 16200.0}
    start = time.perf_counter()
    payload = get_waybar_payload(rates)
    elapsed = time.perf_counter() - start
    assert elapsed < 0.2
    assert payload["class"] == "currency-calc"
