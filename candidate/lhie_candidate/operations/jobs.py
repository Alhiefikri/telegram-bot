"""Python owners for every scheduled operational command.

Provider and recipient capabilities are explicit composition, never inferred
from a stored row. When one is not admitted, the job returns ``deferred`` and
performs no network, recipient, credential or money write. The durable source
rows stay available for a later admitted run.
"""

from dataclasses import dataclass
from datetime import datetime
from zoneinfo import ZoneInfo

from django.db import connections, transaction

from .alerts import scheduler_runtime
from .cleanup import cleanup_auth_challenges
from .metrics import BalanceMetrics
from .retention import cleanup_retained_data

JAKARTA = ZoneInfo("Asia/Jakarta")

# Trusted composition seams. Tests and a future operator composition inject
# adapters explicitly; importing this module or starting the scheduler does not
# activate a provider, SMTP, Telegram or supplier client.
PAYMENT_ADAPTERS = {}
EXTERNAL_ADAPTERS = {}
FUNDING_ADAPTERS = {}
SUPPLIER_CLIENTS = {}
SUPPLIER_CLIENT_FACTORY = None


@dataclass(frozen=True, slots=True)
class JobContext:
    now: datetime
    limit: int
    cursor: object = None


def configure_payment(*, qris=None, external=None, funding=None):
    """Explicit test/operator composition; replacing is atomic at module scope."""
    if qris is not None:
        PAYMENT_ADAPTERS.clear()
        PAYMENT_ADAPTERS.update(qris)
    if external is not None:
        EXTERNAL_ADAPTERS.clear()
        EXTERNAL_ADAPTERS.update(external)
    if funding is not None:
        FUNDING_ADAPTERS.clear()
        FUNDING_ADAPTERS.update(funding)


def configure_suppliers(*, clients=None, client_factory=None):
    if clients is not None:
        SUPPLIER_CLIENTS.clear()
        SUPPLIER_CLIENTS.update(clients)
    if client_factory is not None:
        global SUPPLIER_CLIENT_FACTORY
        SUPPLIER_CLIENT_FACTORY = client_factory


def _deferred(reason):
    return {"status": "deferred", "reason": reason}


def _now_jakarta():
    return datetime.now(JAKARTA).replace(tzinfo=None)


def _db():
    from lhie_candidate.checkout.db import Database
    return Database()


def _cipher():
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.runtime import application_key
    return LaravelEncrypter(application_key())


def _admin_chat(db=None):
    import re
    row = (db or _db()).one("""SELECT admin_telegram_chat_id FROM public.manual_payment_settings
        WHERE is_active=true ORDER BY id LIMIT 1""")
    chat = str((row or {}).get("admin_telegram_chat_id") or "").strip()
    return chat if re.fullmatch(r"[1-9][0-9]{0,18}", chat) else None


def _count(cursor, sql, params=()):
    cursor.execute(sql, params)
    return int(cursor.rowcount)


def _page_ids(sql, params, cursor_value, limit, *, cursor_column="id"):
    db = _db()
    if cursor_value is None:
        return db.rows(f"{sql} ORDER BY {cursor_column} LIMIT %s", (*params, limit))
    return db.rows(f"{sql} AND {cursor_column} > %s ORDER BY {cursor_column} LIMIT %s",
                   (*params, cursor_value, limit))


def _next_cursor(rows, limit):
    return rows[-1]["id"] if len(rows) == limit else None


# --- suppliers ----------------------------------------------------------


def suppliers_sync(ctx):
    if not SUPPLIER_CLIENTS:
        return _deferred("supplier_transport_unavailable")
    try:
        cipher = _cipher()
    except Exception:
        return _deferred("key_unavailable")
    from lhie_candidate.suppliers.catalog import sync
    from lhie_candidate.suppliers.pricing import PROVIDERS
    db = _db()
    rows = _page_ids("""SELECT id FROM public.suppliers WHERE is_active
        AND provider=ANY(%s)""", (list(PROVIDERS),), ctx.cursor, ctx.limit)
    synced = failed = 0
    for row in rows:
        client = SUPPLIER_CLIENTS.get(row["id"])
        if client is None:
            failed += 1
            continue
        try:
            sync(row["id"], client, ctx.now.replace(tzinfo=None), encrypter=cipher)
            synced += 1
        except Exception:
            failed += 1
    return {"status": "partial" if failed else "success",
            "synced": synced, "failed": failed, "next_cursor": _next_cursor(rows, ctx.limit)}


