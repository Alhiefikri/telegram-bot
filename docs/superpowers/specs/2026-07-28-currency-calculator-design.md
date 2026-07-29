# Design Specification: Waybar Real-Time Currency Calculator for Hyprland

**Date:** 2026-07-28  
**Target OS/Environment:** CachyOS (Arch Linux) / Hyprland / Waybar  

---

## 1. Overview & Objectives
The goal of this project is to build a lightweight, ultra-modern real-time currency converter and expression calculator designed specifically for CachyOS Hyprland users.

### Key Highlights:
- **Waybar Integrated Module**: Minimalist icon/ticker on Waybar that seamlessly blends with the active Waybar theme without cluttering it.
- **Smart Expression Calculator**: Supports math expressions alongside currencies (e.g., `4 * 0.25 USD`, `(50 + 20) EUR`, `100 USD + 200 EUR to IDR`).
- **Modern Libadwaita / GTK4 GUI**: Pinned floating popup window with dark mode, smooth typography, and quick currency actions.
- **Safety First & Auto-Revert System**: Automatically backs up existing Waybar configuration (`config` / `config.jsonc` and `style.css`) before applying any changes, providing an automated revert tool (`currency-calculator-revert`) to restore the original state instantly.

---

## 2. Architecture & Components

```
+-----------------------------------------------------------------------+
|                             WAYBAR BAR                                |
|  [ Workspaces ]                  [ 💵 USD 16.2k ]    [ Clock/Tray ]   |
+----------------------------------------|------------------------------+
                                         | on-click
                                         v
                         +-------------------------------+
                         |   Hyprland Floating Popup     |
                         |   Currency & Expression Calc  |
                         |   (GTK4 / Libadwaita GUI)     |
                         +---------------+---------------+
                                         |
                                         v
                         +-------------------------------+
                         |  Rates Engine & Local Cache   |
                         |  (~/.cache/currency/rates.json)|
                         +-------------------------------+
```

### Components Breakdown:
1. **GUI Application (`currency-calculator`)**:
   - Built with Python 3 + GTK4 / Libadwaita.
   - Instant launch, low resource usage (< 40MB RAM).
   - Floating window rule configured via Hyprland config (`windowrule = float, class:^(currency-calculator)$`).
2. **Smart Parser / Math Engine**:
   - Tokenizes math expressions: `+`, `-`, `*`, `/`, `( )`.
   - Recognizes currency ISO codes (`USD`, `IDR`, `EUR`, `SGD`, `JPY`, `GBP`, `AUD`, `MYR`, `CNY`, etc.).
   - Converts live evaluated expressions to target currency real-time.
3. **Rates Fetcher & Cache**:
   - Uses public REST APIs (Frankfurter API / ExchangeRate-API).
   - Updates cache asynchronously without blocking UI or Waybar.
   - Cache file: `~/.cache/currency-calculator/rates.json` (ttl: 1 hour).
4. **Waybar Integration Module**:
   - Custom module in `~/.config/waybar/config.jsonc`.
   - Styled gracefully in `~/.config/waybar/style.css` using native CSS variables to match existing theme.
5. **Safety Backup & Revert Tool (`waybar-currency-revert`)**:
   - Backup directory: `~/.config/waybar/backups/currency-calc-backup-<timestamp>/`.
   - Revert script: Restores original `config.jsonc` and `style.css` and reloads Waybar with `pkill -SIGUSR2 waybar` or `systemctl --user restart waybar`.

---

## 3. User Interface & User Experience (UI/UX)

- **Waybar Button**:
  - Default text: `💵 USD/IDR 16.2k` (or customizable compact icon `󰇅`).
  - Tooltip: Full rate details (e.g. `1 USD = 16,250.00 IDR | Last updated: 16:45`).
- **Calculator Popup Window**:
  - **Header Bar**: Search bar for currencies, Swap button (⇅), Refresh rates button.
  - **Expression Bar**: Large text field supporting live math input (e.g. `4 * 0.25 USD`).
  - **Live Result Panel**: Clean formatted output with big typography (`1.00 USD = 16.250,00 IDR`).
  - **Quick Currency Pills**: Tap-to-select badges for common currencies (IDR, USD, EUR, SGD, JPY, MYR).
  - **Action Shortcuts**: `Enter` / `Ctrl+C` to copy result to clipboard, `Esc` to close popup.

---

## 4. Safety, Testing & Verification Plan

1. **Pre-flight Backup**:
   - Validate current Waybar config file path (`~/.config/waybar/config` or `~/.config/waybar/config.jsonc`).
   - Create copy to `~/.config/waybar/backups/`.
2. **Non-Destructive Testing**:
   - Run GUI standalone to verify rendering, expression parsing, and API rate fetching.
   - Verify Hyprland window floating rule works without disrupting active windows.
3. **Waybar Integration & Revert Verification**:
   - Test `waybar-currency-revert` command to ensure 100% clean recovery of original Waybar setup.
