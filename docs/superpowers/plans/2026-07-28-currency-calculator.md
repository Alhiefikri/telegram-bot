# Currency Calculator & Waybar Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a lightweight, modern GTK4/Libadwaita real-time currency & math expression calculator with Waybar integration and an automated Waybar configuration backup/revert system.

**Architecture:** A modular Python 3 package with decoupled rates fetching, math+currency expression parser, GTK4 Libadwaita GUI popup, Waybar JSON status script, and safe Waybar backup/restore scripts.

**Tech Stack:** Python 3, PyGObject (GTK4 / Libadwaita), `pytest` for unit testing, REST API (Frankfurter API).

## Global Constraints
- Target environment: CachyOS (Arch Linux), Hyprland, Waybar.
- Waybar safety: Back up `~/.config/waybar/config*` and `~/.config/waybar/style.css` before any edits.
- Memory & startup: Cold startup < 0.5s, RAM < 40MB.
- Expression support: Math operators (`+`, `-`, `*`, `/`, `(`, `)`) with currency codes (`USD`, `IDR`, `EUR`, `SGD`, `JPY`, etc.).

---

### Task 1: Rates Fetcher & Cache Engine

**Files:**
- Create: `src/currency_calc/rates.py`
- Test: `tests/test_rates.py`

**Interfaces:**
- Produces: `get_rates(base="USD") -> dict[str, float]`, `convert_currency(amount: float, from_curr: str, to_curr: str, rates: dict) -> float`

- [ ] **Step 1: Write failing tests for rates fetcher and conversion math**

```python
import pytest
from currency_calc.rates import convert_currency, parse_cache

def test_convert_currency_direct():
    rates = {"USD": 1.0, "IDR": 16000.0, "EUR": 0.9}
    # 100 USD to IDR -> 1,600,000 IDR
    assert convert_currency(100.0, "USD", "IDR", rates) == 1600000.0
    # 16000 IDR to USD -> 1 USD
    assert convert_currency(16000.0, "IDR", "USD", rates) == 1.0
    # 10 EUR to IDR -> (10 / 0.9) * 16000
    assert abs(convert_currency(10.0, "EUR", "IDR", rates) - 177777.777) < 0.1
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pytest tests/test_rates.py -v`  
Expected: FAIL with ModuleNotFoundError or function missing.

- [ ] **Step 3: Implement rates module with local JSON caching**

```python
import json
import os
import time
import urllib.request

CACHE_FILE = os.path.expanduser("~/.cache/currency-calculator/rates.json")
CACHE_TTL = 3600  # 1 hour

def fetch_latest_rates(base="USD") -> dict:
    url = f"https://api.frankfurter.app/latest?from={base}"
    try:
        req = urllib.request.Request(url, headers={"User-Agent": "CurrencyCalc/1.0"})
        with urllib.request.urlopen(req, timeout=5) as resp:
            data = json.loads(resp.read().decode())
            rates = data.get("rates", {})
            rates[base] = 1.0
            save_cache({"timestamp": time.time(), "base": base, "rates": rates})
            return rates
    except Exception:
        cached = load_cache()
        if cached:
            return cached.get("rates", {"USD": 1.0, "IDR": 16200.0})
        return {"USD": 1.0, "IDR": 16200.0, "EUR": 0.92, "SGD": 1.35, "JPY": 155.0}

def save_cache(data: dict):
    os.makedirs(os.path.dirname(CACHE_FILE), exist_ok=True)
    with open(CACHE_FILE, "w") as f:
        json.dump(data, f)

def load_cache() -> dict:
    if os.path.exists(CACHE_FILE):
        try:
            with open(CACHE_FILE, "r") as f:
                return json.load(f)
        except Exception:
            return {}
    return {}

def convert_currency(amount: float, from_curr: str, to_curr: str, rates: dict) -> float:
    from_curr = from_curr.upper()
    to_curr = to_curr.upper()
    if from_curr not in rates or to_curr not in rates:
        return amount
    usd_amount = amount / rates[from_curr]
    return usd_amount * rates[to_curr]
```

- [ ] **Step 4: Run test to verify it passes**

Run: `pytest tests/test_rates.py -v`  
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/currency_calc/rates.py tests/test_rates.py
git commit -m "feat: implement rates fetcher with local cache and conversion helper"
```

---

### Task 2: Expression & Currency Math Parser

**Files:**
- Create: `src/currency_calc/parser.py`
- Test: `tests/test_parser.py`

**Interfaces:**
- Consumes: `convert_currency` from `rates.py`
- Produces: `evaluate_expression(expr: str, target_curr: str, rates: dict) -> tuple[float, str]`

- [ ] **Step 1: Write failing tests for expression parsing**

```python
from currency_calc.parser import evaluate_expression

