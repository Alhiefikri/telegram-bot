import contextlib
import copy
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import MagicMock, patch

from lhie_candidate import runtime
from lhie_candidate.metadata import MetadataRouter


DOCUMENT = {
    "version": 1,
    "identity": "candidate",
    "dataset": "synthetic-empty",
    "quarantine": False,
    "database": {"host": "127.0.0.1", "port": 5432, "name": "disposable", "user": "candidate_runtime"},
    "redis": {"host": "127.0.0.1", "port": 6379, "db": 0},
    "limits": {"processes": 1, "threads": 1, "db_connections": 1},
}


class AdmissionTests(unittest.TestCase):
    def test_only_synthetic_or_digest_identified_datasets(self):
        for dataset in ("synthetic-empty", "sanitized-" + "a" * 64):
            document = copy.deepcopy(DOCUMENT)
            document["dataset"] = dataset
            self.assertEqual(runtime.validate_document(document), dataset)
        for dataset in ("raw-export", "sanitized-claimed", "synthetic-", "synthetic-x\n", None):
            with self.subTest(dataset=dataset), self.assertRaises(runtime.AdmissionError):
                runtime.validate_document({**DOCUMENT, "dataset": dataset})

    def test_strict_identity_quarantine_endpoints_and_limits(self):
        changes = [
            ("identity", "laravel"), ("version", True), ("quarantine", True),
            ("quarantine", 0), ("bot_token", "sensitive-sentinel"),
            ("database", {**DOCUMENT["database"], "host": "database"}),
            ("database", {**DOCUMENT["database"], "name": "production"}),
            ("database", {**DOCUMENT["database"], "port": "5432"}),
            ("redis", {**DOCUMENT["redis"], "db": False}),
            ("redis", {**DOCUMENT["redis"], "host": "::1"}),
            ("limits", {**DOCUMENT["limits"], "threads": 2}),
            ("limits", {**DOCUMENT["limits"], "db_connections": True}),
        ]
        for field, value in changes:
            with self.subTest(field=field, value=value), self.assertRaises(runtime.AdmissionError):
                runtime.validate_document({**DOCUMENT, field: value})
        with self.assertRaises(runtime.AdmissionError):
            runtime.validate_document({key: value for key, value in DOCUMENT.items() if key != "limits"})

    def test_fixed_files_private_key_and_duplicate_json_rejection(self):
        with tempfile.TemporaryDirectory() as directory:
            config = Path(directory) / "candidate.json"
            key = Path(directory) / "django-key"
            config.write_text(json.dumps(DOCUMENT))
            key.write_text("a" * 64 + "\n")
            key.chmod(0o600)
            with patch.object(runtime, "RUNTIME_PATH", config), patch.object(runtime, "KEY_PATH", key):
                admitted = runtime.load_runtime()
                self.assertEqual(admitted.dataset, "synthetic-empty")
                self.assertNotIn("a" * 64, repr(admitted))
                key.chmod(0o644)
                with self.assertRaisesRegex(runtime.AdmissionError, "^candidate runtime admission refused$"):
                    runtime.load_runtime()
                key.chmod(0o400)
                config.write_text(json.dumps(DOCUMENT)[:-1] + ', "identity":"candidate"}')
                with self.assertRaises(runtime.AdmissionError):
                    runtime.load_runtime()

    def test_missing_symlink_oversized_and_malformed_inputs_fail_without_details(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / "target"
            link = Path(directory) / "link"
            target.write_text("sensitive-sentinel")
            link.symlink_to(target)
            for path in (Path(directory) / "missing", link, target):
                with self.subTest(path=path), patch.object(runtime, "RUNTIME_PATH", path):
                    with self.assertRaises(runtime.AdmissionError) as caught:
                        runtime.load_runtime()
                    self.assertEqual(str(caught.exception), "candidate runtime admission refused")
            target.write_bytes(b"x" * 4097)
            with patch.object(runtime, "RUNTIME_PATH", target), self.assertRaises(runtime.AdmissionError):
                runtime.load_runtime()

    def test_migrations_denied_for_every_schema_owner(self):
        router = MetadataRouter()
        for label in ("auth", "admin", "sessions", "legacy", "django_candidate"):
            self.assertIs(router.allow_migrate("default", label), False)


class StartupTests(unittest.TestCase):
    def test_startup_dependency_failure_never_binds_or_echoes_exception(self):
        import serve
        stderr = io.StringIO()
        with patch.object(serve, "suppress_payload_logging"), patch.object(
            serve, "create_application", side_effect=RuntimeError("sensitive-sentinel")
        ), patch.object(serve, "make_server") as server, contextlib.redirect_stderr(stderr):
            self.assertEqual(serve.main(), 1)
        server.assert_not_called()
        self.assertEqual(stderr.getvalue(), "candidate startup refused\n")

    def test_unknown_management_commands_cannot_enable_migrations_or_override_settings(self):
        import manage
        for args in (["migrate"], ["makemigrations"], ["shell"], ["check", "--settings=unsafe"]):
            with self.subTest(args=args), patch.object(manage, "suppress_payload_logging"), patch.object(
                manage, "configure_django"
            ) as configure, patch("sys.argv", ["manage.py", *args]), contextlib.redirect_stderr(io.StringIO()):
                self.assertEqual(manage.main(), 1)
                configure.assert_not_called()

    def test_wsgi_fallback_has_no_exception_or_request_data(self):
        from lhie_candidate.startup import SafeApplication
        start = MagicMock()
        wrapped = SafeApplication(MagicMock(side_effect=ValueError("sensitive-sentinel")))
        body = b"".join(wrapped({"PATH_INFO": "/sensitive-sentinel"}, start))
        self.assertEqual(body, b'{"status":"error"}')
        self.assertEqual(start.call_args.args[0], "500 Internal Server Error")


class ReadinessTests(unittest.TestCase):
    def test_database_identity_is_queried_not_assumed_and_connection_always_closed(self):
        from lhie_candidate import readiness
        admitted = (
            "disposable", "candidate_runtime", "127.0.0.1", 5432, False,
            False, False, False, False, 4, "django_candidate", "candidate_runtime",
            False, False,
        )
        denied = []
        for index, wrong in enumerate((
            "production", "harness", "10.0.0.1", 5433, True,
            True, True, True, True, -1, "public", "harness", True, True,
        )):
            denied.append(admitted[:index] + (wrong,) + admitted[index + 1:])
        for identity in (admitted, *denied, None):
            connection = MagicMock()
            cursor = connection.cursor.return_value.__enter__.return_value
            cursor.fetchone.return_value = identity
            with self.subTest(identity=identity), patch.object(readiness, "connections", {"default": connection}):
                if identity == admitted:
                    readiness.verify_database()
                else:
                    with self.assertRaises(readiness.ReadinessError):
                        readiness.verify_database()
                connection.close.assert_called_once_with()
                self.assertTrue(cursor.execute.call_args.args[0].startswith("SELECT "))

    def test_database_errors_are_redacted_and_connections_closed(self):
        from lhie_candidate import readiness
        connection = MagicMock()
        connection.cursor.side_effect = RuntimeError("sensitive-sentinel")
        with patch.object(readiness, "connections", {"default": connection}):
            with self.assertRaises(readiness.ReadinessError) as caught:
                readiness.verify_database()
        self.assertEqual(str(caught.exception), "candidate dependencies unavailable")
        connection.close.assert_called_once_with()

    def test_redis_ping_is_bounded_loopback_and_accepts_partial_response(self):
        from lhie_candidate import readiness
        connection = MagicMock()
        connection.recv.side_effect = [b"+PO", b"NG\r\n"]
        with patch.object(readiness.socket, "create_connection") as create:
            create.return_value.__enter__.return_value = connection
            readiness.verify_redis()
        create.assert_called_once_with(("127.0.0.1", 6379), timeout=1)
        connection.settimeout.assert_called_once_with(1)
        connection.sendall.assert_called_once_with(b"*1\r\n$4\r\nPING\r\n")

    def test_redis_errors_and_wrong_protocol_are_not_ready(self):
        from lhie_candidate import readiness
        for reply in (b"-ERR\r\n", b"", b"secret!"):
            connection = MagicMock()
            connection.recv.side_effect = [reply, b""]
            with self.subTest(reply=reply), patch.object(readiness.socket, "create_connection") as create:
                create.return_value.__enter__.return_value = connection
                with self.assertRaisesRegex(readiness.ReadinessError, "^candidate dependencies unavailable$"):
                    readiness.verify_redis()
