"""P26 disposable PostgreSQL proof for leases, history and retention safety."""
from datetime import datetime, timedelta, timezone
import json
import os
from urllib.parse import unquote, urlsplit
from uuid import uuid4


def _configure_database(dsn):
    import django
    from django.conf import settings
    parsed = urlsplit(dsn)
    if parsed.hostname not in ("127.0.0.1", "localhost", "::1"):
        raise RuntimeError("P26 accepts only loopback PostgreSQL")
    database = {
        "ENGINE": "django.db.backends.postgresql",
        "NAME": unquote(parsed.path.lstrip("/")),
        "USER": unquote(parsed.username or ""),
        "PASSWORD": unquote(parsed.password or ""),
        "HOST": parsed.hostname,
        "PORT": str(parsed.port or 5432),
        "CONN_MAX_AGE": 0,
    }
    if not settings.configured:
        settings.configure(
            SECRET_KEY="p26-disposable-only",
            INSTALLED_APPS=[],
            DATABASES={"default": database, "worker": dict(database)},
            DEFAULT_AUTO_FIELD="django.db.models.BigAutoField",
            TIME_ZONE="Asia/Jakarta",
            USE_TZ=True,
        )
        django.setup()


def exercise_operations(dsn):
    if os.environ.get("P26_OPERATIONS_SMOKE") != "disposable-local-only":
        raise RuntimeError("Explicit P26 disposable PostgreSQL admission required")
    parsed = urlsplit(dsn)
    database_name = unquote(parsed.path.lstrip("/"))
    if (parsed.hostname not in ("127.0.0.1", "::1", "localhost")
            or not database_name.startswith("p26_") or (parsed.username or "") != "p26_admin"):
        raise RuntimeError("P26 smoke accepts only its named synthetic database")
    _configure_database(dsn)

    import psycopg
    from django.db import connections
    from lhie_candidate.operations.health import check_failed_jobs, release_health_summary
    from lhie_candidate.operations.retention import cleanup_retained_data
    from lhie_candidate.operations.state import OperationStore

    with psycopg.connect(dsn, autocommit=True) as admin:
        identity = admin.execute(
            "SELECT current_database(),current_user,host(inet_server_addr())").fetchone()
        if identity[:2] != (database_name, "p26_admin") or identity[2] is None:
            raise RuntimeError("P26 PostgreSQL identity mismatch")

        customer_id = admin.execute("""INSERT INTO public.customers
            (first_name,created_at,updated_at) VALUES ('P26 synthetic',now(),now())
            RETURNING id""").fetchone()[0]
        account_id = admin.execute("""INSERT INTO public.customer_balance_accounts
            (customer_id,balance,created_at,updated_at) VALUES (%s,49,now(),now())
            RETURNING id""", (customer_id,)).fetchone()[0]
        entry_id = admin.execute("""INSERT INTO public.store_balance_entries
            (customer_balance_account_id,kind,amount,balance_after,source_type,source_id,
             idempotency_key,created_at)
            VALUES (%s,'purchase_debit',1,49,'order','p26-synthetic','p26-synthetic-entry',now())
            RETURNING id""", (account_id,)).fetchone()[0]
        event_id = admin.execute("""INSERT INTO public.payment_events
            (provider,event_type,raw_payload,received_at)
            VALUES ('p26-synthetic','callback',%s::jsonb,now()-interval '200 days')
            RETURNING id""", (json.dumps({"sentinel": "p26-payment-event"}),)).fetchone()[0]
        effect_id = uuid4()
        admin.execute("""INSERT INTO public.telegram_outbound_effects
            (id,effect_key,effect_type,source_type,source_id,telegram_chat_id,
             telegram_method,state,unknown_at,last_error_code,created_at,updated_at)
            VALUES (%s,%s,'p26-test','P26Synthetic','unknown-delivery','1',
                    'sendMessage','unknown',now(),'delivery_uncertain',now(),now())""",
            (effect_id, "p26-unknown:" + str(effect_id)))
        update_id = admin.execute("""INSERT INTO public.telegram_updates
            (update_id,payload,processed_at,created_at)
            VALUES (269991123,%s::jsonb,now()-interval '200 days',now()-interval '200 days')
            RETURNING id""", (json.dumps({"private": "p26-update-payload"}),)).fetchone()[0]

        money_before = admin.execute("""SELECT id,customer_balance_account_id,kind,amount,
            balance_after,source_type,source_id,idempotency_key FROM public.store_balance_entries
            WHERE id=%s""", (entry_id,)).fetchone()
        event_before = admin.execute(
            "SELECT provider,event_type,raw_payload,processed_at FROM public.payment_events WHERE id=%s",
            (event_id,)).fetchone()
        effect_before = admin.execute(
            "SELECT state,unknown_at,last_error_code FROM public.telegram_outbound_effects WHERE id=%s",
            (effect_id,)).fetchone()

    cleanup = cleanup_retained_data(days=90, chunk=1, max_batches=10)
    assert cleanup["telegram_updates"]["redacted"] == 1, cleanup
    assert cleanup["complete"] and cleanup["financial_history_untouched"], cleanup
    with psycopg.connect(dsn) as admin:
        update = admin.execute("""SELECT update_id,payload,processed_at,customer_id
            FROM public.telegram_updates WHERE id=%s""", (update_id,)).fetchone()
        assert update[0] == 269991123 and update[1] == {}, update
        assert update[2] is not None and update[3] is None, update
        money_after = admin.execute("""SELECT id,customer_balance_account_id,kind,amount,
            balance_after,source_type,source_id,idempotency_key FROM public.store_balance_entries
            WHERE id=%s""", (entry_id,)).fetchone()
        event_after = admin.execute(
            "SELECT provider,event_type,raw_payload,processed_at FROM public.payment_events WHERE id=%s",
            (event_id,)).fetchone()
        effect_after = admin.execute(
            "SELECT state,unknown_at,last_error_code FROM public.telegram_outbound_effects WHERE id=%s",
            (effect_id,)).fetchone()
        assert money_after == money_before, (money_before, money_after)
        assert event_after == event_before, (event_before, event_after)
        assert effect_after == effect_before and effect_after[0] == "unknown", effect_after

    store, worker = OperationStore("default"), OperationStore("worker")
    assert store.claim_cycle()
    assert not worker.claim_cycle()
    store.release_cycle()
    assert worker.claim_cycle()
    worker.release_cycle()

    now = datetime.now(timezone.utc)
    lease = store.claim("p26:crash-recovery", 1, now, 4)
    assert lease is not None
    connections["default"].close()  # A dead process releases its advisory lock, not its lease row.
    assert worker.claim("p26:crash-recovery", 2, now + timedelta(seconds=2), 4) is None
    recovered = worker.claim("p26:crash-recovery", 2, now + timedelta(seconds=5), 4)
    assert recovered is not None
    worker.finish(recovered, outcome="partial", summary={"failed": 1},
                  cursor_value=update_id, cursor_set=True, error_code="job_partial",
                  now=now + timedelta(seconds=6), duration_ms=12)
    state = store.snapshot(["p26:crash-recovery"])["p26:crash-recovery"]
    assert state["lease_owner"] is None and state["cursor"] == update_id, state
    assert state["last_outcome"] == "partial" and state["consecutive_failures"] == 1, state
    assert store.recent_runs("p26:crash-recovery")[0]["outcome"] == "partial"
    failure_check = check_failed_jobs(0)
    assert failure_check["status"] == "failed" and failure_check["count_capped"], failure_check
    release = release_health_summary()
    assert release["status"] == "failed" and release["failed_jobs"]["last_24h"] >= 1, release

    retry = store.claim("p26:crash-recovery", 3, now + timedelta(seconds=7), 4)
    assert retry is not None
    store.finish(retry, outcome="success", summary={"failed": 0},
                 cursor_value=None, cursor_set=True,
                 now=now + timedelta(seconds=8), duration_ms=4)
    healed = worker.snapshot(["p26:crash-recovery"])["p26:crash-recovery"]
    assert healed["cursor"] is None and healed["consecutive_failures"] == 0, healed
    assert healed["last_failure_code"] is None, healed
    connections.close_all()
    return {"status": "passed", "financial_ledger_preserved": True,
            "payment_event_preserved": True, "unknown_delivery_preserved": True,
            "update_replay_tombstone_retained": True, "lease_crash_recovery": True,
            "cursor_clear_and_failure_health": True}


if __name__ == "__main__":
    print(json.dumps(exercise_operations(os.environ["P26_OPERATIONS_DSN"]), sort_keys=True))