def suppliers_recover(ctx):
    if SUPPLIER_CLIENT_FACTORY is None:
        return _deferred("supplier_transport_unavailable")
    try:
        cipher = _cipher()
    except Exception:
        return _deferred("key_unavailable")
    from lhie_candidate.suppliers.acquisition import SupplierAcquisition
    acquisition = SupplierAcquisition(cipher, SUPPLIER_CLIENT_FACTORY)
    due = acquisition.due_ids(min(ctx.limit, 100))
    processed = failed = 0
    for fulfillment_id in due:
        try:
            acquisition.process(fulfillment_id)
            processed += 1
        except Exception:
            failed += 1
    return {"status": "partial" if failed else "success",
            "due": len(due), "processed": processed, "failed": failed}


# --- USDT funding/balance ----------------------------------------------


def usdt_funding_recover(ctx):
    if not FUNDING_ADAPTERS:
        return _deferred("custody_adapter_unavailable")
    from lhie_candidate.usdt.funding_settlement import FundingSettlement
    service = FundingSettlement(binance=FUNDING_ADAPTERS.get("binance_pay"),
                                bsc=FUNDING_ADAPTERS.get("bsc_usdt"))
    return {"recovered": service.recover(min(ctx.limit, 100))}


def usdt_funding_release_expired(ctx):
    from lhie_candidate.usdt.funding import FundingService
    return {"released": FundingService().release_expired(min(ctx.limit, 1000))}


def usdt_balance_reconcile(ctx):
    from lhie_candidate.usdt.reconciliation import UsdtReconciliation
    recon = UsdtReconciliation()
    cursor = None
    if ctx.cursor is not None:
        try:
            cursor = int(ctx.cursor)
        except (TypeError, ValueError):
            return _deferred("invalid_cursor")
    page = recon.inspect(after_account_id=cursor, limit=min(ctx.limit, 1000))
    frozen = 0
    for finding in page["findings"]:
        with transaction.atomic():
            if recon.freeze_drift(finding["account_id"]) == "frozen":
                frozen += 1
    return {"accounts_inspected": page["accounts_inspected"],
            "findings": len(page["findings"]), "frozen": frozen,
            "next_cursor": page["next_cursor"]}


def usdt_balance_aggregate_accounting(ctx):
    from lhie_candidate.usdt.reconciliation import UsdtAccountingAggregator
    aggregator = UsdtAccountingAggregator()
    with transaction.atomic():
        processed = aggregator.catch_up(limit=min(ctx.limit, 1000))
    return {"processed": processed}


def usdt_balance_metrics(ctx):
    metrics = BalanceMetrics()
    report = metrics.report(60)
    pruned = metrics.prune_metric_events(30, chunk=min(max(ctx.limit, 500), 5000),
                                         max_batches=10)
    return {"pruned": pruned["deleted"], "prune_complete": pruned["complete"],
            "backlog": {name: facet.get("pending", facet.get("open", facet.get("unresolved", 0)))
                        for name, facet in report["backlog"].items()},
            "frozen_accounts": report["drift"]["frozen_accounts"],
            "unresolved_receipts": report["conflict"]["review_receipts"]}


def store_balance_expire_topups(ctx):
    from lhie_candidate.funding.service import FundingService
    service = FundingService()
    db = _db()
    rows = db.rows("""SELECT id FROM public.store_balance_top_ups
        WHERE status IN ('pending','payable','awaiting_confirmation')
          AND (expires_at <= %s OR provider_expires_at <= %s)
        ORDER BY id LIMIT %s""", (_now_jakarta(), _now_jakarta(), min(ctx.limit, 500)))
    expired = failed = 0
    for row in rows:
        try:
            expired += bool(service.expire_if_due(row["id"]))
        except Exception:
            failed += 1
    return {"status": "partial" if failed else "success",
            "examined": len(rows), "expired": expired, "failed": failed}


