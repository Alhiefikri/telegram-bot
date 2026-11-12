# P26 operations, retention and recovery — #708

**Status: local P26 implementation and synthetic PostgreSQL proof are complete; historical-data, production and cutover qualification remain separate.** No production database, provider, Telegram send, CI/CD, or production scheduler was used or activated.

## Ownership and bounded execution

`candidate/lhie_candidate/operations/contract.py` is the command inventory and owner matrix. It mirrors the PHP scheduler entries and operation-only commands; the `inventory` regression detects missing or extra command signatures. Each row records Python owner, cadence, failure policy, state (`scheduled`, Python-owned `ported`, or still-`operator`), limit, maximum runtime, lease and evidence reference. Scheduled bounds are validated (at most 5,000 rows, 300 seconds and a 3,600-second lease).

Print the full machine-readable matrix with:

```sh
candidate/.venv/bin/python candidate/ops.py matrix
```

The scheduler runs one bounded cycle per invocation. PostgreSQL stores cycle/operation claims, cursors, summaries and leases; competing cycles do not overlap. Missed slots run once rather than replaying a burst. Per-operation failures are isolated and follow the row policy; partial/timeout/metrics failures remain observable. Receipt recovery opens and resolves a sanitized outbound alert episode. Importing the app or running HTTP does not start this scheduler. Remaining `operator` entries are explicitly retained for later retirement, not silently ported or scheduled.

## Health and metrics

`candidate/ops.py health` exposes cheap liveness/readiness and bounded, read-only diagnostic checks. Database, queue, scheduler state, heartbeat and worker metrics are separate checks; a missing or malformed heartbeat/metrics file is represented as unavailable/invalid, not as capacity. File reads and failed-job queries are capped. Reports contain fixed queue/operation names and aggregate counters, never payloads, credentials or provider exception text. The implementation makes no throughput/capacity claim. Metrics write failures do not change the outcome of the operation being measured.

## Retention

`candidate/ops.py retention` defaults to a 90-day cutoff and scrubs only `payload` and `customer_id` from old, already-processed `telegram_updates`. It preserves the update ID tombstone. The retention window is bounded to 1–3,650 days, chunk size to 1–5,000 rows, and one invocation to at most 20 batches. Cursors wrap on a later pass so late-imported rows are not hidden behind a high-water mark. Financial rows, payment events, unknown delivery states, audit evidence and notification-family roots are not deleted.

`retention --report` is read-only: all notification-family roots remain protected because archive, complete reverse-reference and hold proofs are not qualified. Its scan is bounded (page 100, roots 1,000, dependencies 1,000, at most 200 dependencies per root, 10 seconds and 20 detail samples); a capped result returns a continuation cursor and resume token. `auth-cleanup` separately removes consumed/expired authentication challenges under its configured limit.

## Python-owned schema and backup operations

`candidate/lhie_candidate/operations/bootstrap.py` owns `bootstrap`, `upgrade` and `verify`. The checked-in baseline manifest records raw SQL and normalized schema SHA-256 values, PostgreSQL major version, migration hashes and catalog counts. The baseline is PostgreSQL 16; bootstrap rejects a nonempty database unless explicitly overridden and checks the client/server/baseline major versions. Upgrade validates recorded baseline and delta checksums, applies versioned SQL transactionally under an advisory lock, and verifies migration history. Verification compares schema digest, tables, sequences, indexes, functions, triggers, constraints and extensions. The runtime path is Python-owned; PHP is used only by the development-time baseline generator.

Current baseline evidence: schema and SQL SHA-256 `6c45fa58fb25040c94fa5b5bd40b5c11fe1e2eabe167a530f34b02fb879e0f11`; 138 tables, 103 sequences, 544 indexes, 31 functions, 43 triggers, 591 constraints and 2 extensions. This is repository schema evidence, not proof of production schema or grants.

