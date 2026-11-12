"""The operational matrix cannot silently drop a PHP command or schedule."""
import pathlib
import re
import unittest
from datetime import datetime, timedelta, timezone

from lhie_candidate.operations import contract

ROOT = pathlib.Path(__file__).resolve().parents[2]


def php_commands():
    names = set()
    for path in sorted((ROOT / "src/app/Console/Commands").glob("*.php")):
        text = path.read_text()
        match = re.search(r"protected \$signature = ['\"]([^'\"]+)", text)
        if match:
            signature = match.group(1)
        else:
            match = re.search(r"protected \$signature = <<<'[^']+'\n(.*?)\n", text, re.S)
            signature = match.group(1).strip() if match else ""
        if signature:
            names.add(signature.split()[0])
    return names


EXPECTED_SCHEDULE = frozenset({
    "suppliers:sync", "suppliers:recover", "usdt-funding:recover",
    "usdt-funding:release-expired", "usdt-balance:reconcile",
    "usdt-balance:aggregate-accounting", "ops:balance-alerts",
    "usdt-balance:metrics", "klikqris:reconcile", "klikqris:backfill-in-flight",
    "klikqris:reconcile-balance-top-ups", "store-balance:expire-top-ups",
    "sakurupiah:reconcile", "sakurupiah:reconcile-balance-top-ups",
    "pakasir:reconcile", "binance:reconcile", "bsc:reconcile",
    "orders:expire-pending", "orders:recover-local-completion",
    "orders:recover-credential-mails", "telegram:recover-updates",
    "telegram:forward-payment-proofs", "telegram:reconcile-stock-notifications",
    "waitlist:reconcile", "suppliers:notify-health",
    "ops:payment-lifecycle-rollout-report", "customer-auth:cleanup",
    "customer-auth:recover-mail", "customer-api:flush-usage",
    "ops:cleanup-retained-data", "notifications:daily-admin-stats",
})


class MatrixTests(unittest.TestCase):
    def test_every_php_command_has_one_explicit_owner(self):
        commands = php_commands()
        names = {operation.name for operation in contract.OPERATIONS}
        self.assertFalse(commands - names, "PHP commands missing from the matrix")
        self.assertEqual(names - commands, contract.JOB_NAMES)

    def test_every_scheduled_php_command_has_a_python_owner_and_test(self):
        self.assertEqual(contract.SCHEDULE_NAMES, EXPECTED_SCHEDULE)
        for operation in contract.SCHEDULE:
            with self.subTest(operation=operation.name):
                self.assertEqual(operation.state, contract.SCHEDULED)
                self.assertTrue(operation.owner.startswith("operations.jobs:"))
                self.assertIn(".test_", operation.evidence)
                self.assertIn(operation.policy, contract.POLICIES)
                self.assertIsNotNone(operation.schedule)

    def test_manual_rows_are_explicit_not_scheduled(self):
        for operation in contract.OPERATIONS:
            if operation.state == contract.SCHEDULED:
                continue
            self.assertIsNone(operation.schedule)
            self.assertNotEqual(operation.policy, "retired")
            if operation.state == contract.OPERATOR:
                self.assertEqual(operation.owner, "operator:php-artisan")

    def test_bounds_and_lease_never_exceed_the_scheduler_contract(self):
        for operation in contract.OPERATIONS:
            with self.subTest(operation=operation.name):
                self.assertLessEqual(operation.limit, 5000)
                self.assertGreaterEqual(operation.max_seconds, 1)
                self.assertLessEqual(operation.max_seconds, 300)
                self.assertGreaterEqual(operation.lease_seconds, operation.max_seconds)
                self.assertLessEqual(operation.lease_seconds, 3600)

    def test_duplicate_rows_cannot_replace_the_scheduled_owner(self):
        self.assertEqual(len({operation.name for operation in contract.OPERATIONS}),
                         len(contract.OPERATIONS))
        daily = contract.OPERATION_BY_NAME["notifications:daily-admin-stats"]
        self.assertEqual(daily.owner, "operations.jobs:notifications_daily_admin_stats")


class ScheduleTests(unittest.TestCase):
    def test_interval_slots_run_once_and_never_replay_a_burst(self):
        schedule = contract.interval(60)
        at = datetime(2026, 11, 12, 10, 0, 30, tzinfo=timezone.utc)
        self.assertTrue(schedule.due(at, None))
        slot = schedule.slot(at)
        self.assertFalse(schedule.due(at, slot))
        self.assertFalse(schedule.due(at + timedelta(seconds=20), slot))
        self.assertTrue(schedule.due(at + timedelta(seconds=40), slot))
        # A long missed interval still yields one due tick, not a burst.
        self.assertTrue(schedule.due(at, schedule.slot(at) - 100))

    def test_daily_schedule_waits_for_the_jakarta_wall_clock(self):
        schedule = contract.daily_at(1, 0)
        before = datetime(2026, 11, 12, 17, 59, tzinfo=timezone.utc)  # 00:59 Jakarta
        after = datetime(2026, 11, 12, 18, 0, tzinfo=timezone.utc)   # 01:00 Jakarta
        self.assertFalse(schedule.due(before, None))
        self.assertTrue(schedule.due(after, None))
        self.assertFalse(schedule.due(after + timedelta(minutes=5), schedule.slot(after)))
        self.assertTrue(schedule.due(after + timedelta(days=1), schedule.slot(after)))

    def test_clock_and_persisted_slot_are_validated(self):
        schedule = contract.interval(60)
        with self.assertRaises(ValueError):
            schedule.due(datetime(2026, 1, 1), None)
        with self.assertRaises(ValueError):
            schedule.due(datetime(2026, 1, 1, tzinfo=timezone.utc), "1")
        with self.assertRaises(ValueError):
            contract.interval(59)
        with self.assertRaises(ValueError):
            contract.daily_at(24, 0)
        with self.assertRaises(ValueError):
            contract.scheduled_operation("telegram:webhook:set")


if __name__ == "__main__":
    unittest.main()
