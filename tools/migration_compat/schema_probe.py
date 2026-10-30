"""Executed only inside the P01 quarantine namespace against generated schema."""
import json
import os
import sys
from datetime import UTC, datetime
from decimal import Decimal

sys.path.insert(0, "/opt/candidate")


def main():
    if os.environ.get("P03_SCHEMA_SMOKE") != "disposable-local-only":
        raise RuntimeError("Explicit disposable opt-in required")
    import psycopg
    from psycopg import errors
    owner = psycopg.connect(host="127.0.0.1", hostaddr="127.0.0.1", dbname="disposable",
                           user="harness", password="harness", autocommit=True, connect_timeout=3)
    assert owner.execute("SELECT current_database(), current_user, host(inet_server_addr())").fetchone() == (
        "disposable", "harness", "127.0.0.1")
    owner.execute("SET statement_timeout = '5s'")
    role = "p03_compat_runtime"
    owner.execute(f"CREATE ROLE {role} LOGIN PASSWORD 'synthetic-local-only' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT CONNECTION LIMIT 2")
    assert owner.execute(
        "SELECT rolsuper,rolcreatedb,rolcreaterole,rolinherit,rolconnlimit FROM pg_roles WHERE rolname=%s",
        (role,)).fetchone() == (False, False, False, False, 2)
    owner.execute(f"GRANT CONNECT ON DATABASE disposable TO {role}")
    owner.execute(f"GRANT USAGE ON SCHEMA public TO {role}")
    owner.execute(f"GRANT SELECT, INSERT ON customers, customer_identities TO {role}")
    # The canonical Telegram trigger locks its Customer FOR UPDATE. PostgreSQL
    # requires UPDATE on at least one column even when no value is changed.
    owner.execute(f"GRANT UPDATE(updated_at) ON customers TO {role}")
    owner.execute(f"GRANT SELECT ON customer_usdt_balance_accounts, bsc_payment_transfer_evidence TO {role}")
    owner.execute(f"GRANT USAGE, SELECT ON SEQUENCE customers_id_seq, customer_identities_id_seq TO {role}")

    from django.conf import settings
    settings.configure(INSTALLED_APPS=[], USE_TZ=True, TIME_ZONE="UTC",
                       DATABASE_ROUTERS=["lhie_candidate.metadata.MetadataRouter"],
                       DATABASES={"default": {"ENGINE": "django.db.backends.postgresql", "NAME": "disposable",
                           "HOST": "127.0.0.1", "PORT": "5432", "USER": role,
                           "PASSWORD": "synthetic-local-only", "OPTIONS": {"options": "-c statement_timeout=5000"}}})
    import django
    django.setup()
    from django.db import connection, transaction, IntegrityError, ProgrammingError
    from django.db.migrations.operations.models import CreateModel
    from django.db.migrations.state import ModelState, ProjectState
    from lhie_candidate.legacy import (LegacyCustomer, LegacyCustomerIdentity, LegacyUsdtAccount,
                                      LegacyBscTransferEvidence)
    import bcrypt
    now = datetime(2026, 10, 30, 1, 2, 3, tzinfo=UTC)
    large_id = 9007199254740993
    password_hash = bcrypt.hashpw(b"p03-synthetic-password", bcrypt.gensalt(rounds=4)).decode()
    customer = LegacyCustomer.objects.create(id=large_id, telegram_id=large_id, first_name="P03 synthetic",
                                            password=password_hash, created_at=now, updated_at=now)
    customer.refresh_from_db()
    assert (customer.id, customer.telegram_id, customer.auth_version, customer.enrollment_version,
            customer.password_scheme, customer.email, customer.created_at) == (large_id, large_id, 0, 0, "raw", None, now)
    assert customer.password == password_hash
    LegacyCustomerIdentity.objects.create(customer_id=large_id, provider="telegram", provider_subject=str(large_id),
                                          created_at=now, updated_at=now)
    # Explicit IDs must not silently reseed Laravel's sequence.
    assert owner.execute("SELECT last_value, is_called FROM customers_id_seq").fetchone() == (1, False)
    owner.execute("SELECT setval('customers_id_seq', %s, true)", (large_id,))
    second = LegacyCustomer.objects.create(first_name="P03 second", created_at=now, updated_at=now)
    assert second.id == large_id + 1 and second.telegram_id is None
    assert owner.execute("SELECT created_at FROM customers WHERE id=%s", (large_id,)).fetchone()[0] == datetime(2026, 10, 30, 8, 2, 3)

    def rejects(operation, exception=IntegrityError):
        try:
            with transaction.atomic():
                operation()
        except exception:
            return
        raise AssertionError("Expected database rejection")

    rejects(lambda: LegacyCustomerIdentity.objects.create(customer_id=second.id, provider="telegram", provider_subject="777"))
    rejects(lambda: LegacyCustomerIdentity.objects.create(customer_id=large_id, provider="telegram", provider_subject=str(large_id)))
    rejects(lambda: LegacyCustomerIdentity.objects.create(customer_id=large_id, provider="unsupported", provider_subject="x"))
    rejects(lambda: LegacyCustomerIdentity.objects.create(customer_id=42, provider="google", provider_subject="missing"))
    rejects(lambda: LegacyCustomer.objects.filter(pk=large_id).update(first_name="denied"), ProgrammingError)
    rejects(lambda: LegacyCustomer.objects.filter(pk=large_id).delete(), ProgrammingError)
    for statement in ("CREATE TABLE public.p03_forbidden (id int)",
                      "ALTER TABLE public.customers ADD COLUMN p03_forbidden int",
                      "UPDATE customer_usdt_balance_accounts SET balance_micro=1",
                      "UPDATE customer_usdt_balance_entries SET amount_micro=1",
                      "SELECT setval('customers_id_seq', 1)",
                      "SET ROLE harness"):
        def forbidden(statement=statement):
            with connection.cursor() as cursor:
                cursor.execute(statement)
        rejects(forbidden, ProgrammingError)
    # A consumer-visible failed second write must roll back its Customer as well.
    before = LegacyCustomer.objects.count()
    def broken_pair():
        pending = LegacyCustomer.objects.create(first_name="must rollback")
        LegacyCustomerIdentity.objects.create(customer_id=pending.id, provider="telegram", provider_subject="777")
    rejects(broken_pair)
    assert LegacyCustomer.objects.count() == before

    # Seed initial fixtures, never repair or update an existing projection.
    owner.execute("INSERT INTO customer_usdt_balance_accounts(customer_id) VALUES (%s)", (large_id,))
    account = LegacyUsdtAccount.objects.get(customer_id=large_id)
    assert (account.currency, account.balance_micro, account.version) == ("USDT", 0, 0)
    with owner.transaction():
        account_id = owner.execute(
            "INSERT INTO customer_usdt_balance_accounts(customer_id,balance_micro,version,last_entry_id) "
            "VALUES (%s,%s,1,1) RETURNING id", (second.id, large_id)).fetchone()[0]
        owner.execute(
            "INSERT INTO customer_usdt_balance_entries "
            "(id,customer_usdt_balance_account_id,account_sequence,kind,amount_micro,balance_after_micro,"
            "source_type,source_id,idempotency_key,request_fingerprint,created_at) "
            "VALUES (1,%s,1,'funding_credit',%s,%s,'p03_fixture','1','p03-fixture',%s,%s)",
            (account_id, large_id, large_id, "a" * 64, datetime(2026, 10, 30, 8, 2, 3)))
        owner.execute(
            "INSERT INTO customer_usdt_balance_accounting_events "
            "(customer_usdt_balance_entry_id,currency,amount_micro,debit_account,credit_account,source_fingerprint,created_at) "
            "VALUES (1,'USDT',%s,'settlement_asset','customer_liability',%s,%s)",
            (large_id, "a" * 64, datetime(2026, 10, 30, 8, 2, 3)))
    assert LegacyUsdtAccount.objects.get(customer_id=second.id).balance_micro == large_id
    assert owner.execute(
        "SELECT a.balance_micro=e.amount_micro AND a.balance_micro=e.balance_after_micro "
        "AND a.version=e.account_sequence AND a.last_entry_id=e.id AND v.amount_micro=e.amount_micro "
        "FROM customer_usdt_balance_accounts a JOIN customer_usdt_balance_entries e "
        "ON e.customer_usdt_balance_account_id=a.id JOIN customer_usdt_balance_accounting_events v "
        "ON v.customer_usdt_balance_entry_id=e.id WHERE a.customer_id=%s", (second.id,)).fetchone() == (True,)
    for table in ("customer_usdt_balance_entries", "customer_usdt_balance_accounting_events"):
        for verb in ("UPDATE", "DELETE"):
            statement = f"UPDATE {table} SET amount_micro=1" if verb == "UPDATE" else f"DELETE FROM {table}"
            try:
                with owner.transaction():
                    owner.execute(statement)
            except errors.RaiseException:
                pass
            else:
                raise AssertionError("Append-only history guard missing")
    for statement, parameters in (
        ("UPDATE customer_usdt_balance_accounts SET balance_micro=-1 WHERE customer_id=%s", (large_id,)),
        ("UPDATE customer_identities SET provider_subject='777' WHERE customer_id=%s", (large_id,)),
        ("UPDATE customers SET telegram_id=777 WHERE id=%s", (large_id,)),
        ("DELETE FROM customers WHERE id=%s", (large_id,)),
    ):
        try:
            with owner.transaction():
                owner.execute(statement, parameters)
        except errors.IntegrityError:
            pass
        else:
            raise AssertionError("Baseline guard missing")
    # Real PostgreSQL numeric adapter and ORM converter, beyond Decimal context 28.
    units = Decimal("9" * 78)
    row = list(LegacyBscTransferEvidence.objects.raw(
        "SELECT 1::bigint AS id, %s::numeric(78,0) AS amount_units", [units]))[0]
    assert row.amount_units == units and isinstance(row.amount_units, Decimal)
    field = LegacyBscTransferEvidence._meta.get_field("amount_units")
    assert field.get_db_prep_value(units, connection) == units
    for bad in (1.5, True, Decimal("0.1"), Decimal("1e78"), Decimal("NaN")):
        try:
            field.get_db_prep_value(bad, connection)
        except ValueError:
            pass
        else:
            raise AssertionError("Inexact units accepted")
    bigint = 9223372036854775807
    assert owner.execute("SELECT %s::bigint", (bigint,)).fetchone()[0] == bigint
    try:
        owner.execute("SELECT %s::bigint", (bigint + 1,))
    except errors.NumericValueOutOfRange:
        pass
    else:
        raise AssertionError("Bigint overflow accepted")

    # Exercise Django's actual migration operation gate, not just Meta flags.
    state = ProjectState()
    model_state = ModelState.from_model(LegacyCustomer)
    state.add_model(model_state)
    operation = CreateModel(name=model_state.name, fields=list(model_state.fields.items()), options=model_state.options)
    tables_before = owner.execute("SELECT count(*) FROM pg_class WHERE relnamespace='public'::regnamespace").fetchone()
    with connection.schema_editor() as editor:
        operation.database_forwards("legacy_compat", editor, ProjectState(), state)
    assert owner.execute("SELECT count(*) FROM pg_class WHERE relnamespace='public'::regnamespace").fetchone() == tables_before
    summary = {}
    for name, query in {
        "tables": "SELECT count(*) FROM pg_tables WHERE schemaname='public'",
        "constraints": "SELECT count(*) FROM pg_constraint WHERE connamespace='public'::regnamespace",
        "triggers": "SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid WHERE c.relnamespace='public'::regnamespace AND NOT t.tgisinternal",
        "indexes": "SELECT count(*) FROM pg_indexes WHERE schemaname='public'",
        "functions": "SELECT count(*) FROM pg_proc WHERE pronamespace='public'::regnamespace",
        "sequences": "SELECT count(*) FROM pg_sequences WHERE schemaname='public'",
    }.items():
        summary[name] = owner.execute(query).fetchone()[0]
    assert summary["triggers"] > 0
    connection.close()
    owner.close()
    print(json.dumps({"status": "passed", "catalog": summary,
                      "checks": ["bigint-above-js-precision", "bigint-overflow-rejected", "numeric-78-exact-no-floats",
                                 "database-defaults-nullability", "jakarta-wall-time-utc-roundtrip", "explicit-id-sequence-reseed",
                                 "enum-unique-fk-trigger-rejection", "least-privilege-no-ddl-update-delete-escalation",
                                 "atomic-rollback-writer", "unmanaged-create-operation-skipped", "hash-bytes-unchanged",
                                 "consistent-initial-ledger-accounting-snapshot", "owner-append-only-history-guards",
                                 "runtime-sequence-reseed-denied", "restricted-role-attributes"]}))


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        import traceback
        frames = [frame.lineno for frame in traceback.extract_tb(error.__traceback__) if frame.filename == __file__]
        print(json.dumps({"status": "failed", "error_type": type(error).__name__, "probe_lines": frames}), file=sys.stderr)
        raise SystemExit(1)