# --- provider reconcilers (explicitly capability-gated) ----------------


def _payment_page(provider, ctx, *, states=None, method=None):
    clauses = ["p.provider=%s", "p.status NOT IN ('paid','refunded')", "o.order_status='pending_payment'",
               "o.payment_status='pending'"]
    params = [provider]
    if states:
        clauses.append("p.provider_create_state = ANY(%s)")
        params.append(list(states))
    if method:
        clauses.append("p.method=%s")
        params.append(method)
    sql = "SELECT p.id FROM public.payments p JOIN public.orders o ON o.id=p.order_id WHERE " + \
          " AND ".join(clauses)
    rows = _page_ids(sql, tuple(params), ctx.cursor, min(ctx.limit, 100), cursor_column="p.id")
    return rows


def _qris_reconcile(ctx, provider):
    if provider not in PAYMENT_ADAPTERS:
        return _deferred("provider_adapter_unavailable")
    from lhie_candidate.payments.runtime import payment_services
    kwargs = {provider: PAYMENT_ADAPTERS[provider]}
    payments = payment_services(**kwargs)
    rows = _payment_page(provider, ctx)
    recovered = failed = 0
    for row in rows:
        try:
            payments.recover_background(row["id"])
            recovered += 1
        except Exception:
            failed += 1
    return {"status": "partial" if failed else "success",
            "examined": len(rows), "recovered": recovered, "failed": failed,
            "next_cursor": _next_cursor(rows, min(ctx.limit, 100))}


def klikqris_reconcile(ctx):
    return _qris_reconcile(ctx, "klikqris")


def sakurupiah_reconcile(ctx):
    return _qris_reconcile(ctx, "sakurupiah")


def pakasir_reconcile(ctx):
    return _qris_reconcile(ctx, "pakasir")


def klikqris_backfill_in_flight(ctx):
    if "klikqris" not in PAYMENT_ADAPTERS:
        return _deferred("provider_adapter_unavailable")
    from lhie_candidate.payments.runtime import payment_services
    payments = payment_services(klikqris=PAYMENT_ADAPTERS["klikqris"])
    rows = _payment_page("klikqris", ctx, states=("creating", "outcome_unknown", "recovery_pending",
                                                  "provider_total_pending"))
    recovered = failed = 0
    for row in rows:
        try:
            payments.recover_background(row["id"])
            recovered += 1
        except Exception:
            failed += 1
    return {"status": "partial" if failed else "success",
            "examined": len(rows), "recovered": recovered, "failed": failed,
            "next_cursor": _next_cursor(rows, min(ctx.limit, 100))}


def _external_reconcile(ctx, method, key):
    if method not in EXTERNAL_ADAPTERS:
        return _deferred("provider_adapter_unavailable")
    from lhie_candidate.payments.runtime import payment_services
    payments = payment_services(**{key: EXTERNAL_ADAPTERS[method]})
    lifecycle = payments.external_lifecycles.get(method)
    if lifecycle is None:
        return _deferred("provider_adapter_unavailable")
    rows = _payment_page("manual", ctx, method=method)
    recovered = failed = 0
    for row in rows:
        try:
            lifecycle.recover(row["id"])
            recovered += 1
        except Exception:
            failed += 1
    return {"status": "partial" if failed else "success",
            "examined": len(rows), "recovered": recovered, "failed": failed,
            "next_cursor": _next_cursor(rows, min(ctx.limit, 100))}


def binance_reconcile(ctx):
    return _external_reconcile(ctx, "BINANCE_PAY_ID", "binance")


def bsc_reconcile(ctx):
    return _external_reconcile(ctx, "USDT_BEP20", "bsc")


