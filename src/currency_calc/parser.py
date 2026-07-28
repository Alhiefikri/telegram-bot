"""Math expression parser with currency token support."""

import ast
import operator
import re
from typing import Callable, Any
from currency_calc.rates import convert_currency

SAFE_OPERATORS: dict[type[ast.AST], Callable[..., Any]] = {
    ast.Add: operator.add,
    ast.Sub: operator.sub,
    ast.Mult: operator.mul,
    ast.Div: operator.truediv,
    ast.USub: operator.neg,
    ast.UAdd: operator.pos,
}


def safe_eval_math(node: ast.AST) -> float:
    """Safely evaluate AST node containing basic arithmetic operations.
    
    Args:
        node: AST node to evaluate.
        
    Returns:
        Evaluated float value.
        
    Raises:
        ValueError: If node or operator is unsupported.
    """
    if isinstance(node, ast.Expression):
        return safe_eval_math(node.body)
    elif isinstance(node, ast.Constant) and isinstance(node.value, (int, float)):
        return float(node.value)
    elif isinstance(node, ast.BinOp):
        left = safe_eval_math(node.left)
        right = safe_eval_math(node.right)
        op_type = type(node.op)
        if op_type not in SAFE_OPERATORS:
            raise ValueError(f"Unsupported binary operator: {op_type}")
        return SAFE_OPERATORS[op_type](left, right)
    elif isinstance(node, ast.UnaryOp):
        operand = safe_eval_math(node.operand)
        op_type = type(node.op)
        if op_type not in SAFE_OPERATORS:
            raise ValueError(f"Unsupported unary operator: {op_type}")
        return SAFE_OPERATORS[op_type](operand)
    else:
        raise ValueError(f"Unsupported AST node type: {type(node)}")


def evaluate_expression(
    expr_str: str,
    target_curr: str = "IDR",
    rates: dict[str, float] | None = None
) -> tuple[float, str]:
    """Evaluate math/currency expression and convert result to target currency.
    
    Args:
        expr_str: Mathematical expression string, e.g. "4 * 0.25 USD", "(50 + 20) EUR", "10 EUR + 5 USD".
        target_curr: Target currency code (default "IDR").
        rates: Exchange rates dictionary.
        
    Returns:
        Tuple of (result_amount, target_curr).
    """
    if rates is None:
        rates = {"USD": 1.0, "IDR": 16200.0}

    target_curr = target_curr.upper()
    expr_str = expr_str.strip()
    if not expr_str:
        return 0.0, target_curr

    # Base currency detection
    base_curr = "USD"
    for code, rate in rates.items():
        if rate == 1.0:
            base_curr = code.upper()
            break

    rates_upper = {k.upper(): v for k, v in rates.items()}

    # 1. Handle embedded numbers with currency codes (e.g., "10 EUR", "5.5 USD", "100IDR")
    def replace_num_curr(match: re.Match) -> str:
        num_str = match.group(1)
        curr_code = match.group(2).upper()
        try:
            val = float(num_str)
            if curr_code in rates_upper:
                val_in_base = convert_currency(val, curr_code, base_curr, rates_upper)
                return str(val_in_base)
            return num_str
        except ValueError:
            return match.group(0)

    processed_str = re.sub(
        r'(\d+(?:\.\d+)?|\.\d+)\s*([A-Za-z]{3})\b',
        replace_num_curr,
        expr_str
    )

    # 2. Handle trailing currency suffix for whole expression, e.g. "(50 + 20) EUR"
    source_curr = base_curr
    trailing_match = re.search(r'^(.*?)\s*([A-Za-z]{3})\s*$', processed_str)
    if trailing_match:
        possible_curr = trailing_match.group(2).upper()
        if possible_curr in rates_upper:
            source_curr = possible_curr
            processed_str = trailing_match.group(1).strip()

    if not processed_str:
        return 0.0, target_curr

    # 3. Safely parse and evaluate expression AST
    try:
        tree = ast.parse(processed_str, mode='eval')
        numeric_val = safe_eval_math(tree.body)
    except Exception:
        # Fallback parsing on syntax error / invalid characters
        try:
            cleaned = re.sub(r'[^0-9.\-+*/()]', '', processed_str)
            if cleaned:
                tree = ast.parse(cleaned, mode='eval')
                numeric_val = safe_eval_math(tree.body)
            else:
                numeric_val = 0.0
        except Exception:
            numeric_val = 0.0

    # 4. Convert evaluated numeric value from source currency to target currency
    final_amount = convert_currency(numeric_val, source_curr, target_curr, rates_upper)
    return final_amount, target_curr


def evaluate_full_details(
    expr_str: str,
    target_curr: str = "IDR",
    rates: dict[str, float] | None = None
) -> dict[str, Any]:
    """Evaluate math/currency expression and return detailed breakdown for UI displays.
    
    Returns dict containing:
        - raw_val: Raw numeric math result before conversion.
        - has_explicit_currency: True if currency symbol/code was in expression.
        - source_curr: Source currency code or "USD".
        - converted_val: Converted value in target currency.
        - target_curr: Target currency code.
    """
    if rates is None:
        rates = {"USD": 1.0, "IDR": 16200.0}

    expr_str = expr_str.strip()
    has_explicit_currency = False
    rates_upper = {k.upper(): v for k, v in rates.items()}

    for code in rates_upper:
        if re.search(r'\b' + re.escape(code) + r'\b', expr_str, re.IGNORECASE):
            has_explicit_currency = True
            break

    # First calculate raw math expression without conversion
    clean_math_str = re.sub(r'[A-Za-z]{3}', '', expr_str).strip()
    try:
        if clean_math_str:
            tree = ast.parse(clean_math_str, mode='eval')
            raw_val = safe_eval_math(tree.body)
        else:
            raw_val = 0.0
    except Exception:
        try:
            cleaned = re.sub(r'[^0-9.\-+*/()]', '', clean_math_str)
            if cleaned:
                tree = ast.parse(cleaned, mode='eval')
                raw_val = safe_eval_math(tree.body)
            else:
                raw_val = 0.0
        except Exception:
            raw_val = 0.0

    converted_val, final_target = evaluate_expression(expr_str, target_curr, rates)

    return {
        "raw_val": raw_val,
        "has_explicit_currency": has_explicit_currency,
        "source_curr": target_curr if not has_explicit_currency else target_curr,
        "converted_val": converted_val,
        "target_curr": final_target,
    }

