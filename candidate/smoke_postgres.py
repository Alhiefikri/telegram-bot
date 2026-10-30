"""Disposable PostgreSQL mechanics, NOT purchase/ledger/stock business parity.

Run only in the admitted P01 candidate container namespace:
CANDIDATE_POSTGRES_SMOKE=disposable-local-only python smoke_postgres.py

Uses the migration-equivalent harness fixture role, not candidate_runtime. This
proves neither production privileges nor runtime-role isolation. No legacy table
is read or written; all DDL is limited to one generated, temporary schema.
"""

import json
import multiprocessing
import os
import queue
import re
import uuid


OPT_IN = "disposable-local-only"
WORKERS = 4
_SCHEMA = re.compile(r"p05_smoke_[0-9a-f]{32}", re.ASCII)


class SmokeFailure(RuntimeError):
    pass


def require_opt_in() -> None:
    if os.environ.get("CANDIDATE_POSTGRES_SMOKE") != OPT_IN:
        raise SmokeFailure("Disposable PostgreSQL smoke requires explicit local opt-in")


def _connect():
    import psycopg

    require_opt_in()
    connection = psycopg.connect(
        host="127.0.0.1",
        hostaddr="127.0.0.1",
        port=5432,
        dbname="disposable",
        user="harness",
        password="harness",
        connect_timeout=3,
        application_name="p05_bootstrap_smoke",
        autocommit=True,
        options=(
            "-c search_path=pg_catalog -c statement_timeout=10000 "
            "-c lock_timeout=5000 -c idle_in_transaction_session_timeout=10000"
        ),
    )
    try:
        identity = connection.execute(
            "SELECT current_database(), current_user, host(inet_server_addr()), inet_server_port()"
        ).fetchone()
        if identity != ("disposable", "harness", "127.0.0.1", 5432):
            raise SmokeFailure("Refusing a non-disposable PostgreSQL identity")
        return connection
    except BaseException:
        connection.close()
        raise


def _table(schema: str, name: str):
    from psycopg import sql

    if not _SCHEMA.fullmatch(schema) or name not in {"claims", "effects"}:
        raise SmokeFailure("Refusing a non-smoke SQL identifier")
    return sql.Identifier(schema, name)


def _claim(connection, schema: str, fixture_id: int, claimant: int) -> bool:
    from psycopg import sql

    row = connection.execute(
        sql.SQL("UPDATE {} SET claimant = %s WHERE id = %s AND claimant IS NULL RETURNING id").format(
            _table(schema, "claims")
        ),
        (claimant, fixture_id),
    ).fetchone()
    if row is None:
        return False
    connection.execute(
        sql.SQL("INSERT INTO {} (claim_id, marker) VALUES (%s, 1)").format(_table(schema, "effects")),
        (fixture_id,),
    )
    return True


def _claimant(schema: str, claimant: int, barrier, results) -> None:
    try:
        with _connect() as connection:
            backend_pid = connection.execute("SELECT pg_backend_pid()").fetchone()[0]
            barrier.wait(timeout=15)
            with connection.transaction():
                claimed = _claim(connection, schema, 1, claimant)
        results.put((claimant, claimed, backend_pid, None))
    except Exception as error:
        # Connection errors can contain credentials or server details.
        results.put((claimant, False, None, type(error).__name__))


def _race(schema: str) -> list[tuple]:
    context = multiprocessing.get_context("spawn")
    barrier = context.Barrier(WORKERS + 1)
    results = context.Queue()
    processes = [
        context.Process(target=_claimant, args=(schema, claimant, barrier, results))
        for claimant in range(1, WORKERS + 1)
    ]
    started = []
    try:
        for process in processes:
            process.start()
            started.append(process)
        barrier.wait(timeout=20)
        observations = []
        for _ in processes:
            try:
                observations.append(results.get(timeout=20))
            except queue.Empty:
                raise SmokeFailure("PostgreSQL claim process produced no result") from None
        for process in processes:
            process.join(timeout=5)
            if process.exitcode != 0:
                raise SmokeFailure("PostgreSQL claim process did not exit successfully")
        if any(error is not None for _, _, _, error in observations):
            raise SmokeFailure("A PostgreSQL claim process failed")
        if len({pid for _, _, pid, _ in observations}) != WORKERS:
            raise SmokeFailure("Claimants did not use distinct PostgreSQL connections")
        if sum(claimed for _, claimed, _, _ in observations) != 1:
            raise SmokeFailure("Expected exactly one successful PostgreSQL claim")
        return observations
    finally:
        for process in started:
            if process.is_alive():
                process.terminate()
            process.join(timeout=5)
            if process.is_alive():
                process.kill()
                process.join(timeout=5)
        results.close()
        results.join_thread()