def _funding_reconcile(ctx, provider):
    if provider not in FUNDING_ADAPTERS:
        return _deferred("provider_adapter_unavailable")
    from lhie_candidate.funding.klikqris import KlikFunding
    from lhie_candidate.funding.service import FundingService
    try:
        cipher = _cipher()
    except Exception:
        return _deferred("key_unavailable")
    funding = FundingService(provider=provider, encrypter=cipher)
    if provider == "klikqris":
        service = KlikFunding(funding, FUNDING_ADAPTERS[provider], cipher)
        recover = service.recover
    else:
        recover = lambda topup_id: funding.check(funding.top_up_by_id(topup_id))  # noqa: E731
    db = _db()
    rows = _page_ids("""SELECT id FROM public.store_balance_top_ups
        WHERE provider=%s AND status IN ('pending','payable','expired','awaiting_confirmation')
          AND provider_create_attempted_at IS NOT NULL""", (provider,), ctx.cursor, min(ctx.limit, 100))
    recovered = failed = 0
    for row in rows:
        try:
            recover(row["id"])
            recovered += 1
        except Exception:
            failed += 1
    return {"status": "partial" if failed else "success",
            "examined": len(rows), "recovered": recovered, "failed": failed,
            "next_cursor": _next_cursor(rows, min(ctx.limit, 100))}


def klikqris_funding_reconcile(ctx):
    return _funding_reconcile(ctx, "klikqris")


def sakurupiah_funding_reconcile(ctx):
    return _funding_reconcile(ctx, "sakurupiah")


# --- orders -------------------------------------------------------------


def orders_expire_pending(ctx):
    from lhie_candidate.checkout.lifecycle import OrderLifecycle
    lifecycle = OrderLifecycle()
    db = _db()
    rows = db.rows("""SELECT id FROM public.orders
        WHERE order_status='pending_payment' AND payment_status='pending'
          AND expires_at IS NOT NULL AND expires_at <= %s
          AND NOT EXISTS (SELECT 1 FROM public.manual_fulfillment_reviews r
              WHERE r.order_id=orders.id AND r.state IN ('pending','refund_uncertain'))
        ORDER BY id LIMIT %s""", (_now_jakarta(), min(ctx.limit, 500)))
    expired = skipped = failed = 0
    for row in rows:
        try:
            result = lifecycle.apply_pending_expiry(row["id"])
            if result.newly_applied:
                expired += 1
            else:
                skipped += 1
        except Exception:
            failed += 1
    return {"status": "partial" if failed else "success",
            "examined": len(rows), "expired": expired, "skipped": skipped, "failed": failed}


def orders_recover_local_completion(ctx):
    from lhie_candidate.checkout.lifecycle import OrderLifecycle
    cursor = None
    if ctx.cursor is not None:
        cursor = str(ctx.cursor)
        try:
            from uuid import UUID
            UUID(cursor)
        except (TypeError, ValueError):
            return _deferred("invalid_cursor")
    return OrderLifecycle().recover_local_completion(min(ctx.limit, 1000), after_order_id=cursor)


def orders_recover_credential_mails(ctx):
    try:
        from lhie_candidate.workers.services import mail_services
        handler = mail_services()
    except Exception:
        return _deferred("key_or_recipient_unavailable")
    service = handler.services.get("credential.mail")
    if service is None or not service.recipients.telegram:
        return _deferred("mail_recipient_unavailable")
    try:
        return {"outcomes": service.recover(min(ctx.limit, 100))}
    except Exception:
        return {"status": "failed", "error": "credential_mail_recovery_failed"}


# --- Telegram / waitlist / notifications -------------------------------


def telegram_recover_updates(ctx):
    try:
        from lhie_candidate.workers.services import interaction_handler
        handler = interaction_handler()
    except Exception:
        return _deferred("key_or_transport_unavailable")
    try:
        return handler.sweep(min(ctx.limit, 100))
    except Exception:
        return {"status": "failed", "error": "update_recovery_failed"}


def telegram_forward_payment_proofs(ctx):
    try:
        from lhie_candidate.notifications.service import Notifications
        from lhie_candidate.notifications.runtime import notification_service
        from lhie_candidate.notifications.policy import MANUAL
        service = notification_service(effect_types=(MANUAL,), queue="notifications")
    except Exception:
        return _deferred("key_or_recipient_unavailable")
    if not service.recipients.telegram:
        return _deferred("telegram_recipient_unavailable")
    return service.recover(min(ctx.limit, 100))


