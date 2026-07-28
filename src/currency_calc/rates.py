"""Rates fetcher with local cache and currency conversion utilities."""

import json
import pathlib
import time
import urllib.request
import urllib.error

FALLBACK_RATES: dict[str, float] = {
    "USD": 1.0,
    "IDR": 16200.0,
    "EUR": 0.92,
    "SGD": 1.35,
    "JPY": 155.0,
}

CACHE_PATH: pathlib.Path = pathlib.Path.home() / ".cache" / "currency-calculator" / "rates.json"


def fetch_latest_rates(base: str = "USD") -> dict[str, float]:
    """Fetch latest exchange rates from API with caching and offline fallback.
    
    Args:
        base: Base currency code (default "USD").
        
    Returns:
        Dictionary mapping currency codes to exchange rates relative to base currency.
    """
    base_code = base.upper()
    url = f"https://api.frankfurter.app/latest?from={base_code}"
    req = urllib.request.Request(url, headers={"User-Agent": "CurrencyCalc/1.0"})

    try:
        with urllib.request.urlopen(req, timeout=5) as response:
            if response.status == 200:
                data = json.loads(response.read().decode("utf-8"))
                rates = dict(data.get("rates", {}))
                rates[base_code] = 1.0

                # Save cache payload
                try:
                    CACHE_PATH.parent.mkdir(parents=True, exist_ok=True)
                    cache_payload = {
                        "timestamp": time.time(),
                        "base": base_code,
                        "rates": rates,
                    }
                    with CACHE_PATH.open("w", encoding="utf-8") as f:
                        json.dump(cache_payload, f, indent=2)
                except Exception:
                    # Ignore write failures (e.g. read-only filesystem)
                    pass

                return rates
    except Exception:
        # Fall back to local cache or default fallback rates on API failure
        pass

    # Try loading from local cache
    if CACHE_PATH.exists():
        try:
            with CACHE_PATH.open("r", encoding="utf-8") as f:
                payload = json.load(f)
                if isinstance(payload, dict) and "rates" in payload:
                    return payload["rates"]
        except Exception:
            pass

    return dict(FALLBACK_RATES)


def convert_currency(amount: float, from_curr: str, to_curr: str, rates: dict[str, float]) -> float:
    """Convert amount from one currency to another using exchange rates.
    
    Formula:
        usd_amount = amount / rates[from_curr]
        result = usd_amount * rates[to_curr]
        
    Args:
        amount: Currency amount to convert.
        from_curr: Source currency code.
        to_curr: Target currency code.
        rates: Rates dictionary where base currency rate == 1.0.
        
    Returns:
        Converted currency amount, or original amount if currency unknown or invalid.
    """
    src = from_curr.upper()
    dst = to_curr.upper()

    if src not in rates or dst not in rates:
        return amount

    from_rate = rates[src]
    to_rate = rates[dst]

    if from_rate <= 0:
        return amount

    usd_amount = amount / from_rate
    return usd_amount * to_rate
