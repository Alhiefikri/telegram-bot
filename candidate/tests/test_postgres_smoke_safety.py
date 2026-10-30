import os
import unittest
from unittest.mock import Mock, patch

import smoke_postgres


class PostgresSmokeSafetyTests(unittest.TestCase):
    def test_requires_exact_explicit_disposable_opt_in(self):
        for value in (None, "", "true", "1", "production", smoke_postgres.OPT_IN + " "):
            environment = {} if value is None else {"CANDIDATE_POSTGRES_SMOKE": value}
            with self.subTest(value=value), patch.dict(os.environ, environment, clear=True):
                with self.assertRaises(smoke_postgres.SmokeFailure):
                    smoke_postgres.require_opt_in()
        with patch.dict(os.environ, {"CANDIDATE_POSTGRES_SMOKE": smoke_postgres.OPT_IN}, clear=True):
            self.assertIsNone(smoke_postgres.require_opt_in())

    def test_run_refuses_before_connecting_without_opt_in(self):
        with patch.dict(os.environ, {}, clear=True), patch.object(smoke_postgres, "_connect") as connect:
            with self.assertRaises(smoke_postgres.SmokeFailure):
                smoke_postgres.run()
            connect.assert_not_called()

    def test_connection_is_fixed_and_identity_checked_before_ddl(self):
        import psycopg

        for identity in (
            ("production", "harness", "127.0.0.1", 5432),
            ("disposable", "postgres", "127.0.0.1", 5432),
            ("disposable", "harness", "192.0.2.1", 5432),
            ("disposable", "harness", "127.0.0.1", 5433),
        ):
            connection = Mock()
            connection.execute.return_value.fetchone.return_value = identity
            with self.subTest(identity=identity), patch.dict(os.environ, {
                "CANDIDATE_POSTGRES_SMOKE": smoke_postgres.OPT_IN,
                "PGHOST": "production.invalid", "PGDATABASE": "production",
            }, clear=True), patch.object(psycopg, "connect", return_value=connection) as connect:
                with self.assertRaises(smoke_postgres.SmokeFailure):
                    smoke_postgres._connect()
                kwargs = connect.call_args.kwargs
                self.assertEqual(kwargs["host"], "127.0.0.1")
                self.assertEqual(kwargs["hostaddr"], "127.0.0.1")
                self.assertEqual(kwargs["port"], 5432)
                self.assertEqual(kwargs["dbname"], "disposable")
                self.assertEqual(kwargs["user"], "harness")
                self.assertEqual(kwargs["connect_timeout"], 3)
                connection.execute.assert_called_once()
                self.assertTrue(connection.execute.call_args.args[0].startswith("SELECT current_database()"))
                connection.close.assert_called_once()

    def test_sql_identifiers_cannot_target_legacy_schemas_or_tables(self):
        for schema, name in (
            ("public", "claims"), ("candidate", "claims"),
            ("p05_smoke_" + "a" * 32, "orders"),
            ("p05_smoke_" + "a" * 32 + '; DROP SCHEMA public', "claims"),
        ):
            with self.subTest(schema=schema, name=name), self.assertRaises(smoke_postgres.SmokeFailure):
                smoke_postgres._table(schema, name)
