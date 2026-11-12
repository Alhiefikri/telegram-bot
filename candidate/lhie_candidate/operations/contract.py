"""Scheduler, recovery and retention ownership contract.

The PHP ``routes/console.php`` schedule and every ``App\\Console\\Commands``
signature are inventoried here. Scheduled rows must point at a bounded Python
job; manual rows are explicitly retained or explicitly ported so nothing is
silently dropped when Artisan leaves the runtime.

Schedules are evaluated in small integer slots and persisted by
``operations.state``. A missed slot is never replayed as a burst: the next tick
sees one older slot and runs the operation once with its normal bound.
"""

from dataclasses import dataclass
from datetime import datetime, time
from zoneinfo import ZoneInfo


JAKARTA = ZoneInfo("Asia/Jakarta")

# Operational inventory states. ``scheduled`` rows are the ones the Python
# scheduler may execute. ``ported`` rows are manual operator tools that already
# have a Python owner and no automatic production activation. ``operator`` rows
# still require the old runtime until the retirement ticket; they are listed so
# no command disappears by accident.
SCHEDULED = "scheduled"
PORTED = "ported"
OPERATOR = "operator"

POLICIES = frozenset({
    "bounded_retry",       # rerun next due slot; durable work remains in PostgreSQL
    "defer",               # capability/recipient not admitted; no side effect
    "alert",               # record a sanitized alert episode on failure
    "read_only",           # reports/scans never mutate
    "operator_confirm",    # destructive/authority-sensitive manual action
    "owner_confirm",       # Owner-only security/financial action
    "retired",             # deliberate non-port, never run again
})


@dataclass(frozen=True, slots=True)
class Schedule:
    kind: str
    seconds: int | None = None
    at: time | None = None
    timezone: str = "Asia/Jakarta"

    def __post_init__(self):
        if self.kind not in ("interval", "daily"):
            raise ValueError("Unknown schedule kind")
        if self.kind == "interval":
            if type(self.seconds) is not int or not 60 <= self.seconds <= 86400 or self.seconds % 60:
                raise ValueError("Invalid interval schedule")
        elif not isinstance(self.at, time):
            raise ValueError("Daily schedule requires a time")
        ZoneInfo(self.timezone)

    def slot(self, now):
        if not isinstance(now, datetime) or now.tzinfo is None:
            raise ValueError("Scheduler clock must be timezone-aware")
        if self.kind == "interval":
            return int(now.timestamp()) // self.seconds
        return now.astimezone(ZoneInfo(self.timezone)).toordinal()

    def due(self, now, last_slot):
        if last_slot is not None and type(last_slot) is not int:
            raise ValueError("Invalid persisted slot")
        slot = self.slot(now)
        if self.kind == "interval":
            return last_slot is None or slot > last_slot
        local = now.astimezone(ZoneInfo(self.timezone))
        return (last_slot is None or local.toordinal() > last_slot) and local.time() >= self.at


def interval(seconds):
    return Schedule("interval", seconds=seconds)


def daily_at(hour=0, minute=0):
    return Schedule("daily", at=time(hour, minute), timezone="Asia/Jakarta")


@dataclass(frozen=True, slots=True)
class Operation:
    name: str
    owner: str
    schedule: Schedule | None
    policy: str
    state: str
    limit: int
    max_seconds: int
    lease_seconds: int
    evidence: str
    condition: str = ""

    def __post_init__(self):
        if not isinstance(self.name, str) or " " in self.name or not 1 <= len(self.name) <= 96:
            raise ValueError("Invalid operation name")
        if not isinstance(self.owner, str) or not self.owner:
            raise ValueError("Operation owner is required")
        if self.state not in (SCHEDULED, PORTED, OPERATOR):
            raise ValueError("Invalid operation state")
        if self.state == SCHEDULED and self.schedule is None:
            raise ValueError("Scheduled operation requires a schedule")
        if self.state != SCHEDULED and self.schedule is not None:
            raise ValueError("Only scheduled operations carry automatic cadence")
        if self.state == SCHEDULED and not self.owner.startswith("operations.jobs:"):
            raise ValueError("Scheduled operation must have a Python job owner")
        if self.policy not in POLICIES:
            raise ValueError("Invalid failure policy")
        if type(self.limit) is not int or not 1 <= self.limit <= 5000:
            raise ValueError("Operation limit is not bounded")
        if type(self.max_seconds) is not int or not 1 <= self.max_seconds <= 300:
            raise ValueError("Operation timeout is not bounded")
        if type(self.lease_seconds) is not int or not self.max_seconds <= self.lease_seconds <= 3600:
            raise ValueError("Operation lease must cover its timeout")
        if not isinstance(self.evidence, str) or not self.evidence:
            raise ValueError("Operation evidence reference is required")


