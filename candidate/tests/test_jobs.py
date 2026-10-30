import json
import unittest

from lhie_candidate.adapters.jobs import AffordabilityJob, InvalidJob, handle_affordability_job
from lhie_candidate.domain.money import Currency, MAX_UNITS, Money
from lhie_candidate.domain.purchase import Affordability, AssessPurchase, PurchaseInputs


class JobTests(unittest.TestCase):
    def test_versioned_id_only_round_trip(self):
        for request_id in (1, MAX_UNITS):
            with self.subTest(request_id=request_id):
                job = AffordabilityJob(request_id)
                wire = job.encode()
                self.assertEqual(json.loads(wire), {
                    "version": 1, "kind": "purchase.affordability", "request_id": request_id,
                })
                self.assertEqual(AffordabilityJob.decode(wire), job)
                self.assertEqual(AffordabilityJob.decode(wire.encode()), job)

    def test_rejects_model_snapshots_amounts_secrets_and_unknown_keys(self):
        base = {"version": 1, "kind": "purchase.affordability", "request_id": 1}
        for field, value in (
            ("model", {"id": 1}), ("payload", {}), ("amount", 100),
            ("amount_micro", 1), ("currency", "USDT"), ("token", "fixture-secret"),
            ("quantity", 1), ("customer_id", 1), ("unknown", None),
        ):
            with self.subTest(field=field), self.assertRaises(InvalidJob) as error:
                AffordabilityJob.decode(json.dumps(base | {field: value}))
            self.assertNotIn(str(value), str(error.exception))
        with self.assertRaises(InvalidJob):
            AffordabilityJob.decode(base)
        for key in base:
            with self.subTest(missing=key), self.assertRaises(InvalidJob):
                AffordabilityJob.decode(json.dumps({k: v for k, v in base.items() if k != key}))

    def test_rejects_wrong_version_kind_and_non_integer_ids(self):
        base = {"version": 1, "kind": "purchase.affordability", "request_id": 1}
        for field, values in (
            ("version", (True, 1.0, "1", 0, 2, None)),
            ("kind", (None, 1, "purchase.execute", "App\\Jobs\\Purchase")),
            ("request_id", (True, False, 0, -1, 1.0, "1", None, {}, [], MAX_UNITS + 1)),
        ):
            for value in values:
                with self.subTest(field=field, value=value), self.assertRaises(InvalidJob):
                    AffordabilityJob.decode(json.dumps(base | {field: value}))
        for value in (True, 0, -1, 1.0, "1", MAX_UNITS + 1):
            with self.subTest(value=value), self.assertRaises(InvalidJob):
                AffordabilityJob(value)

    def test_rejects_php_serialization_and_malformed_json(self):
        for raw in (
            'O:16:"App\\Jobs\\Purchase":0:{}', 'a:1:{s:2:"id";i:1;}',
            's:6:"secret";', '"O:1:\\"X\\":0:{}"',
            "", "null", "[]", "1", "true", "{", b"\xff", " " * 257,
            '{"version":1,"kind":"purchase.affordability","request_id":1,"request_id":2}',
            '{"version":1,"version":1,"kind":"purchase.affordability","request_id":1}',
            '{"version":1,"kind":"purchase.affordability","request_id":NaN}',
        ):
            with self.subTest(raw=raw), self.assertRaises(InvalidJob):
                AffordabilityJob.decode(raw)

    def test_adapter_resolves_trusted_inputs_instead_of_carrying_money(self):
        class Reader:
            def __init__(self):
                self.read_ids = []

            def load_affordability(self, request_id):
                self.read_ids.append(request_id)
                return PurchaseInputs(Money(Currency.USDT, 7), Money(Currency.USDT, 4), 2)

        reader = Reader()
        outcome = handle_affordability_job(AffordabilityJob(42).encode(), AssessPurchase(reader))
        self.assertEqual(reader.read_ids, [42])
        self.assertEqual(outcome.outcome, Affordability.INSUFFICIENT_FUNDS)
        self.assertEqual(outcome.total.units, 8)
        self.assertEqual(outcome.shortfall.units, 1)
        with self.assertRaises(InvalidJob):
            handle_affordability_job('{"request_id":42,"amount":1}', AssessPurchase(reader))
        self.assertEqual(reader.read_ids, [42])