`candidate/lhie_candidate/operations/backup.py` creates a GPG AES-256 encrypted archive containing a PostgreSQL custom dump, role definitions, manifest, and explicitly selected file/key sidecars. The manifest records checksums, protected financial counts, catalog, schema and grants. Role passwords are omitted and remain operator-managed. Backup output and keys are mode `0600`; copied restore files are `0644`, keys `0600`. Restore requires a fresh empty database and a PostgreSQL superuser, requires destinations for present sidecars, and refuses unsafe paths, symlinks, overlapping destinations and overwrite. It validates protected counts, schema, catalog and grants before publishing staged sidecars. The remote override is intentionally not used for this local qualification.

Command shapes (use only an admitted disposable loopback database and a private passphrase file):

```sh
candidate/.venv/bin/python candidate/ops.py bootstrap --dsn <local-dsn> --baseline-dir candidate/lhie_candidate/operations
candidate/.venv/bin/python candidate/ops.py upgrade --dsn <local-dsn> --baseline-dir candidate/lhie_candidate/operations
candidate/.venv/bin/python candidate/ops.py verify --dsn <local-dsn> --baseline-dir candidate/lhie_candidate/operations
candidate/.venv/bin/python candidate/ops.py backup --dsn <local-dsn> --output <new-archive> --passphrase-file <private-file> [--file SOURCE=ARCHIVE_PATH] [--key SOURCE=ARCHIVE_PATH]
candidate/.venv/bin/python candidate/ops.py restore --dsn <empty-local-dsn> <archive> --passphrase-file <private-file> [--files-destination <path>] [--keys-destination <path>]
```

## Acceptance evidence and limits

| P26 criterion | Implementation and evidence |
| --- | --- |
| Scheduler parity, ownership, cadence, bounds and policy | `operations/contract.py`, `ops.py matrix`, fake-clock scheduler and inventory unit tests. |
| Preserve financial history and uncertain delivery; bound cleanup/report | `operations/retention.py`; unit tests and disposable PostgreSQL sentinels verify protected data and update tombstones. |
| Bounded health without secrets or capacity claims | `operations/health.py`; health/metrics tests exercise sanitized and capped reports. |
| Python-only bootstrap, upgrade, backup and restore | Versioned baseline/migrations and `operations/{bootstrap,backup}.py`; disposable PostgreSQL smoke verifies schema/catalog/grants, sidecars, key permissions, and refusal to restore into a nonempty database. |
| Crash recovery, partial status and cursors | Fake-clock/unit tests plus `tests.test_operations_postgres.py` against real disposable PostgreSQL; P12 lifecycle recovery proof covers review/unpaid/refund guards and exhausted cursors. |

Run the focused unit suite from `candidate/`:

```sh
.venv/bin/python -m unittest tests.test_operations_backup tests.test_operations_bootstrap tests.test_operations_health tests.test_operations_jobs tests.test_operations_retention tests.test_operations_scheduler tests.test_operations_contract -v
```

The final focused run passed all 49 tests.

Run the P26 disposable PostgreSQL proof from the repository root:

```sh
candidate/.venv/bin/python tools/migration_harness/smoke_operations.py
```

The final disposable run observed Python 3.13.14, Django 5.2.18 and PostgreSQL
16.15. It used synthetic sentinels on an empty repository-schema database.
Bootstrap, upgrade, verify, financial/payment-event preservation, lease crash
recovery, cursor clear, update-tombstone retention, unknown-delivery
preservation, backup/restore, grants, sidecar permissions and nonempty-target
refusal passed. The restore manifest recorded one protected store-balance
entry, one outbound effect and one unresolved effect, with no Orders or
Payments.

The evidence artifact records repository and baseline source revision
`069f0028b3362665db515f117099b0a88c1fea7f`; current operation sources are
fingerprinted individually. `.local/migration-p26/verification.json` is
private (mode `0600`) and records the environment, command, dataset, outcomes,
unknowns, limits and artifact path.

The related P12 shared-order recovery scenario was separately run with:

```sh
candidate/.venv/bin/python tools/migration_harness/smoke_checkout.py --lifecycle
```

Its local evidence is `.local/migration-p12/verification.json`. The fixtures are synthetic; neither smoke qualifies actual historical data, production roles/grants, original keys/files, live provider behavior, operational capacity or cutover. Historical-data and key coverage remain HD #684; human readiness/cutover gates H2/H3 remain open. No GitHub issue closure is implied by these local artifacts.
