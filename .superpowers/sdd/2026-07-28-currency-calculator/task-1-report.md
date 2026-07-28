# Task 1 Execution Report: Rates Fetcher & Cache Engine

## Summary
Task 1 for the Currency Calculator project has been implemented and fully verified using TDD.

## Deliverables Created
1. `src/currency_calc/__init__.py`: Package initialization file exporting `fetch_latest_rates` and `convert_currency`.
2. `src/currency_calc/rates.py`: Core module containing `fetch_latest_rates()` (API fetching with `urllib.request`, 5s timeout, custom User-Agent, local JSON caching at `~/.cache/currency-calculator/rates.json`, and offline fallback dictionary) and `convert_currency()` (cross-rate calculation with case-insensitivity and fallback for unknown/invalid currencies).
3. `tests/test_rates.py`: Test suite verifying:
   - `test_convert_currency_standard`: Standard currency conversion (e.g. USD to IDR, IDR to USD, cross conversion EUR to IDR, case insensitivity).
   - `test_convert_currency_unknown_currency`: Fallback when source or target currency is not in rates.
   - `test_convert_currency_invalid_rate`: Safety fallback when exchange rate is zero or invalid.
   - `test_fetch_latest_rates_success`: Successful API fetch, proper HTTP request header/timeout, base currency inclusion (`rates[base] == 1.0`), and cache file writing.
   - `test_fetch_latest_rates_api_failure_with_cache`: Fallback to local JSON cache upon API failure.
   - `test_fetch_latest_rates_api_failure_no_cache`: Fallback to default dictionary when API fails and cache is missing.

## Verification Results
- Executed command: `PYTHONPATH=src pytest tests/test_rates.py -v`
- Result: 6 passed in 0.19s
- Status: All tests green.
