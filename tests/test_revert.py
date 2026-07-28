import os
import re
from pathlib import Path
import pytest

from currency_calc.revert import backup_config_dir, restore_config_dir


def test_backup_and_restore_config_dir(tmp_path):
    # Setup source dir with config, config.jsonc, and style.css
    source_dir = tmp_path / "waybar"
    source_dir.mkdir()

    config_file = source_dir / "config"
    config_jsonc_file = source_dir / "config.jsonc"
    style_css_file = source_dir / "style.css"
    extra_file = source_dir / "ignored.txt"

    config_file.write_text("config original content")
    config_jsonc_file.write_text("config.jsonc original content")
    style_css_file.write_text("style.css original content")
    extra_file.write_text("should not be backed up unless specified")

    backup_base = tmp_path / "backups"

    # Test backup_config_dir
    backup_path_str = backup_config_dir(str(source_dir), str(backup_base))
    assert os.path.exists(backup_path_str)

    backup_dir = Path(backup_path_str)
    assert re.match(r"^waybar_backup_\d{8}_\d{6}$", backup_dir.name)

    # Check backed up files
    assert (backup_dir / "config").read_text() == "config original content"
    assert (backup_dir / "config.jsonc").read_text() == "config.jsonc original content"
    assert (backup_dir / "style.css").read_text() == "style.css original content"
    assert not (backup_dir / "ignored.txt").exists()

    # Modify original files
    config_file.write_text("config modified content")
    config_jsonc_file.write_text("config.jsonc modified content")
    style_css_file.write_text("style.css modified content")

    # Restore from backup
    success = restore_config_dir(str(backup_dir), str(source_dir))
    assert success is True

    # Verify content restored
    assert config_file.read_text() == "config original content"
    assert config_jsonc_file.read_text() == "config.jsonc original content"
    assert style_css_file.read_text() == "style.css original content"


def test_restore_nonexistent_backup_dir(tmp_path):
    target_dir = tmp_path / "target"
    fake_backup = tmp_path / "nonexistent_backup_dir"

    success = restore_config_dir(str(fake_backup), str(target_dir))
    assert success is False


def test_backup_partial_files(tmp_path):
    source_dir = tmp_path / "waybar_partial"
    source_dir.mkdir()

    # Only create config.jsonc
    (source_dir / "config.jsonc").write_text("only jsonc")

    backup_base = tmp_path / "backups_partial"

    backup_path_str = backup_config_dir(str(source_dir), str(backup_base))
    backup_dir = Path(backup_path_str)

    assert (backup_dir / "config.jsonc").read_text() == "only jsonc"
    assert not (backup_dir / "config").exists()
    assert not (backup_dir / "style.css").exists()
