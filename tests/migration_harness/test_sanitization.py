import copy
import importlib.util
from pathlib import Path
import secrets
import unittest

spec = importlib.util.spec_from_file_location("p02", Path(__file__).resolve().parents[2] / "tools/migration_data/rehearse.py")
p02 = importlib.util.module_from_spec(spec)
spec.loader.exec_module(p02)


class SanitizationTest(unittest.TestCase):
    def setUp(self):
        self.key, self.fp, self.new_key, self.new_fp = [secrets.token_bytes(32) for _ in range(4)]
        self.source = p02.seed(self.key, self.fp)

    def derive(self, source=None):
        return p02.sanitize(self.source if source is None else source, self.key, self.fp, self.new_key, self.new_fp)

    def test_financial_ownership_and_uncertain_history_survive(self):
        result = self.derive()
        self.assertEqual(p02.invariant(self.source), p02.invariant(result))
        self.assertEqual(result["ledger"], self.source["ledger"])
        self.assertEqual(result["accounts"], self.source["accounts"])
        self.assertEqual([r["status"] for r in result["orders"]], ["paid", "pending", "creation_unknown", "late_review"])
        self.assertEqual(result["effects"][2]["state"], "unknown")
        for old, new in zip(self.source["credentials"], result["credentials"]):
            self.assertNotEqual(old["identity_fingerprint"], new["identity_fingerprint"])
            self.assertNotIn("source-password", p02.decrypt(new["secret"], self.new_key))
        p02.audit(result, self.new_key, self.new_fp)
        for row in result["customers"]:
            self.assertTrue(row["email"].endswith("@example.invalid"))
            self.assertEqual(row["password_hash"], "!p02-disabled-login")

    def test_tampered_archive_or_context_never_decrypts(self):
        sealed = p02.seal(b"synthetic archive", self.key)
        for candidate, key in ((sealed[:-1] + bytes([sealed[-1] ^ 1]), self.key),
                               (sealed, self.new_key), (sealed[1:], self.key)):
            with self.subTest(length=len(candidate)), self.assertRaises(p02.Refused):
                p02.unseal(candidate, key)

    def test_unknown_schema_and_nested_fields_fail_closed(self):
        for table, field in (("customers", "new_contact"), ("ledger", "memo")):
            source = copy.deepcopy(self.source)
            source[table][0][field] = "sensitive"
            with self.subTest(field=field), self.assertRaises(p02.Refused):
                self.derive(source)
        source = copy.deepcopy(self.source)
        source["credentials"][1]["secret"] = p02.encrypt('{"email":"x@example.invalid","password":"p","auth_token":"t","tutorial":"t","extra":"secret"}', self.key)
        with self.assertRaises(p02.Refused):
            self.derive(source)

    def test_identity_and_projection_drift_cannot_be_repaired_by_redaction(self):
        for table, field, value in (("identities", "fingerprint", "bad"),
                                    ("accounts", "balance", 1), ("ledger", "balance_after", 1),
                                    ("credentials", "secret", "tampered")):
            source = copy.deepcopy(self.source)
            source[table][0][field] = value
            with self.subTest(table=table), self.assertRaises(p02.Refused):
                self.derive(source)

    def test_file_mapping_binds_bot_and_bytes_without_source_fallback(self):
        mapping = dict(source_file_id="source-id", target_file_id="test-id", target_bot="synthetic-testing-bot",
                       status="uploaded", asset_sha256="abc")
        self.assertEqual(p02.mapping_file_id(mapping, "synthetic-testing-bot", "abc"), "test-id")
        for changes, bot, sha in (({}, "production", "abc"), ({}, "synthetic-testing-bot", "changed"),
                                  ({"status": "requires-reupload"}, "synthetic-testing-bot", "abc"),
                                  ({"target_file_id": "source-id"}, "synthetic-testing-bot", "abc")):
            with self.subTest(changes=changes, bot=bot), self.assertRaises(p02.Refused):
                p02.mapping_file_id(dict(mapping, **changes), bot, sha)
