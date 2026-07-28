"""GTK4 / Libadwaita modern currency calculator GUI application window."""

import sys
import gi

gi.require_version("Gtk", "4.0")
gi.require_version("Adw", "1")
from gi.repository import Gtk, Adw, Gdk

from currency_calc.rates import fetch_latest_rates
from currency_calc.parser import evaluate_expression


class CurrencyCalcWindow(Adw.ApplicationWindow):
    """Main application window for Currency & Standard Calculator."""

    SUPPORTED_CURRENCIES: list[str] = ["IDR", "USD", "EUR", "SGD", "JPY", "MYR"]

    def __init__(self, application: Adw.Application | None = None, rates: dict[str, float] | None = None):
        """Initialize GTK4 Libadwaita calculator window.
        
        Args:
            application: Parent Adw.Application instance (optional).
            rates: Exchange rates dictionary (optional, fetched if None).
        """
        super().__init__(application=application, title="Kalkulator & Currency")
        self.set_default_size(440, 580)

        self.rates = rates if rates is not None else fetch_latest_rates(base="USD")
        self.target_curr = "IDR"
        self.badges: dict[str, Gtk.Button] = {}

        # Toast overlay for floating notification toasts
        self.toast_overlay = Adw.ToastOverlay()
        self.set_content(self.toast_overlay)

        # Container box
        main_box = Gtk.Box(orientation=Gtk.Orientation.VERTICAL, spacing=12)
        main_box.set_margin_top(14)
        main_box.set_margin_bottom(14)
        main_box.set_margin_start(14)
        main_box.set_margin_end(14)
        self.toast_overlay.set_child(main_box)

        # Header bar / Header box
        header = Gtk.Box(orientation=Gtk.Orientation.HORIZONTAL, spacing=8)
        title_label = Gtk.Label(label="Kalkulator & Currency")
        title_label.add_css_class("title-3")
        header.append(title_label)
        main_box.append(header)

        # Expression Entry
        self.entry = Gtk.Entry()
        self.entry.set_placeholder_text("Ketik angka/matematika e.g. 50 + 20 atau 4 * 0.25 USD")
        self.entry.connect("changed", self.on_input_changed)
        main_box.append(self.entry)

        # Result Display & Sub-result
        result_box = Gtk.Box(orientation=Gtk.Orientation.HORIZONTAL, spacing=12)
        
        text_box = Gtk.Box(orientation=Gtk.Orientation.VERTICAL, spacing=2)
        text_box.set_hexpand(True)

        self.result_label = Gtk.Label(label="0")
        self.result_label.add_css_class("title-1")
        self.result_label.set_halign(Gtk.Align.START)
        text_box.append(self.result_label)

        self.sub_result_label = Gtk.Label(label="≈ 0.00 IDR")
        self.sub_result_label.add_css_class("dim-label")
        self.sub_result_label.set_halign(Gtk.Align.START)
        text_box.append(self.sub_result_label)

        result_box.append(text_box)

        self.copy_btn = Gtk.Button(label="Copy")
        self.copy_btn.add_css_class("pill")
        self.copy_btn.set_tooltip_text("Copy result to clipboard (Enter)")
        self.copy_btn.connect("clicked", lambda _btn: self.copy_to_clipboard())
        result_box.append(self.copy_btn)

        main_box.append(result_box)

        # Quick Currency Badges / Pills
        badge_box = Gtk.Box(orientation=Gtk.Orientation.HORIZONTAL, spacing=6)
        badge_box.set_homogeneous(True)
        for curr in self.SUPPORTED_CURRENCIES:
            btn = Gtk.Button(label=curr)
            btn.connect("clicked", self._make_badge_callback(curr))
            if curr == self.target_curr:
                btn.add_css_class("suggested-action")
            badge_box.append(btn)
            self.badges[curr] = btn
        main_box.append(badge_box)

        # Standard Calculator Keypad Grid
        keypad = Gtk.Grid()
        keypad.set_row_spacing(6)
        keypad.set_column_spacing(6)
        keypad.set_row_homogeneous(True)
        keypad.set_column_homogeneous(True)

        keys = [
            ["C", "(", ")", "/"],
            ["7", "8", "9", "*"],
            ["4", "5", "6", "-"],
            ["1", "2", "3", "+"],
            ["0", ".", "⌫", "="]
        ]
        for row_idx, row in enumerate(keys):
            for col_idx, char in enumerate(row):
                btn = Gtk.Button(label=char)
                if char in ("/", "*", "-", "+", "="):
                    btn.add_css_class("accent")
                elif char in ("C", "⌫"):
                    btn.add_css_class("destructive-action")
                btn.connect("clicked", self._make_keypad_callback(char))
                keypad.attach(btn, col_idx, row_idx, 1, 1)

        main_box.append(keypad)

        # Event controller for keyboard shortcuts
        key_controller = Gtk.EventControllerKey()
        key_controller.connect("key-pressed", self.on_key_pressed)
        self.add_controller(key_controller)

    def _make_badge_callback(self, curr: str):
        """Helper factory for currency badge click callbacks."""
        return lambda _btn: self.set_target_currency(curr)

    def _make_keypad_callback(self, char: str):
        """Helper factory for calculator keypad button clicks."""
        return lambda _btn: self.on_keypad_clicked(char)

    def on_keypad_clicked(self, char: str) -> None:
        """Handle standard calculator keypad button presses."""
        text = self.entry.get_text()
        if char == "C":
            self.entry.set_text("")
        elif char == "⌫":
            if text:
                self.entry.set_text(text[:-1])
        elif char == "=":
            self.copy_to_clipboard()
        else:
            # Space out binary operators for readability
            if char in ("+", "-", "*", "/"):
                self.entry.set_text(text + f" {char} ")
            else:
                self.entry.set_text(text + char)
        self.entry.set_position(-1)

    def set_target_currency(self, curr: str) -> None:
        """Set active target currency code and refresh evaluation & styles.
        
        Args:
            curr: Currency code (e.g. "USD", "EUR", "IDR").
        """
        self.target_curr = curr.upper()
        for c, btn in self.badges.items():
            if c == self.target_curr:
                btn.add_css_class("suggested-action")
            else:
                btn.remove_css_class("suggested-action")
        self.on_input_changed(self.entry)

    def on_input_changed(self, entry: Gtk.Entry) -> None:
        """Handle live input evaluation on expression entry changes."""
        text = entry.get_text()
        from currency_calc.parser import evaluate_full_details
        details = evaluate_full_details(text, self.target_curr, self.rates)
        
        if details["has_explicit_currency"]:
            self.result_label.set_text(f"{details['converted_val']:,.2f} {details['target_curr']}")
            self.sub_result_label.set_text(f"Raw Math: {details['raw_val']:,.2f}")
        else:
            raw = details["raw_val"]
            if raw == int(raw):
                self.result_label.set_text(f"{int(raw):,}")
            else:
                self.result_label.set_text(f"{raw:,.4f}".rstrip('0').rstrip('.'))
            self.sub_result_label.set_text(f"≈ {details['converted_val']:,.2f} {details['target_curr']}")

    def copy_to_clipboard(self) -> str:
        """Copy current result label text to system clipboard and show toast.
        
        Returns:
            The copied result text string.
        """
        text = self.result_label.get_text()
        display = Gdk.Display.get_default()
        if display:
            clipboard = display.get_clipboard()
            clipboard.set(text)
        toast = Adw.Toast.new(f"Copied: {text}")
        self.toast_overlay.add_toast(toast)
        return text

    def on_key_pressed(
        self,
        controller: Gtk.EventControllerKey,
        keyval: int,
        keycode: int,
        state: Gdk.ModifierType
    ) -> bool:
        """Handle key press shortcuts: Enter for copy, Escape to close window."""
        if keyval in (Gdk.KEY_Return, Gdk.KEY_KP_Enter):
            self.copy_to_clipboard()
            return True
        elif keyval == Gdk.KEY_Escape:
            self.close()
            return True
        return False


def main() -> None:
    """Application entrypoint for launching GTK4 Libadwaita application."""
    Adw.init()
    style_manager = Adw.StyleManager.get_default()
    style_manager.set_color_scheme(Adw.ColorScheme.PREFER_DARK)

    app = Adw.Application(application_id="com.cachyos.currencycalc")

    def on_activate(application: Adw.Application) -> None:
        win = CurrencyCalcWindow(application=application)
        win.present()

    app.connect("activate", on_activate)
    app.run(sys.argv)


if __name__ == "__main__":
    main()
