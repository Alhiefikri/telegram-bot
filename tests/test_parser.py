"""Unit tests for math expression and currency parser."""

import pytest
from currency_calc.parser import evaluate_expression, safe_eval_math
import ast


RATES = {
    "USD": 1.0,
    "IDR": 16000.0,
    "EUR": 0.8,
    "SGD": 1.35,
    "JPY": 150.0,
}


def test_simple_eval():
    val, curr = evaluate_expression("4 * 0.25 USD", "IDR", RATES)
    assert val == 16000.0
    assert curr == "IDR"


def test_complex_eval():
    val, curr = evaluate_expression("(50 + 50) EUR", "IDR", RATES)
    # 100 EUR = 100 / 0.8 * 16000 = 2,000,000 IDR
    assert val == 2000000.0
    assert curr == "IDR"


def test_single_currency_amount():
    val, curr = evaluate_expression("100 USD", "IDR", RATES)
    assert val == 1600000.0
    assert curr == "IDR"


def test_multiple_embedded_currencies():
    val, curr = evaluate_expression("10 EUR + 5 USD", "IDR", RATES)
    # 10 EUR = 12.5 USD; + 5 USD = 17.5 USD
    # 17.5 USD * 16000 = 280,000 IDR
    assert val == 280000.0
    assert curr == "IDR"


def test_pure_math_without_currency():
    val, curr = evaluate_expression("10 + 5 * 2", "USD", RATES)
    assert val == 20.0
    assert curr == "USD"


def test_empty_and_whitespace_input():
    val, curr = evaluate_expression("", "IDR", RATES)
    assert val == 0.0
    assert curr == "IDR"

    val, curr = evaluate_expression("   ", "EUR", RATES)
    assert val == 0.0
    assert curr == "EUR"


def test_invalid_syntax_fallback():
    val, curr = evaluate_expression("invalid text", "IDR", RATES)
    assert val == 0.0
    assert curr == "IDR"

    val, curr = evaluate_expression("4 * +", "IDR", RATES)
    assert val == 0.0
    assert curr == "IDR"


def test_division_by_zero():
    val, curr = evaluate_expression("10 / 0", "IDR", RATES)
    assert val == 0.0
    assert curr == "IDR"


def test_ast_security_rejection():
    # Attempt code execution string
    val, curr = evaluate_expression("__import__('os').system('ls')", "IDR", RATES)
    assert val == 0.0
    assert curr == "IDR"


def test_safe_eval_math_direct():
    tree = ast.parse("10 + (2 * 5)", mode="eval")
    result = safe_eval_math(tree.body)
    assert result == 20.0
