"""Unit and smoke tests for GTK4 Libadwaita application window."""

import gi
gi.require_version("Gtk", "4.0")
gi.require_version("Adw", "1")
from gi.repository import Gtk, Adw, Gdk
from unittest.mock import patch

from currency_calc.app import CurrencyCalcWindow, main

Adw.init()

MOCK_RATES = {
    "USD": 1.0,
    "IDR": 16200.0,
    "EUR": 0.92,
    "SGD": 1.35,
    "JPY": 155.0,
    "MYR": 4.7,
}


def test_window_initialization():
    win = CurrencyCalcWindow(rates=MOCK_RATES)
    assert win.target_curr == "IDR"
    assert win.result_label.get_text() == "0"
    for code in ["IDR", "USD", "EUR", "SGD", "JPY", "MYR"]:
        assert code in win.badges
        assert isinstance(win.badges[code], Gtk.Button)


def test_live_expression_evaluation():
    win = CurrencyCalcWindow(rates=MOCK_RATES)
    win.entry.set_text("4 * 0.25 USD")
    assert win.result_label.get_text() == "16,200.00 IDR"

    win.entry.set_text("100 USD")
    assert win.result_label.get_text() == "1,620,000.00 IDR"

    # Test pure math calculation
    win.entry.set_text("50 + 20")
    assert win.result_label.get_text() == "70"


def test_quick_currency_badge_selection():
    win = CurrencyCalcWindow(rates=MOCK_RATES)
    win.entry.set_text("4 * 0.25 USD")

    # Switch to USD badge
    win.badges["USD"].emit("clicked")
    assert win.target_curr == "USD"
    assert win.result_label.get_text() == "1.00 USD"

    # Switch to EUR badge
    win.badges["EUR"].emit("clicked")
    assert win.target_curr == "EUR"
    assert win.result_label.get_text() == "0.92 EUR"

    # Switch back to IDR badge
    win.badges["IDR"].emit("clicked")
    assert win.target_curr == "IDR"
    assert win.result_label.get_text() == "16,200.00 IDR"


def test_keypad_clicks():
    win = CurrencyCalcWindow(rates=MOCK_RATES)
    win.on_keypad_clicked("5")
    win.on_keypad_clicked("+")
    win.on_keypad_clicked("5")
    assert win.entry.get_text() == "5 + 5"
    assert win.result_label.get_text() == "10"
    win.on_keypad_clicked("C")
    assert win.entry.get_text() == ""


def test_copy_to_clipboard():
    win = CurrencyCalcWindow(rates=MOCK_RATES)
    win.entry.set_text("10 USD")
    text = win.copy_to_clipboard()
    assert text == "162,000.00 IDR"


def test_keyboard_shortcuts():
    win = CurrencyCalcWindow(rates=MOCK_RATES)
    win.entry.set_text("5 USD")

    # Enter key triggers copy
    handled_enter = win.on_key_pressed(None, Gdk.KEY_Return, 0, Gdk.ModifierType(0))
    assert handled_enter is True

    # KP_Enter key triggers copy
    handled_kp_enter = win.on_key_pressed(None, Gdk.KEY_KP_Enter, 0, Gdk.ModifierType(0))
    assert handled_kp_enter is True

    # Escape key closes window
    handled_esc = win.on_key_pressed(None, Gdk.KEY_Escape, 0, Gdk.ModifierType(0))
    assert handled_esc is True

    # Unhandled key
    handled_other = win.on_key_pressed(None, Gdk.KEY_a, 0, Gdk.ModifierType(0))
    assert handled_other is False


def test_app_id_and_main_smoke():
    with patch("gi.repository.Adw.Application.run") as mock_run:
        mock_run.return_value = 0
        with patch("sys.argv", ["currency-calc"]):
            main()
        assert mock_run.called
