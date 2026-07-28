"""Currency Calculator package."""

from .rates import fetch_latest_rates, convert_currency

__all__ = ["fetch_latest_rates", "convert_currency"]