def telegram_reconcile_stock_notifications(ctx):
    try:
        from lhie_candidate.notifications.runtime import notification_service
        from lhie_candidate.notifications.policy import STOCK
        service = notification_service(effect_types=(STOCK,), queue="bulk")
    except Exception:
        return _deferred("key_or_recipient_unavailable")
    if not service.recipients.telegram:
        return _deferred("telegram_recipient_unavailable")
    return service.recover(min(ctx.limit, 100))


def waitlist_reconcile(ctx):
    try:
        from lhie_candidate.workers.services import mail_services
        handler = mail_services()
    except Exception:
        return _deferred("key_or_recipient_unavailable")
    service = handler.services.get("waitlist.mail")
    if service is None or not service.enabled:
        return _deferred("waitlist_mail_disabled")
    return service.recover(min(ctx.limit, 100))


def suppliers_notify_health(ctx):
    try:
        from lhie_candidate.notifications.runtime import notification_service
        from lhie_candidate.notifications.operations import Operations, SUPPLIER
        service = notification_service(effect_types=(SUPPLIER,), queue="notifications")
    except Exception:
        return _deferred("key_or_recipient_unavailable")
    if not service.recipients.telegram:
        return _deferred("telegram_recipient_unavailable")
    return {"materialized": Operations(service).materialize(min(ctx.limit, 100))}


def customer_auth_recover_mail(ctx):
    try:
        from lhie_candidate.workers.services import auth_mail_service
        service = auth_mail_service()
    except Exception:
        return _deferred("key_or_recipient_unavailable")
    if not getattr(service, "service", None) or not getattr(service.service, "recipients", None):
        return _deferred("auth_mail_disabled")
    try:
        return {"recovered": service.sweep(min(ctx.limit, 100))}
    except Exception:
        return {"status": "failed", "error": "auth_mail_recovery_failed"}


def customer_api_flush_usage(ctx):
    try:
        from lhie_candidate.customer_api.admission import Admission
        from lhie_candidate.customer_api.usage import flush
        admission = Admission()
    except Exception:
        return _deferred("key_or_redis_unavailable")
    try:
        return {"processed": flush(admission, limit=min(ctx.limit, 500))}
    except Exception:
        return {"status": "failed", "error": "usage_flush_failed"}


def notifications_daily_admin_stats(ctx):
    try:
        from lhie_candidate.notifications.runtime import notification_service
        from lhie_candidate.notifications.operations import Operations, DAILY
        service = notification_service(effect_types=(DAILY,), queue="notifications")
    except Exception:
        return _deferred("key_or_recipient_unavailable")
    if not service.recipients.telegram:
        return _deferred("telegram_recipient_unavailable")
    return {"materialized": Operations(service).materialize(min(ctx.limit, 25))}


# --- cleanup, metrics, reports, receipts -------------------------------


def ops_cleanup_retained_data(ctx):
    after = 0 if ctx.cursor is None else ctx.cursor
    return cleanup_retained_data(90, chunk=min(ctx.limit, 5000), max_batches=10,
                                 after=after)


def customer_auth_cleanup(ctx):
    return cleanup_auth_challenges(min(ctx.limit, 1000))


def ops_balance_alerts(ctx):
    from lhie_candidate.usdt.operations_controls import OperationalControls
    chat = _admin_chat()
    if chat is None:
        return _deferred("admin_recipient_unavailable")
    signals = OperationalControls().evaluate(admin_chat_id=chat)
    return {"active": sum(1 for value in signals.values() if value),
            "signals": sorted(key for key, value in signals.items() if value)}


