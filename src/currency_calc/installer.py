"""Installer and integration script for Waybar & Hyprland Currency Calculator."""

import argparse
import json
import os
import re
import subprocess
import sys
from pathlib import Path
from typing import Optional

from currency_calc.revert import backup_config_dir


def strip_jsonc_comments(text: str) -> str:
    """Strip single-line and block comments from JSONC text for standard json.loads."""
    text = re.sub(r"/\*[\s\S]*?\*/", "", text)
    lines = text.splitlines()
    clean_lines = []
    for line in lines:
        m = re.search(r'^(?:[^"\\]|\\.|"[^"\\]*")*?(\s*//.*)$', line)
        if m:
            line = line[: m.start(1)]
        clean_lines.append(line)
    return "\n".join(clean_lines)


def install_all(
    bin_dir: Optional[str] = None,
    waybar_dir: Optional[str] = None,
    hypr_dir: Optional[str] = None,
    repo_src_dir: Optional[str] = None,
) -> dict[str, str]:
    """Execute complete installation of Currency Calculator binaries, Waybar module, and Hyprland rules.

    Args:
        bin_dir: Path to user binary installation directory (default: ~/.local/bin).
        waybar_dir: Path to user Waybar configuration directory (default: ~/.config/waybar).
        hypr_dir: Path to user Hyprland configuration directory (default: ~/.config/hypr).
        repo_src_dir: Path to repository src directory.

    Returns:
        Dictionary containing paths of installed components.
    """
    bin_path = Path(os.path.expanduser(bin_dir or "~/.local/bin")).resolve()
    waybar_path = Path(os.path.expanduser(waybar_dir or "~/.config/waybar")).resolve()
    hypr_path = Path(os.path.expanduser(hypr_dir or "~/.config/hypr")).resolve()

    if repo_src_dir:
        src_path = Path(os.path.expanduser(repo_src_dir)).resolve()
    else:
        src_path = Path(__file__).resolve().parent.parent

    bin_path.mkdir(parents=True, exist_ok=True)
    waybar_path.mkdir(parents=True, exist_ok=True)
    hypr_path.mkdir(parents=True, exist_ok=True)

    # 1. Backup existing Waybar configuration
    backup_base = waybar_path / "backups"
    backup_dir = backup_config_dir(str(waybar_path), str(backup_base))

    # 2. Install currency-calculator launcher
    calc_launcher = bin_path / "currency-calculator"
    calc_launcher_content = f"""#!/usr/bin/env bash
export PYTHONPATH="{src_path}:$PYTHONPATH"
exec python3 -m currency_calc.app "$@"
"""
    calc_launcher.write_text(calc_launcher_content)
    calc_launcher.chmod(0o755)

    # 3. Install waybar-currency-module script
    module_script = bin_path / "waybar-currency-module"
    module_script_content = f"""#!/usr/bin/env bash
export PYTHONPATH="{src_path}:$PYTHONPATH"
exec python3 -m currency_calc.waybar_module "$@"
"""
    module_script.write_text(module_script_content)
    module_script.chmod(0o755)

    # 4. Install currency-calculator-revert script
    revert_script = bin_path / "currency-calculator-revert"
    revert_script_content = f"""#!/usr/bin/env bash
# Standalone revert script for Waybar Currency Calculator integration
BACKUP_DIR="${{1:-""}}"
WAYBAR_DIR="${{2:-"{waybar_path}"}}"

python3 -c "
import sys, os, glob
from pathlib import Path

backup_dir = sys.argv[1] if len(sys.argv) > 1 and sys.argv[1] else ''
waybar_dir = sys.argv[2] if len(sys.argv) > 2 and sys.argv[2] else '{waybar_path}'
backup_base = os.path.join(waybar_dir, 'backups')

if not backup_dir:
    backups = sorted(glob.glob(os.path.join(backup_base, 'waybar_backup_*')))
    if not backups:
        print('No waybar backups found in', backup_base)
        sys.exit(1)
    backup_dir = backups[-1]

from currency_calc.revert import restore_config_dir
success = restore_config_dir(backup_dir, waybar_dir)
if success:
    print(f'Restored Waybar configuration from {{backup_dir}}')
else:
    print(f'Failed to restore Waybar configuration from {{backup_dir}}')
    sys.exit(1)
" "$BACKUP_DIR" "$WAYBAR_DIR"

if pgrep -x waybar > /dev/null; then
    pkill -SIGUSR2 waybar || true
fi
"""
    revert_script.write_text(revert_script_content)
    revert_script.chmod(0o755)

    # 5. Update Waybar config.jsonc
    config_file = waybar_path / "config.jsonc"
    if not config_file.exists() and (waybar_path / "config").exists():
        config_file = waybar_path / "config"

    config_data = {}
    if config_file.exists():
        raw_text = config_file.read_text()
        clean_text = strip_jsonc_comments(raw_text).strip()
        if clean_text:
            try:
                config_data = json.loads(clean_text)
            except Exception:
                config_data = {}

    module_exec_path = str(module_script)
    calc_exec_path = str(calc_launcher)

    config_data["custom/currency"] = {
        "format": "{}",
        "interval": 3600,
        "return-type": "json",
        "exec": module_exec_path,
        "on-click": calc_exec_path,
    }

    if "modules-right" in config_data and isinstance(config_data["modules-right"], list):
        if "custom/currency" not in config_data["modules-right"]:
            config_data["modules-right"].append("custom/currency")
    else:
        config_data["modules-right"] = ["custom/currency"]

    config_file.write_text(json.dumps(config_data, indent=4) + "\n")

    # 6. Update Waybar style.css
    style_file = waybar_path / "style.css"
    css_content = style_file.read_text() if style_file.exists() else ""
    if "#custom-currency" not in css_content:
        new_css = css_content + "\n\n/* Currency Calculator Waybar Module */\n#custom-currency {\n    background-color: rgba(30, 30, 46, 0.8);\n    color: #cdd6f4;\n    padding: 0 10px;\n    margin: 0 4px;\n    border-radius: 8px;\n}\n"
        style_file.write_text(new_css.lstrip())

    # 7. Update Hyprland hyprland.conf
    hypr_conf = hypr_path / "hyprland.conf"
    hypr_text = hypr_conf.read_text() if hypr_conf.exists() else ""
    rules = [
        "windowrule = float, class:^(com.cachyos.currencycalc)$",
        "windowrule = size 420 540, class:^(com.cachyos.currencycalc)$",
        "windowrule = center, class:^(com.cachyos.currencycalc)$",
    ]
    needed_rules = [r for r in rules if r not in hypr_text]
    if needed_rules:
        new_hypr = hypr_text + "\n\n# Currency Calculator Floating Window Rules\n" + "\n".join(needed_rules) + "\n"
        hypr_conf.write_text(new_hypr.lstrip())

    # 8. Reload Waybar if active
    try:
        subprocess.run(["pkill", "-SIGUSR2", "waybar"], stderr=subprocess.DEVNULL, check=False)
    except Exception:
        pass

    return {
        "backup_dir": backup_dir,
        "calc_launcher": str(calc_launcher),
        "module_script": str(module_script),
        "revert_script": str(revert_script),
        "config_file": str(config_file),
        "style_file": str(style_file),
        "hypr_conf": str(hypr_conf),
    }


def main():
    parser = argparse.ArgumentParser(description="Install Currency Calculator Waybar & Hyprland Integration")
    parser.add_argument("--bin-dir", default=None, help="Binary installation directory")
    parser.add_argument("--waybar-dir", default=None, help="Waybar configuration directory")
    parser.add_argument("--hypr-dir", default=None, help="Hyprland configuration directory")
    parser.add_argument("--repo-src-dir", default=None, help="Repository src directory")
    args = parser.parse_args()

    res = install_all(
        bin_dir=args.bin_dir,
        waybar_dir=args.waybar_dir,
        hypr_dir=args.hypr_dir,
        repo_src_dir=args.repo_src_dir,
    )
    print("Installation successful:")
    for k, v in res.items():
        print(f"  {k}: {v}")


if __name__ == "__main__":
    main()
