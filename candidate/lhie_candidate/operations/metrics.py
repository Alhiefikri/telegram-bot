"""Bounded, secret-free operational counters and the balance metrics report.

The report is read-only over money state. Its only mutation is the bounded
retention delete of ``balance_operation_events`` rows, which are observability
records, never a ledger or delivery authority. A metrics failure can therefore
never change a balance, receipt, effect or Order.
"""

from collections import deque
from datetime import datetime
import json
import os
from pathlib import Path
import tempfile
from zoneinfo import ZoneInfo

from django.db import connections

from .state import safe_summary

JAKARTA = ZoneInfo("Asia/Jakarta")

COUNTERS = ("runs", "success", "partial", "failure", "timeout", "deferred", "skipped",
            "metrics_failed")
DURATIONS = ("job",)
BALANCE_METRICS = {
    "ledger_outcome": ("posted", "replayed", "insufficient_funds", "frozen",
                       "projection_drift", "identity_conflict"),
    "lock_retry": ("deadlock", "concurrency"),
    "receipt_claim_outcome": ("claimed", "replayed", "conflict", "review"),
    "funding_reservation_outcome": ("reserved", "existing_active", "replayed", "conflict"),
    "outbound_delivery_outcome": ("claimed", "sent", "failed", "unknown", "skipped_paused"),
}
OUTBOUND_BACKLOG_EFFECTS = (
    "usdt.top_up_receipt", "usdt.refund_receipt", "balance.top_up_receipt",
    "admin.balance_top_up_owner_alert", "admin.balance_top_up_paid_notification",
    "balance.owner_compensation_notification",
)
MAX_PRUNE_CHUNK = 5000
MAX_PRUNE_BATCHES = 20


def _now():
    return datetime.now(JAKARTA).replace(tzinfo=None)


def _iso(value):
    if value is None:
        return None
    if isinstance(value, datetime):
        if value.tzinfo is None:
            value = value.replace(tzinfo=JAKARTA)
        return value.astimezone(JAKARTA).isoformat()
    return str(value)


def _oldest_age(oldest, now):
    if oldest is None:
        return 0
    if isinstance(oldest, datetime):
        if oldest.tzinfo is not None:
            oldest = oldest.astimezone(JAKARTA).replace(tzinfo=None)
        return max(0, int((now - oldest).total_seconds()))
    return 0


class OperationMetrics:
    """Per-scheduler-run counters; files are private and payload-free."""

    def __init__(self, name, *, maximum=256):
        if not isinstance(name, str) or not name:
            raise ValueError("A scheduler operation name is required")
        if type(maximum) is not int or not 8 <= maximum <= 4096:
            raise ValueError("Invalid metrics bound")
        self.name = name
        self.maximum = maximum
        self._counters = dict.fromkeys(COUNTERS, 0)
        self._durations = {key: deque(maxlen=maximum) for key in DURATIONS}

    def record(self, name, seconds=None):
        if name not in self._counters and name not in self._durations:
            raise ValueError("Unknown operation metric")
        if name in self._counters:
            self._counters[name] += 1
        if seconds is not None:
            if name not in self._durations:
                raise ValueError("Unknown operation duration")
            if type(seconds) not in (int, float) or not 0 <= seconds <= 3600:
                raise ValueError("Invalid operation duration")
            self._durations[name].append(round(float(seconds) * 1000, 3))

    def snapshot(self):
        durations = {}
        for name, values in self._durations.items():
            ordered = sorted(values)
            durations[name] = {"count": len(ordered),
                               "max_ms": ordered[-1] if ordered else 0.0,
                               "min_ms": ordered[0] if ordered else 0.0,
                               "total_ms": round(sum(ordered), 3)}
        return {"operation": self.name, "counters": dict(self._counters), "durations": durations}

    def write(self, path):
        target = Path(path)
        directory = target.parent
        if not directory.is_dir():
            raise ValueError("Metrics directory is unavailable")
        descriptor, temporary = tempfile.mkstemp(prefix=".operation-metrics-", dir=directory)
        try:
            with os.fdopen(descriptor, "w") as stream:
                json.dump(self.snapshot(), stream, sort_keys=True, separators=(",", ":"))
                stream.write("\n")
            os.chmod(temporary, 0o600)
            os.replace(temporary, target)
        except Exception:
            try:
                os.unlink(temporary)
            except OSError:
                pass
            raise


