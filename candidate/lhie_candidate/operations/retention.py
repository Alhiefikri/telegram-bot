"""Bounded privacy cleanup and read-only notification retention evidence.

Cleanup redacts aged, processed Telegram payloads but keeps update IDs and
processed markers as replay tombstones. Provider payment events, financial
history, outbound effects, audit logs and unknown deliveries are never pruned.
"""

from datetime import datetime
import base64
import json
import re

from django.db import connections, transaction

JAKARTA_TZ = "Asia/Jakarta"
DEFAULT_RETENTION_DAYS = 90
MAX_CHUNK = 5000
MAX_BATCHES = 20
POLICY = "notification-retention-s09-v1"

FAMILIES = {
    "effects": ("telegram_outbound_effects", "id", True, None),
    "supplier-health": ("supplier_health_events", "id", True, "App\\Models\\SupplierHealthEvent"),
    "stock-events": ("stock_notification_events", "id", False, "App\\Models\\StockNotificationEvent"),
    "stock-recipients": ("stock_notification_recipients", "id", False,
                         "App\\Models\\StockNotificationRecipient"),
    "price-events": ("product_price_change_events", "id", False,
                     "App\\Models\\ProductPriceChangeEvent"),
    "price-recipients": ("product_price_change_notification_recipients", "id", False,
                         "App\\Models\\ProductPriceChangeNotificationRecipient"),
    "broadcasts": ("telegram_broadcasts", "id", False, "App\\Models\\TelegramBroadcast"),
    "broadcast-recipients": ("telegram_broadcast_recipients", "id", False,
                             "App\\Models\\TelegramBroadcastRecipient"),
}
CAPS = {"page": 100, "roots": 1000, "dependencies": 1000, "per_root": 200,
        "seconds": 10, "details": 20}


def _cursor(using):
    return connections[using].cursor()
def _scrub_page(cursor, cutoff, after, chunk):
    cursor.execute("""WITH page AS (
        SELECT id FROM public.telegram_updates
        WHERE id > %s AND processed_at IS NOT NULL AND created_at < %s
          AND (payload <> '{}'::jsonb OR customer_id IS NOT NULL)
        ORDER BY id LIMIT %s FOR UPDATE SKIP LOCKED
    ), scrubbed AS (
        UPDATE public.telegram_updates AS target
        SET payload='{}'::jsonb, customer_id=NULL
        FROM page WHERE target.id=page.id
        RETURNING target.id
    )
    SELECT count(*), max(id) FROM scrubbed""", (after, cutoff, chunk))
    count, last_id = cursor.fetchone()
    return int(count), last_id


def cleanup_retained_data(days=DEFAULT_RETENTION_DAYS, *, chunk=500, max_batches=10,
                          after=0, using="default"):
    if type(days) is not int or not 1 <= days <= 3650:
        raise ValueError("Invalid retention window")
    if type(chunk) is not int or not 1 <= chunk <= MAX_CHUNK:
        raise ValueError("Invalid retention chunk")
    if type(max_batches) is not int or not 1 <= max_batches <= 20:
        raise ValueError("Invalid retention batch bound")
    if type(after) is not int or not 0 <= after <= 9223372036854775807:
        raise ValueError("Invalid retention cursor")
    redacted, batches, complete, next_cursor = 0, 0, True, 0
    with transaction.atomic(using=using), _cursor(using) as cursor:
        cursor.execute("SELECT set_config('statement_timeout', %s, true)", ("1000ms",))
        cursor.execute("SELECT (CURRENT_TIMESTAMP AT TIME ZONE %s) - (%s * INTERVAL '1 day')",
                       (JAKARTA_TZ, days))
        cutoff = cursor.fetchone()[0]
        position = after
        while batches < max_batches:
            count, last_id = _scrub_page(cursor, cutoff, position, chunk)
            batches += 1
            if count == 0:
                # Wrap on the next daily pass so late-imported historical rows
                # are not permanently hidden behind a prior high-water mark.
                next_cursor = 0
                complete = True
                break
            redacted += count
            position = int(last_id)
            next_cursor = position
            if count < chunk:
                next_cursor = 0
                complete = True
                break
            complete = False
    return {"telegram_updates": {"redacted": redacted, "batches": batches},
            "next_cursor": next_cursor, "days": days, "chunk": chunk,
            "max_batches": max_batches, "complete": complete,
            "financial_history_untouched": True}




