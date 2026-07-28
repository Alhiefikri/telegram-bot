# Task 2 Execution Report: Math Expression & Currency Parser

**Status:** Completed
**Timestamp:** 2026-07-28T16:53:00+08:00

## Summary of Changes
1. **Created `src/currency_calc/parser.py`**:
   - `safe_eval_math(node: ast.AST) -> float`: Evaluates math expressions using `ast.parse` in `eval` mode with a strict whitelist of safe binary (`+`, `-`, `*`, `/`) and unary (`+`, `-`) operators. Rejects code execution or unsupported nodes without using raw `eval()`.
   - `evaluate_expression(expr_str: str, target_curr: str = "IDR", rates: dict | None = None) -> tuple[float, str]`:
     - Parses math expressions containing embedded currency codes attached to numbers (e.g. `10 EUR + 5 USD`).
     - Parses trailing currency suffix attached to whole expressions (e.g. `4 * 0.25 USD`, `(50 + 20) EUR`, `100 USD`).
     - Handles invalid/empty inputs and division-by-zero gracefully returning `(0.0, target_curr)`.
     - Converts final evaluated numeric amounts to `target_curr` using `convert_currency` from `currency_calc.rates`.

2. **Created `tests/test_parser.py`**:
   - Test cases covering simple math, complex nested expressions, single/multiple currency tokens, empty strings, invalid syntax, division by zero, AST security code-injection prevention, and direct `safe_eval_math` evaluation.

## Verification
- Ran TDD red step: `PYTHONPATH=src pytest tests/test_parser.py -v` failed prior to implementation (`ModuleNotFoundError`).
- Ran TDD green step: `PYTHONPATH=src pytest tests/test_parser.py -v` passed all 10 unit tests.
- Ran full test suite: `PYTHONPATH=src pytest tests/ -v` passed all 16 unit tests across `test_rates.py` and `test_parser.py`.

```
============================== 16 passed in 0.19s ==============================
```