rates = {"USD": 1.0, "IDR": 16000.0, "EUR": 0.8}

def test_simple_eval():
    val, curr = evaluate_expression("4 * 0.25 USD", "IDR", rates)
    assert val == 16000.0
    assert curr == "IDR"

def test_complex_eval():
    val, curr = evaluate_expression("(50 + 50) EUR", "IDR", rates)
    # 100 EUR = 100 / 0.8 * 16000 = 2,000,000 IDR
    assert val == 2000000.0
    assert curr == "IDR"
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pytest tests/test_parser.py -v`  
Expected: FAIL

- [ ] **Step 3: Implement math expression parser**

```python
import re
import ast
import operator
from currency_calc.rates import convert_currency

SAFE_OPERATORS = {
    ast.Add: operator.add,
    ast.Sub: operator.sub,
    ast.Mult: operator.mul,
    ast.Div: operator.truediv,
    ast.USub: operator.neg,
    ast.UAdd: operator.pos,
}

def safe_eval_math(node):
    if isinstance(node, ast.Constant) and isinstance(node.value, (int, float)):
        return float(node.value)
    elif isinstance(node, ast.BinOp):
        left = safe_eval_math(node.left)
        right = safe_eval_math(node.right)
        return SAFE_OPERATORS[type(node.op)](left, right)
    elif isinstance(node, ast.UnaryOp):
        operand = safe_eval_math(node.operand)
        return SAFE_OPERATORS[type(node.op)](operand)
    raise ValueError("Unsupported math expression")

def evaluate_expression(expr_str: str, target_curr: str, rates: dict) -> tuple[float, str]:
    expr_str = expr_str.strip()
    if not expr_str:
        return 0.0, target_curr
    
    # Extract explicit source currency if provided (e.g., '4 * 0.25 USD')
    match = re.search(r'([A-Za-z]{3})\s*$', expr_str)
    source_curr = "USD"
    if match:
        possible_curr = match.group(1).upper()
        if possible_curr in rates:
            source_curr = possible_curr
            expr_str = expr_str[:match.start()].strip()

    try:
        tree = ast.parse(expr_str, mode='eval')
        numeric_val = safe_eval_math(tree.body)
    except Exception:
        # Fallback to float parsing
        try:
            numeric_val = float(re.sub(r'[^0-9.]', '', expr_str))
        except Exception:
            numeric_val = 0.0

    converted = convert_currency(numeric_val, source_curr, target_curr, rates)
    return converted, target_curr
```

- [ ] **Step 4: Run test to verify it passes**

Run: `pytest tests/test_parser.py -v`  
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/currency_calc/parser.py tests/test_parser.py
git commit -m "feat: implement math expression parser with currency token support"
```

---

### Task 3: Waybar Backup & Auto-Revert Utility

**Files:**
- Create: `src/currency_calc/revert.py`
- Test: `tests/test_revert.py`

**Interfaces:**
- Produces: `backup_waybar_config() -> str`, `restore_waybar_config(backup_dir: str) -> bool`

- [ ] **Step 1: Write unit test for backup and restore**

```python
import os
import shutil
import tempfile
from currency_calc.revert import backup_config_dir, restore_config_dir

def test_backup_restore():
    with tempfile.TemporaryDirectory() as tmpdir:
        config_dir = os.path.join(tmpdir, "waybar")
        os.makedirs(config_dir)
        config_file = os.path.join(config_dir, "config.jsonc")
        with open(config_file, "w") as f:
            f.write('{"test": true}')
            
        backup_path = backup_config_dir(config_dir, os.path.join(tmpdir, "backups"))
        assert os.path.exists(backup_path)
        
        # Modify original
        with open(config_file, "w") as f:
            f.write('{"modified": true}')
            
        # Restore
        success = restore_config_dir(backup_path, config_dir)
        assert success
        with open(config_file, "r") as f:
            content = f.read()
        assert '{"test": true}' in content
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pytest tests/test_revert.py -v`  
Expected: FAIL

- [ ] **Step 3: Implement backup and restore logic**

```python
import os
import shutil
import time

def backup_config_dir(source_dir: str, backup_base_dir: str) -> str:
    timestamp = time.strftime("%Y%m%d_%H%M%S")
    dest_dir = os.path.join(backup_base_dir, f"waybar_backup_{timestamp}")
    os.makedirs(dest_dir, exist_ok=True)
    for filename in ["config", "config.jsonc", "style.css"]:
        src_file = os.path.join(source_dir, filename)
        if os.path.exists(src_file):
            shutil.copy2(src_file, dest_dir)
    return dest_dir

def restore_config_dir(backup_dir: str, target_dir: str) -> bool:
    if not os.path.exists(backup_dir):
        return False
    for filename in os.listdir(backup_dir):
        src_file = os.path.join(backup_dir, filename)
        dest_file = os.path.join(target_dir, filename)
        if os.path.isfile(src_file):
            shutil.copy2(src_file, dest_file)
    return True
```

