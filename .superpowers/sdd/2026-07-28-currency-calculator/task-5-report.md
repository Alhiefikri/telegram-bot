# Task 5 Execution Report: GTK4 / Libadwaita Modern GUI App

**Status:** Completed  
**Date:** 2026-07-28  

## Overview
Implemented `src/currency_calc/app.py`, providing a modern GTK4 / Libadwaita dark mode application window (`com.cachyos.currencycalc`) with live expression evaluation, target currency selection badges, clipboard copy capabilities (via button click or `Enter` shortcut), floating toast feedback, and window close on `Esc`. Added comprehensive unit and smoke test coverage in `tests/test_app.py`.

## Files Created / Modified
- `src/currency_calc/app.py`: Created GTK4 / Libadwaita GUI application window `CurrencyCalcWindow` and `main()` entrypoint.
- `tests/test_app.py`: Created unit and smoke tests covering window creation, live math/currency expression evaluation, quick badge switching, clipboard copy operations, keyboard shortcuts, and `Adw.Application` main entrypoint.

## Interface Specifications
- `CurrencyCalcWindow(application: Adw.Application | None = None, rates: dict[str, float] | None = None)`:
  - Consumes exchange rates (or fetches via `fetch_latest_rates()`).
  - Supports live evaluation using `evaluate_expression` from `currency_calc.parser`.
  - Application ID: `com.cachyos.currencycalc` with Libadwaita dark mode preferred color scheme.
  - Quick badges: `IDR`, `USD`, `EUR`, `SGD`, `JPY`, `MYR`.
  - Keyboard shortcuts: `Enter` / `KP_Enter` copies formatted result to clipboard with `Adw.Toast` feedback; `Esc` closes window.
- `main() -> None`:
  - Entrypoint initializing `Adw.Application(application_id="com.cachyos.currencycalc")` and presenting `CurrencyCalcWindow`.

## Test Verification
- Test commands run:
  - `PYTHONPATH=src pytest tests/test_app.py -v`: 6 passed
  - `PYTHONPATH=src pytest -v`: 30 passed (full test suite across all 5 tasks)
