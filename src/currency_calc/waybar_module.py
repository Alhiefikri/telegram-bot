"""Waybar custom module JSON printer for currency rates."""

import json
import sys
from typing import Any, Optional

from currency_calc.rates import fetch_latest_rates


def get_waybar_payload(rates: Optional[dict[str, float]] = None) -> dict[str, Any]:
    """Generate JSON payload structure for Waybar custom module.

    Args:
        rates: Optional dictionary of exchange rates relative to USD.
            If None or missing IDR, fetch rates or use fallback.

    Returns:
        Dictionary containing 'text', 'tooltip', and 'class' keys.
    """
    if rates is None or not isinstance(rates, dict) or "IDR" not in rates:
        try:
            fetched_rates = fetch_latest_rates(base="USD")
            rates = fetched_rates if fetched_rates else {"USD": 1.0, "IDR": 16200.0}
        except Exception:
            rates = {"USD": 1.0, "IDR": 16200.0}

    idr_rate = rates.get("IDR", 16200.0)
    rate_k = f"{idr_rate / 1000:.1f}k"

    return {
        "text": f"💵 USD/IDR {rate_k}",
        "tooltip": f"1 USD = {idr_rate:,.2f} IDR\nClick to open Currency Calculator",
        "class": "currency-calc",
    }


def main() -> None:
    """CLI entry point for Waybar custom module."""
    rates = fetch_latest_rates(base="USD")
    payload = get_waybar_payload(rates)
    print(json.dumps(payload))


if __name__ == "__main__":
    main()