class BalanceMetrics:
    def __init__(self, using="default"):
        self.using = using

    def _rows(self, sql, params=()):
        with connections[self.using].cursor() as cursor:
            cursor.execute(sql, params)
            columns = [column[0] for column in cursor.description]
            return [dict(zip(columns, row)) for row in cursor.fetchall()]

    def _one(self, sql, params=()):
        rows = self._rows(sql, params)
        return rows[0] if rows else None

    def _facet(self, sql, params, count_label, now):
        row = self._one(sql, params) or {}
        return {"count" if count_label == "count" else count_label: int(row.get("aggregate") or 0),
                "oldest_age_seconds": _oldest_age(row.get("oldest"), now)}

    def report(self, window_minutes=60):
        if type(window_minutes) is not int or not 1 <= window_minutes <= 1440:
            raise ValueError("Invalid metrics window")
        now = _now()
        counters = {metric: dict.fromkeys(outcomes, 0)
                    for metric, outcomes in BALANCE_METRICS.items()}
        rows = self._rows("""SELECT metric,outcome,count(*) AS aggregate
            FROM public.balance_operation_events
            WHERE occurred_at >= (CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta') - (%s * INTERVAL '1 minute')
            GROUP BY metric,outcome""", (window_minutes,))
        for row in rows:
            if row["metric"] in counters and row["outcome"] in counters[row["metric"]]:
                counters[row["metric"]][row["outcome"]] = int(row["aggregate"])

        backlog = {
            "top_up_preparation": self._facet("""SELECT count(*) AS aggregate,min(next_attempt_at) AS oldest
                FROM public.customer_usdt_top_up_work
                WHERE state IN ('pending','creating','creation_unknown')""", (), "pending", now),
            "top_up_settlement": self._facet("""SELECT count(*) AS aggregate,min(next_check_at) AS oldest
                FROM public.customer_usdt_top_up_settlement_work
                WHERE state NOT IN ('settled','externally_refunded','resolved_by_compensation','rejected')""",
                (), "open", now),
            "funding_reservations": self._facet("""SELECT count(*) AS aggregate,min(expires_at) AS oldest
                FROM public.customer_usdt_funding_reservations WHERE resolved_at IS NULL""",
                (), "unresolved", now),
            "balance_outbound": self._facet("""SELECT count(*) AS aggregate,min(created_at) AS oldest
                FROM public.telegram_outbound_effects
                WHERE state IN ('pending','failed') AND effect_type = ANY(%s)""",
                (list(OUTBOUND_BACKLOG_EFFECTS),), "pending", now),
            "top_up_review": self._facet("""SELECT count(*) AS aggregate,min(created_at) AS oldest
                FROM public.usdt_top_up_review_requests
                WHERE expires_at > (CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta')""",
                (), "open", now),
        }

        latest = self._one("""SELECT verdict,checked_at,account_finding_count,receipt_finding_count,
            drift_frozen_count,custody_state,liability_micro,unresolved_deposit_micro,
            custody_assets_micro,findings
            FROM public.usdt_balance_reconciliation_runs WHERE scope='full'
            ORDER BY checked_at DESC,id DESC LIMIT 1""")
        drift = {
            "frozen_accounts": int((self._one("""SELECT count(*) AS aggregate
                FROM public.customer_usdt_balance_accounts WHERE frozen_reason IS NOT NULL""") or {})["aggregate"]),
            "latest": None if latest is None else {
                "verdict": latest["verdict"],
                "checked_at": _iso(latest["checked_at"]),
                "account_findings": int(latest["account_finding_count"]),
                "receipt_findings": int(latest["receipt_finding_count"]),
                "drift_frozen": int(latest["drift_frozen_count"]),
                "custody_state": latest["custody_state"],
                "unresolved_deposit_micro": str(latest["unresolved_deposit_micro"]),
            },
        }
        findings = latest.get("findings") if latest else None
        if isinstance(findings, str):
            try:
                findings = json.loads(findings)
            except ValueError:
                findings = None
        unexplained = (findings or {}).get("treasury", {}).get("unexplained_micro") if isinstance(findings, dict) else None
        coverage = {
            "ledger_liability_micro": "0" if latest is None else str(latest["liability_micro"]),
            "unresolved_deposit_micro": "0" if latest is None else str(latest["unresolved_deposit_micro"]),
            "custody_state": None if latest is None else latest["custody_state"],
            "custody_assets_micro": None if latest is None or latest["custody_assets_micro"] is None
            else str(latest["custody_assets_micro"]),
            "verdict": None if latest is None else latest["verdict"],
        }
        conflict = {
            "review_receipts": int((self._one("""SELECT count(*) AS aggregate
                FROM public.external_money_receipts WHERE status='review'""") or {})["aggregate"]),
            "review_parent_claims": int((self._one("""SELECT count(*) AS aggregate
                FROM public.external_money_receipt_parent_claims WHERE status='review'""") or {})["aggregate"]),
            "open_registry_conflict_reservations": int((self._one("""SELECT count(*) AS aggregate
                FROM public.external_money_receipt_reservations
                WHERE resolved_at IS NULL AND reason='registry_conflict'""") or {})["aggregate"]),
        }
        pauses = [row["scope"] for row in self._rows(
            "SELECT scope FROM public.customer_usdt_funding_pauses ORDER BY scope")]
        gate = self._one("""SELECT state,opened_at,window_start,window_end,replay_completed_at
            FROM public.balance_restore_gates WHERE id=1""")
        controls = {
            "pauses": pauses,
            "restore_gate": {"state": "cleared" if gate is None else gate["state"],
                             "opened_at": None if gate is None else _iso(gate["opened_at"]),
                             "window_start": None if gate is None else _iso(gate["window_start"]),
                             "window_end": None if gate is None else _iso(gate["window_end"]),
                             "replay_completed_at": None if gate is None else _iso(gate["replay_completed_at"])},
        }
        return {
            "generated_at": datetime.now(JAKARTA).isoformat(),
            "window_minutes": window_minutes,
            "counters": counters,
            "backlog": backlog,
            "drift": drift,
            "coverage": coverage,
            "conflict": conflict,
            "controls": controls,
            "treasury_unexplained_micro": None if unexplained is None else str(unexplained),
        }

    def prune_metric_events(self, days=30, *, chunk=500, max_batches=MAX_PRUNE_BATCHES):
        if type(days) is not int or not 1 <= days <= 3650:
            raise ValueError("Invalid metric retention")
        if type(chunk) is not int or not 1 <= chunk <= MAX_PRUNE_CHUNK:
            raise ValueError("Invalid metric prune chunk")
        if type(max_batches) is not int or not 1 <= max_batches <= 100:
            raise ValueError("Invalid metric prune bound")
        deleted, batches = 0, 0
        with connections[self.using].cursor() as cursor:
            while batches < max_batches:
                cursor.execute("""WITH page AS (
                    SELECT id FROM public.balance_operation_events
                    WHERE occurred_at < (CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta')
                        - (%s * INTERVAL '1 day')
                    ORDER BY id LIMIT %s)
                    DELETE FROM public.balance_operation_events e USING page
                    WHERE e.id=page.id""", (days, chunk))
                count = cursor.rowcount
                deleted += count
                batches += 1
                if count < chunk:
                    break
        return {"deleted": deleted, "batches": batches,
                "complete": count < chunk, "chunk": chunk, "max_batches": max_batches}


def balance_report(window_minutes=60, *, using="default"):
    return BalanceMetrics(using).report(window_minutes)


def prune_metric_events(days=30, *, using="default"):
    return BalanceMetrics(using).prune_metric_events(days)


def metrics_snapshot(result):
    return safe_summary(result)
