# P10: isolated workers and durable recovery (#691)

Authority: [#691](https://github.com/Alhiefikri/telegram-bot/issues/691),
[live parent #679](https://github.com/Alhiefikri/telegram-bot/issues/679), and
[ADR-0032](../../adr/0032-python-bounded-worker-supervision.md). Blockers
#689 and #690 are merged on `migration/python-django-experiment`; this slice
stays local and does not activate production, real money or CI/CD.

## Worker contract

| Queue | Durable source | Wakeup | Process | Batch | Timeout / retry_after | Attempts |
| --- | --- | --- | --- | --- | --- | --- |
| `interaction` | non-payment `django_candidate.navigation_updates` | `candidate:interaction` | 1 | 25 | 60 s / 180 s | no blind replay |
| `payment` | checkout/check `navigation_updates`, `payment_events` hints and ambiguous `payments` | `candidate:payments` | 1 | 10 | 60 s / 180 s | navigation: no blind replay; other work: 3 |
| `receipt` | `telegram_outbound_effects` | `candidate:telegram-receipts` | 1 | 25 | 60 s / 180 s | 3 |

`notifications`, broadcast, stock, Binance and supplier families are reserved
and cannot be assigned to these workers. A queued family is disjoint by
construction; an envelope for another queue is rejected before the handler.

```text
supervisor (no DB connection)
├── serve.py                 HTTP, one connection
├── workers.py interaction   one connection, per-chat serialization
├── workers.py payment       one connection, provider I/O outside money locks
└── workers.py receipt       one connection, token-fenced effect claims
```

The runtime admits `processes=5`, `threads=5`, `db_connections=4` in the
private candidate contract. The disposable role ceiling is six: four for the
runtime (HTTP plus one per queue) and two bounded diagnostic/test slots that
are not worker capacity. `workers.py --queue` and `--supervisor` refuse to
start without `workers: true`; a raw P05/P06 stack starts HTTP only. All
processes remain children inside the existing 1.25 CPU / 1216 MiB application
allocation. The supervisor holds no database or Redis connection.

## Scheduling, timeout and shutdown

- Each loop runs a durable sweep first, then waits up to one second for an
  ID-only wakeup (`BRPOP`). Sweeps are bounded and repeat every 2 s
  (interaction), 5 s (payment) and 5 s (receipt).
- A job runs under a 60 s process alarm. On timeout or handler failure the
  connection is discarded; a successful job keeps its single admitted connection
  for `CONN_MAX_AGE=60` and `close_old_connections()` returns it once expired or
  unusable. Durable state decides whether the unit is unknown, failed or
  retryable.
- Payment sweeps interleave navigation, hints and ambiguous-create recovery.
  Each operation gets its own timeout. A 60-second scheduling budget prevents
  starting more work after expiry without cutting off an operation in flight;
  the first family rotates on the next sweep.
- `SIGTERM`/`SIGINT` stop pickup and let the current job finish. The process exit
  closes its connection; the supervisor waits 75 s, then terminates and finally
  kills.
- An unexpected child exit is restarted at most three times in 60 s. A fourth
  exit stops the whole supervised runtime instead of looping forever.
- No PHP job payload is decoded. Wakeups are ASCII JSON with exactly
  `version`, `kind`, `id`; integers are bounded and UUIDs are canonical.

## Durable discovery and recovery

- Interaction and payment share the durable navigation inbox but process disjoint
  callback families. Checkout and payment-check callbacks run on the payment
  worker, including provider I/O; other navigation runs on interaction. The
  payment processor validates and claims under the per-chat advisory lock,
  releases it for provider I/O, then reacquires it and revalidates the claim,
  Customer, root generation and session before rendering. Earlier pending or
  processing rows fence later same-chat updates. Bounded sweeps skip blocked
  chats; an abandoned processing row becomes `unknown`, never blindly replayed.
- Payment also discovers unprocessed callback hints and recoverable ambiguous
  creates from PostgreSQL. Recovery observes authoritative provider history;
  it never creates another invoice or settles from a callback alone.
- Receipt: the bounded sweep repairs the sent projection, expires stale leases
  to `unknown`, discovers paid orders and failed photo fallbacks, and processes
  due ordered effects directly from PostgreSQL even when Redis is absent. An
  unknown delivery is never resent.

## Instrumentation

`WorkerMetrics` stores bounded counters and pickup/finish/sweep durations and
writes one private JSON file per queue. The evidence also reads durable
timestamps: navigation `accepted_at`/`claimed_at`/`completed_at`, payment
`received_at`/`paid_at`, receipt effect `created_at`, first claim
`first_pickup_at` in `outcome_metadata`, and `sent_at`. No metric contains an
identity, payload, money value or credential.

## Commands

```sh
# Run the real runtime (HTTP + one process per queue):
python stack.py --metrics-dir /tmp

# Run only the three supervised queue workers:
python workers.py --supervisor --metrics-dir /tmp

# One bounded queue run (test/ops, not a daemon):
python workers.py --queue receipt --iterations 2 --metrics /tmp/receipt.json

# Disposable proof from the repository root:
python3 tools/migration_harness/smoke_workers.py
```

Focused regressions (no database required):

```sh
cd candidate
.venv/bin/python -m unittest tests.test_workers tests.test_runtime tests.test_jobs \
  tests.test_navigation_ingress tests.test_receipt_render tests.test_receipt_transport -v
```

The PostgreSQL drill and mixed-load proof live in
`candidate/tests/test_workers_postgres.py` and are invoked only through
`candidate/smoke_workers.py` by the disposable host orchestrator. It reuses the
P01 namespace, the repository schema oracle, the P07/P08 fixtures and the P09
effect fencing; it does not add a second harness or report.

## Verification (observed locally)

Command: `python3 tools/migration_harness/smoke_workers.py` from the repository
root. The disposable stack used Python 3.13.18, Django 5.2.18 and PostgreSQL
16.15 against the fake Telegram relay and the fake slow Sakurupiah sandbox.
The mixed phase started the real `stack.py` supervisor; the recovery phase
stopped Redis first.

The recorded `.local/migration-p10/verification.json` observed:

- Mixed load: navigation completed in 0.75 s, the eight-update navigation
  backlog drained in 1.55 s and the independent receipt projection completed in
  1.64 s while the slow payment worker was still busy for 4.82 s. The payment
  receipt followed at 5.54 s. All three queues drained to depth 0; the sampled
  runtime never exceeded 3 `candidate_runtime` connections (admitted ceiling 4).
  Interaction, payment and receipt workers finished 1/1/7 units with zero
  failures, rejections or job timeouts; the non-receipt notification row stayed
  `pending` and isolated.
- Recovery with Redis stopped: the lost wakeup was delivered from PostgreSQL,
  pending interaction work was processed, the callback-hint payment settled,
  and the abandoned receipt claim became `unknown` after one send. The
  abandoned order was not resent, and its balance/order state was unchanged.
  Recovery worker sweeps were 0.15 s (interaction), 0.88 s (receipt) and 4.15 s
  (slow payment).
- Resources: the probe container's cgroup peak was 301,498,368 bytes against the
  1,275,068,416-byte limit, peak 8 PIDs, and 1.801 CPU-seconds over 5.339 s
  (0.337 average CPUs against the 1.25-CPU limit). The final cgroup snapshot was
  103,194,624 bytes. Boundary/PostgreSQL/Redis reported 15.07/64 MiB, 35.52/640
  MiB and 8.31/128 MiB. These are cgroup observations, not a sum of process
  RSS; peak includes the probe driver, and the limits are ceilings, not
  utilization claims. The run reused the existing P05/P09 candidate image with
  the current source read-only; no image build, real provider, Telegram send,
  production data/key or CI operation was performed.
- Identity: base revision `2140eba722a3a9dc791fcd932da86320e760e525`, candidate
  image `sha256:5e2c9e4e801e0fa6baf26331a9c600752b6d4ccc6cf9ec3d611dbe4eaca8f652`,
  boundary image `sha256:14f7c59b2f1b8c6a0bc8c43babfb50495293ba888ffb6e03499478b9a7a8e6de`,
  manifest `.local/migration-p10/lifecycle/candidate/1793510002025084853-manifest.json`.

## Context7 references

Consulted through Context7's public v2 context API on 2026-11-01:

- `/websites/djangoproject_en/5.2`, query "transaction.on_commit after commit and
  atomic transactions": `on_commit()` runs only after the outermost commit, in
  registration order; a rolled-back savepoint discards its callback. The
  ID-only enqueue is therefore registered inside the business transaction and
  only fires after commit. The callback itself catches Redis errors, so a lost
  hint cannot suppress later callbacks or roll back a committed write.
- `/websites/djangoproject_en/5.2`, query "CONN_MAX_AGE database connections
  per thread close_all": each thread owns its connection and a long-lived worker
  should close unusable connections after each task. The #721 follow-up keeps a
  successful worker on one connection for `CONN_MAX_AGE=60` and calls
  `close_old_connections()` after each job/sweep; timeout or handler failure
  still calls `connections.close_all()` so aborted session state is discarded.
  The four-slot role budget is unchanged.
- `/websites/postgresql/16`, query "FOR UPDATE SKIP LOCKED concurrent queue":
  `SKIP LOCKED` is the documented option for queue-like tables; stale
  interaction resolution uses it behind a bound. Receipt and payment claims
  keep their specific-row `FOR UPDATE`/token fencing.
- `/websites/postgresql/16`, queries "CONNECTION LIMIT" and "advisory lock
  functions": a role-level connection limit bounds normal sessions, and
  session advisory locks serialize navigation chats and payment observations.
  The disposable role allows six normal connections: four runtime plus two
  diagnostic/test slots.

Primary documentation:
[Django on_commit](https://docs.djangoproject.com/en/5.2/topics/db/transactions/#performing-actions-after-commit),
[Django persistent connections](https://docs.djangoproject.com/en/5.2/ref/databases/#persistent-connections),
[PostgreSQL locking](https://www.postgresql.org/docs/16/sql-select.html#SQL-FOR-UPDATE-SHARE),
[PostgreSQL role attributes](https://www.postgresql.org/docs/16/role-attributes.html),
[PostgreSQL advisory locks](https://www.postgresql.org/docs/16/functions-admin.html#FUNCTIONS-ADVISORY-LOCKS).

## Observed limits

The proof uses the fake Telegram relay and a fake, deliberately slow Sakurupiah
sandbox. It does not qualify a real provider, actual Telegram delivery,
production data/keys, resource capacity or the 30% navigation target. The
recovery phase stops Redis before running the worker; the lost-wakeup,
expired-lease and no-resend assertions are recorded in
`.local/migration-p10/verification.json`. cgroup `memory.current` is reported
apart from RSS and container limits; a limit is a ceiling, never actual usage.
Later runs can vary; the JSON and its source/image/manifest identity are the
authoritative record, not this summary.
