import json
import os
import subprocess
import sys
from pathlib import Path
import pytest

from currency_calc.installer import install_all


def test_backup_created_during_installer(tmp_path):
    bin_dir = tmp_path / "bin"
    waybar_dir = tmp_path / "waybar"
    hypr_dir = tmp_path / "hypr"

    bin_dir.mkdir(parents=True)
    waybar_dir.mkdir(parents=True)
    hypr_dir.mkdir(parents=True)

    config_file = waybar_dir / "config.jsonc"
    style_file = waybar_dir / "style.css"
    config_file.write_text('{"modules-right": ["clock"]}')
    style_file.write_text("/* original style */\n#clock { color: #fff; }")

    res = install_all(
        bin_dir=str(bin_dir),
        waybar_dir=str(waybar_dir),
        hypr_dir=str(hypr_dir),
        repo_src_dir=str(Path(__file__).resolve().parent.parent / "src")
    )

    backup_dir = Path(res["backup_dir"])
    assert backup_dir.exists()
    assert (backup_dir / "config.jsonc").read_text() == '{"modules-right": ["clock"]}'
    assert (backup_dir / "style.css").read_text() == "/* original style */\n#clock { color: #fff; }"


def test_launcher_scripts_installed_and_executable(tmp_path):
    bin_dir = tmp_path / "bin"
    waybar_dir = tmp_path / "waybar"
    hypr_dir = tmp_path / "hypr"

    res = install_all(
        bin_dir=str(bin_dir),
        waybar_dir=str(waybar_dir),
        hypr_dir=str(hypr_dir),
        repo_src_dir=str(Path(__file__).resolve().parent.parent / "src")
    )

    calc_bin = bin_dir / "currency-calculator"
    module_bin = bin_dir / "waybar-currency-module"
    revert_bin = bin_dir / "currency-calculator-revert"

    assert calc_bin.is_file()
    assert module_bin.is_file()
    assert revert_bin.is_file()

    assert os.access(calc_bin, os.X_OK)
    assert os.access(module_bin, os.X_OK)
    assert os.access(revert_bin, os.X_OK)

    assert "currency_calc.app" in calc_bin.read_text()
    assert "currency_calc.waybar_module" in module_bin.read_text()
    assert "restore_config_dir" in revert_bin.read_text()


def test_waybar_config_and_style_updated(tmp_path):
    bin_dir = tmp_path / "bin"
    waybar_dir = tmp_path / "waybar"
    hypr_dir = tmp_path / "hypr"

    waybar_dir.mkdir(parents=True)
    config_file = waybar_dir / "config.jsonc"
    config_file.write_text('{\n  "modules-right": ["clock"]\n}')

    install_all(
        bin_dir=str(bin_dir),
        waybar_dir=str(waybar_dir),
        hypr_dir=str(hypr_dir),
        repo_src_dir=str(Path(__file__).resolve().parent.parent / "src")
    )

    content = json.loads(config_file.read_text())
    assert "custom/currency" in content
    assert content["custom/currency"]["format"] == "{}"
    assert content["custom/currency"]["interval"] == 3600
    assert content["custom/currency"]["return-type"] == "json"
    assert "waybar-currency-module" in content["custom/currency"]["exec"]
    assert "currency-calculator" in content["custom/currency"]["on-click"]
    assert "custom/currency" in content["modules-right"]

    style_file = waybar_dir / "style.css"
    assert style_file.is_file()
    assert "#custom-currency" in style_file.read_text()


def test_hyprland_conf_updated(tmp_path):
    bin_dir = tmp_path / "bin"
    waybar_dir = tmp_path / "waybar"
    hypr_dir = tmp_path / "hypr"

    hypr_dir.mkdir(parents=True)
    hypr_conf = hypr_dir / "hyprland.conf"
    hypr_conf.write_text("# Hyprland config\n")

    install_all(
        bin_dir=str(bin_dir),
        waybar_dir=str(waybar_dir),
        hypr_dir=str(hypr_dir),
        repo_src_dir=str(Path(__file__).resolve().parent.parent / "src")
    )

    conf_text = hypr_conf.read_text()
    assert "windowrule = float, match:class ^(com.cachyos.currencycalc)$" in conf_text
    assert "windowrule = size 420 540, match:class ^(com.cachyos.currencycalc)$" in conf_text
    assert "windowrule = center, match:class ^(com.cachyos.currencycalc)$" in conf_text


def test_revert_script_restores_config(tmp_path):
    bin_dir = tmp_path / "bin"
    waybar_dir = tmp_path / "waybar"
    hypr_dir = tmp_path / "hypr"

    waybar_dir.mkdir(parents=True)
    config_file = waybar_dir / "config.jsonc"
    config_file.write_text('{"original": true}')

    res = install_all(
        bin_dir=str(bin_dir),
        waybar_dir=str(waybar_dir),
        hypr_dir=str(hypr_dir),
        repo_src_dir=str(Path(__file__).resolve().parent.parent / "src")
    )

    # Corrupt or modify current config
    config_file.write_text('{"modified": true}')

    revert_bin = bin_dir / "currency-calculator-revert"
    cmd = [str(revert_bin), res["backup_dir"], str(waybar_dir)]
    env = os.environ.copy()
    env["PYTHONPATH"] = str(Path(__file__).resolve().parent.parent / "src")
    subprocess.run(cmd, env=env, check=True)

    assert config_file.read_text() == '{"original": true}'


def test_install_currency_calc_sh_script(tmp_path):
    bin_dir = tmp_path / "bin"
    waybar_dir = tmp_path / "waybar"
    hypr_dir = tmp_path / "hypr"

    script_path = Path(__file__).resolve().parent.parent / "install_currency_calc.sh"
    assert script_path.is_file()

    env = os.environ.copy()
    env["PYTHONPATH"] = str(Path(__file__).resolve().parent.parent / "src")

    cmd = [
        "bash", str(script_path),
        "--bin-dir", str(bin_dir),
        "--waybar-dir", str(waybar_dir),
        "--hypr-dir", str(hypr_dir),
    ]

    subprocess.run(cmd, env=env, check=True)

    assert (bin_dir / "currency-calculator").is_file()
    assert (bin_dir / "waybar-currency-module").is_file()
    assert (bin_dir / "currency-calculator-revert").is_file()
    assert (waybar_dir / "config.jsonc").is_file()
    assert (waybar_dir / "style.css").is_file()
    assert (hypr_dir / "hyprland.conf").is_file()