def run() -> dict:
    require_opt_in()
    from psycopg import errors, sql

    schema = "p05_smoke_" + uuid.uuid4().hex
    created = False
    with _connect() as connection:
        try:
            with connection.transaction():
                connection.execute(sql.SQL("CREATE SCHEMA {}").format(sql.Identifier(schema)))
                connection.execute(sql.SQL(
                    "CREATE TABLE {} (id bigint PRIMARY KEY, claimant integer NULL CHECK (claimant > 0))"
                ).format(_table(schema, "claims")))
                connection.execute(sql.SQL(
                    "CREATE TABLE {} (claim_id bigint PRIMARY KEY REFERENCES {} (id), "
                    "marker integer NOT NULL CHECK (marker > 0))"
                ).format(_table(schema, "effects"), _table(schema, "claims")))
                connection.execute(sql.SQL("INSERT INTO {} (id) VALUES (1), (2)").format(
                    _table(schema, "claims")
                ))
            created = True
            observations = _race(schema)
            winner = next(claimant for claimant, claimed, _, _ in observations if claimed)
            claims = connection.execute(sql.SQL(
                "SELECT id, claimant FROM {} ORDER BY id"
            ).format(_table(schema, "claims"))).fetchall()
            effects = connection.execute(sql.SQL(
                "SELECT claim_id, marker FROM {} ORDER BY claim_id"
            ).format(_table(schema, "effects"))).fetchall()
            if claims != [(1, winner), (2, None)] or effects != [(1, 1)]:
                raise SmokeFailure("PostgreSQL claim state is not exactly one atomic effect")

            rollback_observed = False
            try:
                with connection.transaction():
                    if not _claim(connection, schema, 2, 99):
                        raise SmokeFailure("Rollback fixture was already claimed")
                    connection.execute(sql.SQL(
                        "UPDATE {} SET marker = -1 WHERE claim_id = 2"
                    ).format(_table(schema, "effects")))
            except errors.CheckViolation:
                rollback_observed = True
            if not rollback_observed:
                raise SmokeFailure("Expected PostgreSQL constraint failure was not observed")
            after_claims = connection.execute(sql.SQL(
                "SELECT id, claimant FROM {} ORDER BY id"
            ).format(_table(schema, "claims"))).fetchall()
            after_effects = connection.execute(sql.SQL(
                "SELECT claim_id, marker FROM {} ORDER BY claim_id"
            ).format(_table(schema, "effects"))).fetchall()
            if after_claims != claims or after_effects != effects:
                raise SmokeFailure("PostgreSQL failed to roll back both fixture writes")
        finally:
            if created:
                # Never cascade: cleanup must fail rather than delete foreign dependants.
                with connection.transaction():
                    connection.execute(sql.SQL("DROP TABLE {}").format(_table(schema, "effects")))
                    connection.execute(sql.SQL("DROP TABLE {}").format(_table(schema, "claims")))
                    connection.execute(sql.SQL("DROP SCHEMA {}").format(sql.Identifier(schema)))
    return {
        "scope": "bootstrap-postgresql-mechanics-not-business-parity",
        "role_scope": "migration-fixture-not-runtime-privilege-proof",
        "claimants": WORKERS,
        "distinct_connections": WORKERS,
        "successful_claims": 1,
        "persisted_effects": 1,
        "constraint_failure_rolled_back_both_writes": True,
        "temporary_schema_removed": True,
    }


def main() -> int:
    try:
        result = run()
    except Exception as error:
        print(json.dumps({"status": "failed", "error_type": type(error).__name__}))
        return 1
    print(json.dumps({"status": "passed", **result}, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
