"""Backup restore rejects unsafe paths and preserves explicit sidecar custody."""
from contextlib import nullcontext
from pathlib import Path
import shutil
import tarfile
import tempfile
import unittest
from unittest.mock import patch

from lhie_candidate.operations import backup


class BackupRestoreTests(unittest.TestCase):
    def test_existing_and_symlink_destinations_are_never_overwritten(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            existing = root / "existing"
            existing.mkdir()
            link = root / "link"
            link.symlink_to(existing, target_is_directory=True)
            for target in (existing, link):
                with self.subTest(target=target), self.assertRaises(backup.BackupError):
                    backup._restore_destination(target)
            self.assertTrue(existing.is_dir())

    def test_sidecar_restore_requires_destinations_before_database_mutation(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            archive = root / "backup.gpg"
            archive.write_bytes(b"encrypted")
            payload = root / "payload"
            (payload / "files").mkdir(parents=True)
            (payload / "keys").mkdir()
            manifest = {"files": [{"path": "data.bin", "bytes": 1}], "keys": []}
            with patch.object(backup, "_extracted", return_value=nullcontext((payload, manifest))), \
                    patch.object(backup, "user_tables") as empty_check:
                with self.assertRaisesRegex(backup.BackupError, "require a restore destination"):
                    backup.restore_backup(archive, "dsn", passphrase="long-enough-passphrase")
            empty_check.assert_not_called()

    def test_role_creation_is_conditional_but_existing_role_attributes_restore(self):
        with tempfile.TemporaryDirectory() as directory:
            roles = Path(directory) / "roles.sql"
            roles.write_text('CREATE ROLE harness;\nALTER ROLE harness WITH LOGIN;\n')
            captured = []

            def run(command, *, env, timeout=300):
                script = Path(command[command.index("-f") + 1]).read_text()
                captured.append((command, script))

            with patch.object(backup, "_connection_parts", return_value=("dsn", "", None)), \
                    patch.object(backup, "_run", side_effect=run):
                backup._restore_roles("dsn", roles)
            command, script = captured[0]
            self.assertIn("--single-transaction", command)
            self.assertIn("IF NOT EXISTS (SELECT 1 FROM pg_catalog.pg_roles", script)
            self.assertIn("EXECUTE 'CREATE ROLE harness;'", script)
            self.assertIn("ALTER ROLE harness WITH LOGIN;", script)
            self.assertNotIn("PASSWORD", script.upper())

    def test_archive_path_traversal_is_rejected_before_extraction(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            archive = root / "backup.gpg"
            archive.write_bytes(b"encrypted")
            tar_path = root / "malicious.tar"
            with tarfile.open(tar_path, "w") as tar:
                payload = b"x"
                member = tarfile.TarInfo("files/../../outside")
                member.size = len(payload)
                import io
                tar.addfile(member, io.BytesIO(payload))
            with patch.object(backup, "_gpg_decrypt",
                              side_effect=lambda _src, dst, _pass: shutil.copyfile(tar_path, dst)):
                with self.assertRaisesRegex(backup.BackupError, "Unsafe archive member"):
                    with backup._extracted(archive, "long-enough-passphrase"):
                        self.fail("unsafe archive was extracted")


if __name__ == "__main__":
    unittest.main()
