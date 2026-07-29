#!/usr/bin/env bash
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
export PYTHONPATH="$SCRIPT_DIR/src:$PYTHONPATH"

echo "Installing Currency Calculator, Waybar module, and Hyprland rules..."
python3 -m currency_calc.installer "$@"
