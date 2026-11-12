"""Python-only bootstrap validates artifacts and atomically records deltas."""
import hashlib
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

from lhie_candidate.operations import bootstrap


class BootstrapTests(unittest.TestCase):
    def test_checked_in_baseline_manifest_and_sql_are_consistent(self):
        baseline = bootstrap.load_baseline(
            Path(__file__).parents[1] / "lhie_candidate" / "operations")
        self.assertEqual(hashlib.sha256(baseline.path.read_bytes()).hexdigest(), baseline.sql_sha256)
        self.assertEqual(hashlib.sha256(
            bootstrap._normalized_sql(baseline.path.read_text()).encode()).hexdigest(),
            baseline.schema_sha256)
        self.assertEqual(baseline.manifest["postgres_major"], 16)
        self.assertGreater(baseline.manifest["counts"]["tables"], 0)

    def test_schema_digest_normalizes_minor_versions_but_rejects_client_major_mismatch(self):
        dump = ("-- Dumped from database version 16.1\n"
                "-- Dumped by pg_dump version 16.9\n"
                "CREATE TABLE public.p26_probe (id integer);\n")
        with patch.object(bootstrap, "_connection_parts", return_value=("dsn", "password", None)), \
                patch.object(bootstrap, "_env", return_value={}), \
                patch.object(bootstrap, "_run", return_value=dump):
            digest = bootstrap.public_schema_sha256("dsn", expected_major=16)
        self.assertEqual(digest, hashlib.sha256(
            b"CREATE TABLE public.p26_probe (id integer);\n").hexdigest())
        mismatched = dump.replace("pg_dump version 16.9", "pg_dump version 18.6")
        with patch.object(bootstrap, "_connection_parts", return_value=("dsn", "password", None)), \
                patch.object(bootstrap, "_env", return_value={}), \
                patch.object(bootstrap, "_run", return_value=mismatched):
            with self.assertRaisesRegex(bootstrap.BootstrapError, "major version"):
                bootstrap.public_schema_sha256("dsn", expected_major=16)

    def test_delta_history_is_embedded_in_one_private_transactional_script(self):
        version = "2026_11_12_000001_operations_metadata"
        with tempfile.TemporaryDirectory() as directory:
            delta = Path(directory) / (version + ".sql")
            delta.write_text("CREATE TABLE django_candidate.p26_probe(id integer);\n")
            observed = {}

            def apply(_dsn, path, **_kwargs):
                path = Path(path)
                observed["script"] = path.read_text()
                observed["mode"] = os.stat(path).st_mode & 0o777

            with patch.object(bootstrap, "_apply_psql", side_effect=apply):
                bootstrap._apply_with_history(
                    "dsn", delta, version, hashlib.sha256(delta.read_bytes()).hexdigest(),
                    "delta", None)
        self.assertEqual(observed["mode"], 0o600)
        self.assertIn("pg_advisory_xact_lock", observed["script"])
        self.assertIn("\\if :p26_already_applied", observed["script"])
        self.assertIn("CREATE TABLE django_candidate.p26_probe", observed["script"])
        self.assertIn("INSERT INTO django_candidate.candidate_schema_migrations", observed["script"])
        self.assertIn("\\endif", observed["script"])

    def test_history_rejects_changed_or_unknown_applied_delta(self):
        version = "2026_11_12_000001_operations_metadata"
        baseline = bootstrap.Baseline(
            Path("schema-baseline.sql"),
            {"version": "repo-schema-v1", "schema_sha256": "a" * 64})
        with tempfile.TemporaryDirectory() as directory:
            migrations = Path(directory) / "migrations"
            migrations.mkdir()
            delta = migrations / (version + ".sql")
            delta.write_text("SELECT 1;\n")
            history = [
                {"version": baseline.version, "checksum": baseline.schema_sha256, "kind": "baseline"},
                {"version": version, "checksum": hashlib.sha256(delta.read_bytes()).hexdigest(),
                 "kind": "delta"},
            ]
            self.assertIn(version, bootstrap._validated_history(
                baseline, directory, history))
            delta.write_text("SELECT 2;\n")
            with self.assertRaisesRegex(bootstrap.BootstrapError, "checksum"):
                bootstrap._validated_history(baseline, directory, history)
            history[1]["version"] = "2026_11_12_999999_unreviewed"
            with self.assertRaisesRegex(bootstrap.BootstrapError, "checksum"):
                bootstrap._validated_history(baseline, directory, history)


if __name__ == "__main__":
    unittest.main()