- [ ] **Step 4: Run test to verify it passes**

Run: `pytest tests/test_revert.py -v`  
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/currency_calc/revert.py tests/test_revert.py
git commit -m "feat: add Waybar backup and restore module for revert capability"
```

---

### Task 4: Waybar Custom Module Integration Script

**Files:**
- Create: `src/currency_calc/waybar_module.py`

**Interfaces:**
- Produces: JSON payload for Waybar: `{"text": "...", "tooltip": "...", "class": "..."}`

- [ ] **Step 1: Implement Waybar module script**

```python
import json
import sys
from currency_calc.rates import fetch_latest_rates

def main():
    rates = fetch_latest_rates(base="USD")
    idr_rate = rates.get("IDR", 16200.0)
    rate_k = f"{idr_rate / 1000:.1f}k"
    
    payload = {
        "text": f"💵 USD/IDR {rate_k}",
        "tooltip": f"1 USD = {idr_rate:,.2f} IDR\nClick to open Currency Calculator",
        "class": "currency-calc"
    }
    print(json.dumps(payload))

if __name__ == "__main__":
    main()
```

- [ ] **Step 2: Test script output in CLI**

Run: `python3 src/currency_calc/waybar_module.py`  
Expected output: `{"text": "💵 USD/IDR 16.2k", "tooltip": "...", "class": "currency-calc"}`

- [ ] **Step 3: Commit**

```bash
git add src/currency_calc/waybar_module.py
git commit -m "feat: implement Waybar custom module JSON printer"
```

---

### Task 5: GTK4/Libadwaita Modern GUI App

**Files:**
- Create: `src/currency_calc/app.py`

**Interfaces:**
- Executable GUI application launching GTK4 floating popup window.

- [ ] **Step 1: Implement GTK4 / Libadwaita window UI**

```python
import sys
import gi
gi.require_version("Gtk", "4.0")
gi.require_version("Adw", "1")
from gi.repository import Gtk, Adw, Gdk
from currency_calc.rates import fetch_latest_rates
from currency_calc.parser import evaluate_expression

class CurrencyCalcWindow(Adw.ApplicationWindow):
    def __init__(self, app):
        super().__init__(application=app, title="Currency Calculator")
        self.set_default_size(420, 520)
        self.rates = fetch_latest_rates(base="USD")

        # Main box
        box = Gtk.Box(orientation=Gtk.Orientation.VERTICAL, spacing=16)
        box.set_margin_top(16)
        box.set_margin_bottom(16)
        box.set_margin_start(16)
        box.set_margin_end(16)
        self.set_content(box)

        # Title / Header
        title = Gtk.Label(label="Currency Calculator")
        title.add_css_class("title-1")
        box.append(title)

        # Expression Entry
        self.entry = Gtk.Entry()
        self.entry.set_placeholder_text("Type math or currency e.g. 4 * 0.25 USD")
        self.entry.connect("changed", self.on_input_changed)
        box.append(self.entry)

        # Result Display
        self.result_label = Gtk.Label(label="0.00 IDR")
        self.result_label.add_css_class("title-2")
        box.append(self.result_label)

    def on_input_changed(self, entry):
        text = entry.get_text()
        val, curr = evaluate_expression(text, "IDR", self.rates)
        self.result_label.set_text(f"{val:,.2f} {curr}")

def main():
    app = Adw.Application(application_id="com.cachyos.currencycalc")
    def on_activate(app):
        win = CurrencyCalcWindow(app)
        win.present()
    app.connect("activate", on_activate)
    app.run(sys.argv)

if __name__ == "__main__":
    main()
```

- [ ] **Step 2: Commit**

```bash
git add src/currency_calc/app.py
git commit -m "feat: implement GTK4 Libadwaita popup application window"
```

---

### Task 6: Hyprland Window Rules & Integration Setup

**Files:**
- Modify/Install scripts into `~/.local/bin/`

- [ ] **Step 1: Create installation script that configures Waybar & Hyprland**
- [ ] **Step 2: Verify Waybar reload and revert functionality**
- [ ] **Step 3: Commit final integration scripts**

```bash
git add .
git commit -m "feat: complete Waybar currency calculator integration with revert safety"
```
