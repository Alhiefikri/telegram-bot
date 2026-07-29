import os
import shutil
from datetime import datetime
from pathlib import Path


TARGET_FILES = ["config", "config.jsonc", "style.css"]


def backup_config_dir(source_dir: str, backup_base_dir: str) -> str:
    """
    Copies Waybar configuration files ('config', 'config.jsonc', 'style.css') from
    source_dir into a timestamped directory under backup_base_dir.

    Returns the absolute path of the created backup directory.
    """
    src_path = Path(os.path.expanduser(source_dir)).resolve()
    base_path = Path(os.path.expanduser(backup_base_dir)).resolve()

    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    backup_dir = base_path / f"waybar_backup_{timestamp}"
    backup_dir.mkdir(parents=True, exist_ok=True)

    for filename in TARGET_FILES:
        target_file = src_path / filename
        if target_file.is_file():
            shutil.copy2(target_file, backup_dir / filename)

    return str(backup_dir)


def restore_config_dir(backup_dir: str, target_dir: str) -> bool:
    """
    Copies all files from backup_dir back into target_dir.

    Returns True if successful, False if backup_dir does not exist or is not a directory.
    """
    bkp_path = Path(os.path.expanduser(backup_dir)).resolve()
    tgt_path = Path(os.path.expanduser(target_dir)).resolve()

    if not bkp_path.exists() or not bkp_path.is_dir():
        return False

    tgt_path.mkdir(parents=True, exist_ok=True)

    for item in bkp_path.iterdir():
        if item.is_file():
            shutil.copy2(item, tgt_path / item.name)

    return True
