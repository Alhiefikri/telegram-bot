"""P10 fake sandbox and boundary-threading checks without network access."""
import json
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'tools/migration_harness'))
import boundary
import harness


class FakeSandboxTests(unittest.TestCase):
    def setUp(self):
        boundary._FAKE_PAYMENTS.clear()

    def test_list_create_status_and_history_are_synthetic(self):
        self.assertEqual(boundary.fake_p08_request({"operation": "list", "fields": {"method": "list"}})["status"], "200")
        create = {"operation": "create", "fields": {
            "method": "QRIS", "phone": "08000000000", "amount": "15000", "merchant_fee": "2",
            "merchant_ref": "p10-synthetic", "callback_url": "https://synthetic.invalid/cb",
            "return_url": "https://synthetic.invalid/return"}}
        row = boundary.fake_p08_request(create)["data"][0]
        self.assertEqual((row["merchant_ref"], row["amount"], row["status"]),
                         ("p10-synthetic", "15000", "pending"))
        with patch.object(boundary, "time") as clock:
            status = boundary.fake_p08_request({"operation": "status", "fields": {
                "method": "status", "trx_id": row["trx_id"]}})
            clock.sleep.assert_called_once_with(4.0)
        self.assertEqual(status["data"][0]["payment_status"], "berhasil")
        history = boundary.fake_p08_request({"operation": "history", "fields": {
            "method": "transaction", "mechant": "1", "merchant_ref": "p10-synthetic"}})
        self.assertEqual(history["data"][0]["status"], "berhasil")

    def test_unknown_operations_fields_and_references_are_rejected(self):
        for body in (
            None, {}, {"operation": "list", "fields": {"method": "create"}},
            {"operation": "create", "fields": {"method": "QRIS"}},
            {"operation": "status", "fields": {"method": "status", "trx_id": "missing"}},
            {"operation": "history", "fields": {"method": "transaction", "mechant": "2",
                                                "merchant_ref": "missing"}},
            {"operation": "list", "fields": {"method": "list"}, "extra": True},
        ):
            with self.subTest(body=body), self.assertRaises(ValueError):
                boundary.fake_p08_request(body)

    def test_fake_mode_mounts_current_boundary_source_and_never_uses_real_credentials(self):
        config = harness.default("candidate")
        self.assertEqual(config["mode"], "fake")
        previous = harness.BOUNDARY_SOURCE
        harness.BOUNDARY_SOURCE = Path("/synthetic/boundary.py")
        try:
            spec = harness.compose(config, Path("/synthetic/state"))
        finally:
            harness.BOUNDARY_SOURCE = previous
        self.assertIn("/synthetic/boundary.py:/opt/harness/boundary.py:ro",
                      spec["services"]["boundary"]["volumes"])
        self.assertNotIn("sandbox_token", json.dumps(spec["services"]["app"]))


if __name__ == "__main__":
    unittest.main()