def ops_payment_lifecycle_rollout_report(ctx):
    db = _db()
    uncertain = {"creating", "outcome_unknown", "provider_total_pending", "financial_integrity_failed"}
    unresolved = {}
    row = db.one("""SELECT count(*) AS aggregate FROM public.payments
        WHERE status='pending' AND provider_create_state=ANY(%s)""", (list(uncertain),))
    unresolved["provider_uncertainty"] = int(row["aggregate"])
    row = db.one("""SELECT count(*) AS aggregate FROM public.payments
        WHERE status='pending' AND expired_at IS NOT NULL AND expired_at <= %s""", (_now_jakarta(),))
    unresolved["stuck_pending_payments"] = int(row["aggregate"])
    row = db.one("""SELECT count(*) AS aggregate FROM public.manual_fulfillment_reviews
        WHERE state IN ('pending','refund_uncertain')""")
    unresolved["late_payment_queue"] = int(row["aggregate"])
    row = db.one("""SELECT count(*) AS aggregate FROM public.provider_cancellation_attempts
        WHERE state IN ('pending','processing','retryable_failure')""")
    unresolved["cancellation_attempts"] = int(row["aggregate"])
    row = db.one("""SELECT count(*) AS aggregate FROM public.payments p
        JOIN public.orders o ON o.id=p.order_id WHERE o.payment_status <> p.status""")
    unresolved["lifecycle_state_mismatches"] = int(row["aggregate"])
    notification_claims = {
        "stock_expired": int((db.one("""SELECT count(*) AS aggregate
            FROM public.telegram_outbound_effects WHERE state='sending'
            AND lease_until IS NOT NULL AND lease_until <= %s""", (_now_jakarta(),)) or {}).get("aggregate", 0)),
        "broadcast_stale": 0,
        "legacy_stock_unmaterialized": int((db.one("""SELECT count(*) AS aggregate
            FROM public.stock_notification_recipients r
            WHERE NOT EXISTS (SELECT 1 FROM public.telegram_outbound_effects e
                WHERE e.source_type='App\\Models\\StockNotificationRecipient'
                  AND e.source_id=CAST(r.id AS text))""") or {}).get("aggregate", 0)),
    }
    unresolved["notification_claims"] = notification_claims
    total = (unresolved["provider_uncertainty"] + unresolved["stuck_pending_payments"]
             + unresolved["late_payment_queue"] + unresolved["cancellation_attempts"]
             + unresolved["lifecycle_state_mismatches"] + sum(notification_claims.values()))
    return {"generated_at": datetime.now(JAKARTA).isoformat(),
            "rollout": {"mode": "enabled", "canary_percentage": 0},
            "unresolved": unresolved, "contract_safe": total == 0,
            "unresolved_total": total}


def receipt_recover(ctx):
    try:
        from lhie_candidate.receipt.runtime import receipt_service
        service = receipt_service()
    except Exception:
        return _deferred("key_unavailable")
    try:
        summary = service.recover(min(ctx.limit, 1000))
        if not isinstance(summary, dict):
            raise ValueError("Invalid recovery summary")
        failed = summary.get("source_failures", 0)
        if type(failed) is not int or failed < 0:
            raise ValueError("Invalid recovery failure count")
        delivered = 0
        while delivered < ctx.limit:
            due = service.protocol.ready_effects(ctx.limit - delivered)
            if not due:
                break
            progressed = 0
            for effect in due:
                try:
                    outcome = service.deliver(effect["id"])
                except Exception:
                    failed += 1
                    break
                if outcome in ("sent", "unknown", "failed"):
                    progressed += 1
                if outcome == "failed":
                    failed += 1
            delivered += progressed
            if failed or progressed == 0:
                break
        summary["delivered"] = delivered
        if failed:
            summary["status"] = "partial"
            scheduler_runtime("outbound-recovery", "failed", "recovery_exit")
        else:
            summary["status"] = "success"
            scheduler_runtime("outbound-recovery", "recovered")
        return summary
    except Exception:
        try:
            scheduler_runtime("outbound-recovery", "failed", "recovery_exit")
        except Exception:
            pass
        return {"status": "failed", "error": "outbound_recovery_failed"}


def assert_database_queue_empty(ctx):
    from .health import assert_database_queue_empty as _assert
    return _assert()


def scheduler_runtime_alert(ctx):
    return scheduler_runtime(*ctx.cursor) if isinstance(ctx.cursor, (list, tuple)) else _deferred("arguments")