def _row(name, owner, schedule, policy, state, limit, max_seconds, lease_seconds, evidence, condition=""):
    return Operation(name=name, owner=owner, schedule=schedule, policy=policy, state=state,
                     limit=limit, max_seconds=max_seconds, lease_seconds=lease_seconds,
                     evidence=evidence, condition=condition)


# The matrix intentionally mirrors the live PHP names. Keep entries one per
# command signature; the inventory test fails on a missing or extra command.
OPERATIONS = (
    # --- routes/console.php: every minute ---------------------------------
    _row("suppliers:sync", "operations.jobs:suppliers_sync", interval(60), "defer", SCHEDULED,
         5, 55, 180, "tests.test_operations_jobs; tools/migration_harness/smoke_operations.py",
         "supplier transport injected"),
    _row("suppliers:recover", "operations.jobs:suppliers_recover", interval(60), "defer", SCHEDULED,
         25, 55, 180, "tests.test_operations_jobs; tools/migration_harness/smoke_operations.py",
         "supplier transport injected"),
    _row("usdt-funding:recover", "operations.jobs:usdt_funding_recover", interval(60), "bounded_retry", SCHEDULED,
         25, 50, 180, "tests.test_operations_jobs; tests.test_operations_postgres.py"),
    _row("klikqris:reconcile", "operations.jobs:klikqris_reconcile", interval(60), "defer", SCHEDULED,
         10, 50, 180, "tests.test_operations_jobs; tests.test_operations_postgres.py",
         "provider readiness and adapter admitted"),
    _row("klikqris:reconcile-balance-top-ups", "operations.jobs:klikqris_funding_reconcile", interval(60), "defer", SCHEDULED,
         10, 50, 180, "tests.test_operations_jobs; tests.test_operations_postgres.py",
         "provider readiness and adapter admitted"),
    _row("store-balance:expire-top-ups", "operations.jobs:store_balance_expire_topups", interval(60), "bounded_retry", SCHEDULED,
         100, 50, 180, "tests.test_operations_jobs; tests.test_operations_postgres.py"),
    _row("sakurupiah:reconcile", "operations.jobs:sakurupiah_reconcile", interval(60), "defer", SCHEDULED,
         100, 50, 180, "tests.test_operations_jobs; tests.test_operations_postgres.py",
         "provider readiness and adapter admitted"),
    _row("sakurupiah:reconcile-balance-top-ups", "operations.jobs:sakurupiah_funding_reconcile", interval(60), "defer", SCHEDULED,
         10, 50, 180, "tests.test_operations_jobs; tests.test_operations_postgres.py",
         "provider readiness and adapter admitted"),
    _row("pakasir:reconcile", "operations.jobs:pakasir_reconcile", interval(60), "defer", SCHEDULED,
         100, 50, 180, "tests.test_operations_jobs; tests.test_operations_postgres.py",
         "historical adapter admitted"),
    _row("binance:reconcile", "operations.jobs:binance_reconcile", interval(60), "defer", SCHEDULED,
         100, 50, 180, "tests.test_operations_jobs; tests.test_operations_postgres.py",
         "Binance adapter admitted"),
    _row("bsc:reconcile", "operations.jobs:bsc_reconcile", interval(60), "defer", SCHEDULED,
         100, 50, 180, "tests.test_operations_jobs; tests.test_operations_postgres.py",
         "BSC RPC adapter admitted"),
    _row("orders:expire-pending", "operations.jobs:orders_expire_pending", interval(60), "bounded_retry", SCHEDULED,
         100, 50, 180, "tests.test_operations_jobs; tests.test_operations_postgres.py"),
    _row("orders:recover-credential-mails", "operations.jobs:orders_recover_credential_mails", interval(60), "defer", SCHEDULED,
         100, 55, 180, "tests.test_operations_jobs; tests.test_operations_postgres.py"),
    _row("telegram:recover-updates", "operations.jobs:telegram_recover_updates", interval(60), "bounded_retry", SCHEDULED,
         100, 55, 180, "tests.test_operations_jobs; tests.test_operations_postgres.py"),
    _row("telegram:forward-payment-proofs", "operations.jobs:telegram_forward_payment_proofs", interval(60), "defer", SCHEDULED,
         100, 55, 180, "tests.test_operations_jobs; tests.test_operations_postgres.py"),
    _row("telegram:reconcile-stock-notifications", "operations.jobs:telegram_reconcile_stock_notifications", interval(60), "defer", SCHEDULED,
         25, 55, 180, "tests.test_operations_jobs; tests.test_operations_postgres.py"),
    _row("waitlist:reconcile", "operations.jobs:waitlist_reconcile", interval(60), "defer", SCHEDULED,
         100, 55, 180, "tests.test_operations_jobs; tests.test_operations_postgres.py"),
    _row("suppliers:notify-health", "operations.jobs:suppliers_notify_health", interval(60), "defer", SCHEDULED,
         25, 55, 180, "tests.test_operations_jobs; tests.test_operations_postgres.py"),
    _row("customer-auth:recover-mail", "operations.jobs:customer_auth_recover_mail", interval(60), "defer", SCHEDULED,
         100, 55, 180, "tests.test_operations_jobs; tests.test_operations_postgres.py"),
    _row("customer-api:flush-usage", "operations.jobs:customer_api_flush_usage", interval(60), "bounded_retry", SCHEDULED,
         500, 50, 180, "tests.test_operations_jobs; tests.test_operations_postgres.py"),
    # --- routes/console.php: five/ten minutes -----------------------------
    _row("usdt-funding:release-expired", "operations.jobs:usdt_funding_release_expired", interval(600), "bounded_retry", SCHEDULED,
         25, 50, 180, "tests.test_operations_jobs; tests.test_operations_postgres.py"),
    _row("usdt-balance:reconcile", "operations.jobs:usdt_balance_reconcile", interval(300), "bounded_retry", SCHEDULED,
         500, 55, 300, "tests.test_operations_jobs; tests.test_operations_postgres.py"),
    _row("usdt-balance:aggregate-accounting", "operations.jobs:usdt_balance_aggregate_accounting", interval(300), "bounded_retry", SCHEDULED,
         2000, 55, 300, "tests.test_operations_jobs; tests.test_operations_postgres.py"),
    _row("ops:balance-alerts", "operations.jobs:ops_balance_alerts", interval(300), "bounded_retry", SCHEDULED,
         20, 50, 300, "tests.test_operations_jobs; tests.test_operations_postgres.py"),
    _row("klikqris:backfill-in-flight", "operations.jobs:klikqris_backfill_in_flight", interval(300), "defer", SCHEDULED,
         100, 55, 300, "tests.test_operations_jobs; tests.test_operations_postgres.py",
         "provider readiness and adapter admitted"),
    _row("orders:recover-local-completion", "operations.jobs:orders_recover_local_completion", interval(600), "bounded_retry", SCHEDULED,
         100, 55, 300, "tests.test_order_lifecycle_postgres; tests.test_operations_jobs; tests.test_operations_postgres.py"),
    _row("ops:payment-lifecycle-rollout-report", "operations.jobs:ops_payment_lifecycle_rollout_report", interval(300), "read_only", SCHEDULED,
         20, 50, 300, "tests.test_operations_jobs; tests.test_operations_postgres.py"),
    # --- routes/console.php: hourly/daily ---------------------------------
    _row("customer-auth:cleanup", "operations.jobs:customer_auth_cleanup", interval(3600), "bounded_retry", SCHEDULED,
         500, 55, 900, "tests.test_operations_jobs; tests.test_operations_postgres.py"),
    _row("usdt-balance:metrics", "operations.jobs:usdt_balance_metrics", daily_at(0, 0), "read_only", SCHEDULED,
         5000, 55, 3600, "tests.test_operations_jobs; tests.test_operations_postgres.py"),
    _row("ops:cleanup-retained-data", "operations.jobs:ops_cleanup_retained_data", daily_at(0, 30), "bounded_retry", SCHEDULED,
         500, 55, 3600, "tests.test_operations_jobs; tests.test_operations_postgres.py"),
    _row("notifications:daily-admin-stats", "operations.jobs:notifications_daily_admin_stats", daily_at(0, 0), "defer", SCHEDULED,
         25, 55, 300, "tests.test_operations_jobs; tests.test_operations_postgres.py"),

    # --- Manual operator tools already owned in Python --------------------
    _row("ops:health", "operations.health:health_report", None, "read_only", PORTED,
         100, 30, 60, "tests.test_operations_health"),
    _row("ops:release-health-summary", "operations.health:release_health_summary", None, "read_only", PORTED,
         100, 30, 60, "tests.test_operations_health"),
    _row("ops:check-failed-jobs", "operations.health:check_failed_jobs", None, "read_only", PORTED,
         100, 30, 60, "tests.test_operations_health"),
    _row("ops:check-queue-depth", "operations.health:queue_depth", None, "read_only", PORTED,
         100, 30, 60, "tests.test_operations_health"),
    _row("ops:assert-database-queue-empty", "operations.health:assert_database_queue_empty", None, "read_only", PORTED,
         1, 30, 60, "tests.test_operations_health"),
    _row("ops:scheduler-runtime-alert", "operations.alerts:scheduler_runtime", None, "alert", PORTED,
         20, 30, 60, "tests.test_operations_alerts"),
    _row("telegram:notification-retention-dry-run", "operations.retention:notification_retention_report", None, "read_only", PORTED,
         1000, 10, 60, "tests.test_operations_retention"),
    _row("telegram:recover-outbound", "operations.jobs:receipt_recover", None, "bounded_retry", PORTED,
         100, 55, 300, "tests.test_operations_jobs"),
    _row("usdt-balance:restore-status", "usdt.operations_restore:RestoreGate.status", None, "read_only", PORTED,
         20, 30, 60, "tests.test_p17_postgres"),
    _row("usdt-balance:restore-open", "usdt.operations_restore:RestoreGate.open", None, "owner_confirm", PORTED,
         20, 30, 60, "tests.test_p17_postgres"),
    _row("usdt-balance:restore-replay", "usdt.operations_restore:RestoreGate.replay", None, "owner_confirm", PORTED,
         25, 55, 300, "tests.test_p17_postgres"),
    _row("usdt-balance:restore-close", "usdt.operations_restore:RestoreGate.close", None, "owner_confirm", PORTED,
         20, 30, 60, "tests.test_p17_postgres"),
    _row("usdt-balance:unfreeze", "usdt.operations_controls:OperationalControls.unfreeze", None, "owner_confirm", PORTED,
         20, 30, 60, "tests.test_p17_postgres"),
    _row("usdt-balance:record-treasury-movement", "usdt.operations_reconciliation:TreasuryMovement.record", None, "owner_confirm", PORTED,
         20, 30, 60, "tests.test_p17_postgres"),
    _row("usdt-funding:hold-expired-reservations", "usdt.funding:FundingService.hold_expired", None, "bounded_retry", PORTED,
         25, 50, 180, "tests.test_operations_jobs"),
    _row("usdt-funding:recover-transfer", "usdt.funding:FundingService.recover_transfer", None, "bounded_retry", PORTED,
         25, 55, 300, "tests.test_operations_jobs"),
    _row("usdt-bsc:scan", "usdt.funding_settlement:FundingSettlement.recover", None, "bounded_retry", PORTED,
         25, 55, 300, "tests.test_operations_jobs"),
    _row("customers:cleanup-login-links", "operations.cleanup:cleanup_auth_challenges", None, "bounded_retry", PORTED,
         500, 55, 300, "tests.test_operations_cleanup"),
    _row("customer-auth:mail-health", "operations.health:auth_mail_health", None, "read_only", PORTED,
         1000, 30, 60, "tests.test_operations_health"),
    _row("customer-auth:readiness", "operations.health:auth_readiness", None, "read_only", PORTED,
         1000, 30, 60, "tests.test_operations_health"),
    _row("customer-auth:availability", "auth.http:set_availability", None, "operator_confirm", PORTED,
         20, 30, 60, "tests.test_auth_http"),
    _row("customer-auth:revoke", "auth.http:revoke_customer", None, "owner_confirm", PORTED,
         20, 30, 60, "tests.test_auth_postgres"),
    _row("pakasir:retirement-status", "payments.pakasir:PakasirRetirement.status", None, "read_only", PORTED,
         20, 30, 60, "tests.test_pakasir"),
    _row("pakasir:retirement-approve", "payments.pakasir:PakasirRetirement.approve", None, "owner_confirm", PORTED,
         20, 30, 60, "tests.test_pakasir"),
    _row("store-balance:check", "check_store_balance:main", None, "read_only", PORTED,
         100, 30, 60, "tests.test_adjustments_postgres"),
    _row("balance:release-readiness-report", "admin.balance:release_readiness", None, "read_only", PORTED,
         100, 30, 60, "tests.test_admin_operations"),
    _row("balance:money-receipt-cutover-report", "admin.balance:receipt_cutover", None, "read_only", PORTED,
         100, 30, 60, "tests.test_admin_operations"),
    _row("balance:backfill-money-receipts", "payments.receipts:claim_provider_reference", None, "operator_confirm", PORTED,
         100, 55, 300, "tests.test_p16_postgres"),
    _row("balance:resolve-money-receipt-reservation", "payments.receipts:_reserve", None, "owner_confirm", PORTED,
         20, 30, 60, "tests.test_p17_postgres"),
    _row("credentials:backfill-identity-registry", "admin.stock:identity_backfill", None, "operator_confirm", PORTED,
         100, 55, 300, "tests.test_admin_postgres"),
    _row("credentials:check-identity-readiness", "admin.stock:identity_readiness", None, "read_only", PORTED,
         100, 30, 60, "tests.test_admin_postgres"),
    _row("credentials:diagnose-identity-conflicts", "admin.stock:identity_conflicts", None, "read_only", PORTED,
         100, 30, 60, "tests.test_admin_postgres"),
    _row("klikqris:validate-production", "payments.klikqris:KlikQrisLifecycle.refresh", None, "operator_confirm", PORTED,
         10, 55, 300, "tests.test_klikqris"),
    _row("klikqris:validate-sandbox", "payments.klikqris:KlikQrisLifecycle.refresh", None, "operator_confirm", PORTED,
         10, 55, 300, "tests.test_klikqris"),
    _row("storefront:optimize-images", "storefront.media:optimize_images", None, "operator_confirm", PORTED,
         100, 55, 300, "tests.test_storefront_media"),
    _row("storefront:repair-family-slugs", "storefront.catalog:repair_family_slugs", None, "operator_confirm", PORTED,
         100, 55, 300, "tests.test_storefront_postgres"),
    _row("catalog:icons-check", "admin.catalog:icons_check", None, "read_only", PORTED,
         100, 30, 60, "tests.test_catalog_presentation"),
    _row("telegram:outbound-contract", "receipt.protocol:ReceiptProtocol.contract", None, "read_only", PORTED,
         100, 30, 60, "tests.test_receipt_transport"),
    _row("telegram:receipt-queue-cutover", "operations.jobs:assert_database_queue_empty", None, "operator_confirm", PORTED,
         1, 30, 60, "tests.test_operations_health"),
    _row("telegram:recover-outbound-worker", "operations.jobs:receipt_recover", None, "bounded_retry", PORTED,
         25, 55, 300, "tests.test_operations_jobs"),
    _row("telegram:recover-failed-updates", "navigation.processor:NavigationProcessor.recover", None, "bounded_retry", PORTED,
         100, 55, 300, "tests.test_navigation_ingress"),
    _row("ops:schedule-run", "operations.runner:Scheduler.run_once", None, "bounded_retry", PORTED,
         50, 300, 3600, "tests.test_operations_scheduler"),

    # --- Explicitly retained operation-only commands ----------------------
    # These are not scheduled jobs and still require Artisan authority or a
    # provider/credential that has no local admission. They are listed here so
    # retirement planning cannot mistake them for ported behavior.
    _row("admin:bootstrap-owner", "operator:php-artisan", None, "operator_confirm", OPERATOR,
         1, 30, 60, "P00 inventory; P30 retirement"),
    _row("suppliers:rate-audit", "operator:php-artisan", None, "owner_confirm", OPERATOR,
         500, 55, 300, "P00 inventory; P30 retirement"),
    _row("telegram:translation-audit", "operator:php-artisan", None, "read_only", OPERATOR,
         1, 30, 60, "P00 inventory; P30 retirement"),
    _row("telegram:benchmark-receipt-txt", "operator:php-artisan", None, "read_only", OPERATOR,
         1, 60, 120, "P00 inventory; P30 retirement"),
    _row("telegram:latency-baseline", "operator:php-artisan", None, "read_only", OPERATOR,
         1, 60, 120, "P00 inventory; P30 retirement"),
    _row("telegram:webhook:set", "operator:php-artisan", None, "owner_confirm", OPERATOR,
         1, 30, 60, "P00 inventory; P30 retirement"),
    _row("ops:check-telegram-webhook", "operator:php-artisan", None, "read_only", OPERATOR,
         1, 30, 60, "P00 inventory; P30 retirement"),
    _row("bsc:check-capabilities", "operator:php-artisan", None, "read_only", OPERATOR,
         1, 30, 60, "P00 inventory; P30 retirement"),
    _row("bsc:check-readiness", "operator:php-artisan", None, "read_only", OPERATOR,
         1, 30, 60, "P00 inventory; P30 retirement"),
    _row("sakurupiah:check-readiness", "operator:php-artisan", None, "read_only", OPERATOR,
         1, 30, 60, "P00 inventory; P30 retirement"),
    _row("customer-auth:smoke-mail", "operator:php-artisan", None, "operator_confirm", OPERATOR,
         1, 55, 120, "P00 inventory; P30 retirement"),
    _row("catalog:seed-demo", "operator:php-artisan", None, "operator_confirm", OPERATOR,
         100, 30, 60, "P00 inventory; P30 retirement"),
    _row("dashboard:seed-local-demo", "operator:php-artisan", None, "operator_confirm", OPERATOR,
         100, 30, 60, "P00 inventory; P30 retirement"),
    _row("usdt-topup:shared-receiver", "operator:php-artisan", None, "owner_confirm", OPERATOR,
         1, 30, 60, "P00 inventory; P30 retirement"),
    _row("usdt-receivers:register", "operator:php-artisan", None, "owner_confirm", OPERATOR,
         1, 30, 60, "P00 inventory; P30 retirement"),
    _row("usdt-receivers:retire", "operator:php-artisan", None, "owner_confirm", OPERATOR,
         1, 30, 60, "P00 inventory; P30 retirement"),
    _row("usdt-receivers:verify", "operator:php-artisan", None, "owner_confirm", OPERATOR,
         1, 30, 60, "P00 inventory; P30 retirement"),
)


OPERATION_BY_NAME = {operation.name: operation for operation in OPERATIONS}
SCHEDULE = tuple(operation for operation in OPERATIONS if operation.state == SCHEDULED)
SCHEDULE_NAMES = frozenset(operation.name for operation in SCHEDULE)

# The one non-Artisan scheduler entry: SendDailyAdminStatsNotification.
JOB_NAMES = frozenset({"notifications:daily-admin-stats"})


def scheduled_operation(name):
    operation = OPERATION_BY_NAME.get(name)
    if operation is None or operation.state != SCHEDULED:
        raise ValueError("Unknown scheduled operation")
    return operation
