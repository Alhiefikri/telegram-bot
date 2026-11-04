import unittest
from datetime import datetime
from unittest.mock import Mock

from lhie_candidate.auth.limits import RateLimits
from lhie_candidate.auth.service import password_hash
from lhie_candidate.auth.types import AuthError, Browser, LEGACY_ZONE, require_fresh
from lhie_candidate.compat.identity import verify_customer_password


class AuthenticationBoundaryTests(unittest.TestCase):
    def test_new_password_policy_keeps_unicode_and_suffix_after_bcrypt_boundary(self):
        first = "A9!" + "é" * 50 + "one"
        second = "A9!" + "é" * 50 + "two"
        stored = password_hash(first, rounds=4)
        self.assertTrue(verify_customer_password(first, stored, "sha384-v1", algorithm="bcrypt"))
        self.assertFalse(verify_customer_password(second, stored, "sha384-v1", algorithm="bcrypt"))
        for value in ("a9!lower", "UPPER!!!", "A1234567", "A1!short" * 17):
            with self.subTest(value=value), self.assertRaises(AuthError):
                password_hash(value, rounds=4)

    def test_fresh_proof_rejects_expired_future_and_stale_versions(self):
        at = datetime(2026, 11, 4, 12)
        timestamp = int(at.replace(tzinfo=LEGACY_ZONE).timestamp())
        customer = {"id": 7, "auth_version": 2}
        require_fresh(Browser("browser", "local", 7, 2, timestamp - 299, "magic"), customer, at)
        for identity, version, proof in ((7, 2, timestamp - 300), (7, 2, timestamp + 1),
                                         (7, 1, timestamp), (8, 2, timestamp), (7, 2, True)):
            with self.subTest(identity=identity, version=version, proof=proof), self.assertRaises(AuthError):
                require_fresh(Browser("browser", "local", identity, version, proof, "magic"), customer, at)

    def test_shared_limiter_outage_and_denial_never_admit(self):
        client = Mock()
        client.eval.side_effect = ConnectionError("private-provider-error")
        limits = RateLimits("synthetic-app-key", client)
        with self.assertRaises(AuthError) as outcome:
            limits.reserve([("login", "private@example.invalid", 5, 900)])
        self.assertEqual((outcome.exception.code, outcome.exception.status), ("unavailable", 503))
        self.assertNotIn("private", str(outcome.exception))
        client.eval.side_effect = None
        client.eval.return_value = []
        with self.assertRaises(AuthError) as outcome:
            limits.reserve([("login", "private@example.invalid", 5, 900)])
        self.assertEqual(outcome.exception.code, "rate_limited")
        self.assertNotIn("private@example.invalid", str(client.eval.call_args))