def _parse_as_of(value):
    if not isinstance(value, str) or re.fullmatch(
            r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}", value) is None:
        raise ValueError("Retention as-of requires an ISO8601 numeric offset")
    parsed = datetime.fromisoformat(value)
    if parsed.tzinfo is None:
        raise ValueError("Retention as-of requires an offset")
    return parsed


def _key(value, uuid=False):
    if value in (None, ""):
        return None
    value = str(value)
    if uuid:
        if re.fullmatch(r"[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}", value) is None:
            raise ValueError("Invalid retention key")
    elif re.fullmatch(r"[1-9][0-9]{0,18}", value) is None:
        raise ValueError("Invalid retention key")
    return value


def _manifest(family, policy, as_of, timezone, horizon, after, stop):
    return {"schema": 1, "family": family, "policy": policy, "as_of": as_of,
            "timezone": timezone, "horizon": horizon, "after": after, "stop": stop}


def _resume_token(manifest):
    return base64.b64encode(json.dumps(
        manifest, sort_keys=True, separators=(",", ":")).encode()).decode()


def notification_retention_report(family, *, as_of, horizon, policy=POLICY,
                                  timezone=JAKARTA_TZ, page=100, roots=1000,
                                  dependencies=1000, per_root=200, seconds=10,
                                  details=20, after=None, stop=None, resume=None,
                                  operator_artifact=False, using="default"):
    """Read-only, bounded classification; it never archives, deletes or resends."""
    if family not in FAMILIES:
        raise ValueError("Invalid retention family")
    if policy != POLICY:
        raise ValueError("Invalid retention policy")
    if timezone != JAKARTA_TZ:
        raise ValueError("Invalid retention timezone")
    if type(horizon) is not int or horizon not in (30, 90, 180):
        raise ValueError("Invalid retention horizon")
    budgets = {"page": page, "roots": roots, "dependencies": dependencies,
               "per_root": per_root, "seconds": seconds, "details": details}
    for key, value in budgets.items():
        if type(value) is not int or not 1 <= value <= CAPS[key]:
            raise ValueError("Invalid retention budget")
    if per_root < 2 or dependencies < 2:
        raise ValueError("Retention dependency budgets are too small")
    parsed = _parse_as_of(as_of)
    uuid_family = FAMILIES[family][2]
    after, stop = _key(after, uuid_family), _key(stop, uuid_family)
    manifest = _manifest(family, policy, as_of, timezone, horizon, after, stop)
    if resume:
        if len(resume) > 2048:
            raise ValueError("Retention resume manifest is too large")
        try:
            decoded = json.loads(base64.b64decode(resume, validate=True).decode("utf-8"))
        except Exception:
            raise ValueError("Invalid retention resume manifest") from None
        if decoded != _manifest(family, policy, as_of, timezone, horizon,
                                _key(decoded.get("after"), uuid_family),
                                _key(decoded.get("stop"), uuid_family)) or after or stop:
            raise ValueError("Retention resume manifest does not match this window")
        after, stop = decoded["after"], decoded["stop"]
    if after and stop:
        compare = (lambda a, b: a > b) if uuid_family else (lambda a, b: int(a) > int(b))
        if compare(after, stop):
            raise ValueError("Retention range is inverted")

    table, key_column, _, source_type = FAMILIES[family]
    started = datetime.now(parsed.tzinfo)
    scanned = protected = dependencies_scanned = 0
    reasons = set()
    samples = []
    truncated = False
    with transaction.atomic(using=using), _cursor(using) as cursor:
        cursor.execute("SET LOCAL statement_timeout = %s", (seconds * 1000,))
        # Every root is protected: this schema has no qualified archive,
        # complete reverse-reference proof or hold register. Missing evidence is
        # not negative evidence, matching the PHP classifier's closed result.
        base_reasons = ["reference_unproven", "hold_unproven", "archive_unverified",
                        "age_unproven"]
        cursor_position = after
        while scanned < roots:
            remaining = min(page, roots - scanned)
            sql = f"SELECT * FROM public.{table}"
            clauses, params = [], []
            if cursor_position is not None:
                clauses.append(f"{key_column} > %s")
                params.append(cursor_position)
            if stop is not None:
                clauses.append(f"{key_column} <= %s")
                params.append(stop)
            if clauses:
                sql += " WHERE " + " AND ".join(clauses)
            sql += f" ORDER BY {key_column} LIMIT %s"
            params.append(remaining)
            cursor.execute(sql, tuple(params))
            columns = [column[0] for column in cursor.description]
            rows = [dict(zip(columns, row)) for row in cursor.fetchall()]
            if not rows:
                break
            for row in rows:
                if (datetime.now(parsed.tzinfo) - started).total_seconds() > seconds:
                    truncated = True
                    break
                scanned += 1
                protected += 1
                reasons.update(base_reasons)
                state = str(row.get("state") or "")
                if state in ("pending", "sending"):
                    reasons.add("active_obligation")
                if state == "unknown":
                    reasons.add("ambiguity")
                if state == "manual_review":
                    reasons.add("manual_review")
                if source_type is not None:
                    dependency = min(per_root, max(0, dependencies - dependencies_scanned))
                    if dependency:
                        cursor.execute(f"""SELECT count(*) FROM public.telegram_outbound_effects
                            WHERE source_type=%s AND source_id=%s""",
                            (source_type, str(row[key_column])))
                        dependencies_scanned += min(int(cursor.fetchone()[0]), dependency)
                if len(samples) < details:
                    samples.append({"family": family, "state": state or "unproven",
                                    "classification": "protected",
                                    "reasons": sorted(set(base_reasons))})
                cursor_position = str(row[key_column])
                if dependencies_scanned >= dependencies:
                    truncated = True
                    break
            if truncated:
                break
            if len(rows) < remaining:
                break
        if not truncated and scanned == roots and cursor_position is not None:
            clauses = [f"{key_column} > %s"]
            params = [cursor_position]
            if stop is not None:
                clauses.append(f"{key_column} <= %s")
                params.append(stop)
            cursor.execute(f"SELECT 1 FROM public.{table} WHERE {' AND '.join(clauses)} "
                           f"ORDER BY {key_column} LIMIT 1", tuple(params))
            truncated = cursor.fetchone() is not None

    continuation_after = cursor_position if truncated else after
    continuation = _manifest(
        family, policy, as_of, timezone, horizon, continuation_after, stop)

    return {
        "schema": "notification-retention-report-v1",
        "policy": policy,
        "family": family,
        "as_of": as_of,
        "timezone": timezone,
        "horizon": horizon,
        "budgets": budgets,
        "scanned_roots": scanned,
        "protected": protected,
        "delete_candidates": 0,
        "reasons": sorted(reasons),
        "dependencies_scanned": dependencies_scanned,
        "truncated": truncated,
        "next_cursor": continuation_after if truncated else None,
        "resume": _resume_token(continuation) if truncated else None,
        "details": samples,
        "read_only": True,
        "operator_artifact": continuation if operator_artifact else None,
        "limits": ["no archive, reverse-reference registry or hold proof is qualified; "
                   "all scanned roots remain protected",
                   "read-only: no delete, archive, repair or resend"],
    }
