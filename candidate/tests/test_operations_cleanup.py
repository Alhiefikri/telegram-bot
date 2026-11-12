"""Authentication cleanup refuses invalid bounds before opening a transaction."""
import unittest
from unittest.mock import patch

from lhie_candidate.operations import cleanup


class CleanupContractTests(unittest.TestCase):
    def test_invalid_cleanup_bounds_fail_before_database_access(self):
        for limit in (0, -1, 1001, True, "500"):
            with self.subTest(limit=limit), patch.object(cleanup, "_cursor") as cursor:
                with self.assertRaises(ValueError):
                    cleanup.cleanup_auth_challenges(limit)
                cursor.assert_not_called()

    def test_legacy_flag_must_be_an_explicit_boolean(self):
        for value in (1, "true", None):
            with self.subTest(value=value), patch.object(cleanup, "_cursor") as cursor:
                with self.assertRaises(ValueError):
                    cleanup.cleanup_auth_challenges(legacy_only=value)
                cursor.assert_not_called()


if __name__ == "__main__":
    unittest.main()
