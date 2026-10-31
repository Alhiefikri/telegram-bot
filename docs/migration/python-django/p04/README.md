# P04 Laravel comparator and initial smoke (#686)

Authority: [#686](https://github.com/Alhiefikri/telegram-bot/issues/686),
[canonical #679](https://github.com/Alhiefikri/telegram-bot/issues/679), and
[P00 acceptance](../p00/acceptance.md). Blockers #680 and #681 were closed before
implementation. Work belongs to `migration/python-django-experiment` or its child
branches, never directly to `main`. This work uses child branch
`migration/p04-laravel-baseline-686`.

Observed local checks, retained artifacts and limits: [verification.md](verification.md).

## Commands and isolation

The runner requires the existing [P01 harness](../p01/README.md), a local Docker
Engine with cgroup v2, and its default fake/synthetic Laravel configuration. It
refuses any running P01 stack and any non-default target configuration. It resets
only P01-owned disposable Laravel volumes. It neither reads production settings
nor starts the production entrypoint, scheduler, deployment or CI/CD.

Build setup, outside measurement:

```sh
npm --prefix src run build
python3 tools/migration_harness/harness.py build laravel --local-dependencies
python3 tools/migration_benchmark/build_assets.py
```

The explicit offline profile verifies installed dependencies against the lockfile,
retains development dependencies, and records dependency/source tree identities.
The additional asset layer freezes the current Vite output by content hash. This
is not silently substituted for a production-Dockerfile image. Images and host
asset bytes must match before measurement starts.

```sh
python3 -m unittest discover -s tests/migration_harness -p 'test_benchmark*.py' -v
python3 tools/migration_benchmark/runner.py --smoke --output .local/migration-p04/lightweight-smoke
# P11 only, after the Django slice exists; not a P04 prerequisite:
python3 tools/migration_benchmark/runner.py --benchmark --output .local/migration-p04/comparison-php
```

New runs require a new directory below `.local`. The default is the short smoke;
the comparison workload requires explicit `--benchmark` and belongs to P11 after
the Django slice exists. `--resume` requires identical protocol/source hashes.
Historical runs cannot contribute observations across incompatible conditions.
Smoke uses ten current-PHP flows, including checkout and paid-delivery, with two
attempts each; it is diagnostic only. Historical `smoke.py`/`resume_smoke.py` are
available for targeted diagnosis, not routine acceptance.
Runtime files enter the application's live filesystem over stdin, not a source
bind mount. Request clients and nine workers remain children inside the P01
application's 1.25 CPU/1216 MiB cgroup; the complete stack stays at 2 CPU/2048 MiB,
with no runtime swap. Host Docker, measurement driver, OS and build costs remain
outside that envelope and are reported as limitations rather than hidden overhead.

The optional read-only progress command prints completed cell/attempt counts and
the current private spool's sanitized outcome counts every 30 seconds. It never
prints payloads or identifiers, changes worker policy, or resets anything. Cold
waves restart their workers outside the measured interval, so a cell can continue
writing successful samples long before the runner prints its completed-cell line.

## Frozen protocol and dataset

The machine-readable contracts are
[`protocol.json`](../../../../tools/migration_benchmark/protocol.json) and
[`datasets.json`](../../../../tools/migration_benchmark/datasets.json). Their source
hashes accompany each run. Small and large are synthetic profiles, not claims
about production volume, distribution or capacity.

The seed retains complete Category/Family/Product, Customer, Order/Payment and
Product Stock Item relationships. Stock Intake, checkout, expiry/cancellation,
paid processing, Late Payment review and ambiguous delivery use existing Laravel
owners. No trigger is disabled and no balance/stock projection or immutable
history is patched to manufacture a fixture. Delivery ambiguity comes from a
lost synthetic Telegram response; the effect ledger chooses `unknown`.
Seed construction drains receipt/outbound work before starting admin notification
workers. Otherwise an admin notice can snapshot an unresolved receipt, then gain
an outcome-update effect depending on queue scheduling. This deterministic seed
ordering uses the normal owners and is outside measurement; measured worker
topologies and their source sleep values remain unchanged.

Seed evidence includes logical relationships, exact integer IDR prices,
cardinalities, actual status/review/effect distributions and invariant counts.
The canonical logical checksum sorts object keys, preserves list order, and
excludes randomized UUIDs, encryption and runtime timestamps. It is not a physical
backup checksum. Seed provenance names the domain owners. The safe one-pixel
fixture is a local asset, not a production Telegram file reference.

The independent-cell design introduced in protocol version 2 seeds once per
dataset, then restores a pristine local PostgreSQL template before each
independent cell/batch. Lightweight selection uses protocol schema 4;
storage/checkpoint schema remains 2. Warmup and measurement share that clone.
Only the disposable Redis namespace is flushed between cells;
seed admission verifies the private logical manifest, exact row fingerprint,
cardinalities and historical audits before restoring its cache marker. Cloning
and admission occur outside measurement. A candidate must import the same seed
and use the same independent-cell chronology and workload.

Version-1 evidence used ordered cells sharing an accumulating database and
restarted all workers for cold waves. It remains separate historical evidence;
never pool it with version-2 distributions. The interrupted version-1 run was
recovered read-only from its owned storage volume, including completed samples
that had not yet reached the host journal.

## Current implementation-first scope (2026-10-31)

The owner requested removing excessive verification work across the migration.
This revision supersedes the earlier 960-cell and staged 140-case requirements,
without claiming either old requirement passed or approving a human gate.

The owner subsequently approved stopping the long baseline and prioritizing
implementation. P04 is complete when the safe comparator, initial flow smoke
and honest partial evidence are available; it does not require all 24 cases.
P11 owns paired measurement after Django is runnable, including missing PHP
cases and only the necessary refresh of applicable retained evidence.

Use one current-PHP comparator, eight navigation transitions and users 1/5/10:
24 cases per implementation, fixed at representative data, warm, distinct-chat
traffic and representative background work. Each case has two independent
batches of 25 measured attempts after five excluded warmups per batch.
These are exploratory samples, not production p95 confidence or 50 guaranteed
successes. Preserve failures, unknowns and outliers; no minimum-200 qualification,
three-batch requirement or automatic extension remains.

Current PHP stays the primary comparator. Normalized PHP and small/cold/same-chat/
idle diagnostics run only to investigate a specific failure or risk, not as a
mandatory matrix. Same-chat/stale-callback functional correctness still matters.
Checkout and paid-delivery timings use transaction-flow smoke rather than another
load matrix. Reuse existing manifests, parser tests and resource observations;
do not add sensor, dashboard, resume or benchmark infrastructure for acceptance.

The target remains separate p50 and p95 Django <=70% current PHP per case.
Threshold-adjacent, near-floor, unstable or failure-heavy results are inconclusive.
After diagnosis/fix, at most one targeted two-batch rerun; unresolved uncertainty
goes to H1 for revise/stop, never endless sampling or a manufactured pass.
Local synthetic backend ACKs are not device-visible or production latency.

**Execution boundary:** schema 4 retains the 24-case comparison plan, but the
runner now defaults to smoke. Full measurement requires `--benchmark`; do not
run it to finish P04. Batch/sample counts are fixed, with no automatic extension.
Reports distinguish sampling completion from exploratory/inconclusive quality.
Use a fresh run identity when sources/protocol change and never relabel partial
coverage as complete. Both obsolete staged and owner-stopped lightweight runs
retain their data and cleanup accounting. See verification.md for actual results.

Historical execution records remain in [verification.md](verification.md).
They record the former staged plan at `.local/migration-p04/staged-baseline`,
not a requirement to finish it now. H1, actual Testing Bot/sandbox qualification,
data safety, branch requirements and production/CI permissions remain unchanged.

## Measurements

Every attempt has its full scenario/load identity, warmup phase, batch, ordinal,
outcome, retries, DB duration and observed monotonic timestamps. Missing boundaries
remain null. The report isolates each transition, user count, dataset, traffic,
temperature, background policy, implementation, transport and comparator profile.
It never pools slow transitions into a faster aggregate score.

The eight navigation transitions remain separate: Home to catalog, catalog to
Plans, Plan to detail, Back, Home, quantity, Orders and Balance. Checkout and
paid/delivery have separate boundaries and no invented numerical acceptance
budget. The HTTP kernel's ingress ACK is not a Telegram screen ACK. Successful
synthetic send/edit response timestamps are backend ACKs, not proof of when a
customer's device rendered the screen.
Interaction and receipt queue waits start at their observed enqueue invocation,
before Redis publication. They are not publication-completion timestamps.
Interaction queue wait excludes webhook ingress work.

`current` preserves Laravel business behavior and the source's nine queue names
and sleep values. `normalized` explicitly sets worker sleeps to zero under the
same whole-stack cap. Both use concurrent CLI HTTP-kernel clients and bounded real
Redis worker loops, not Nginx/FPM. Neither is a production HTTP-capacity profile.
This limitation must survive any P11 comparison. There are no PHP business-code
optimizations in this comparator.

Warm clients reuse booted independent processes. Version-2 cold waves start fresh
clients and only the relevant request-path workers: `default` for navigation;
add `telegram-outbound` for checkout; also add `payments` and `telegram-receipts`
for paid delivery. Unrelated background workers remain running. Catalog/icon
projection caches are cleared while canonical intent/session state is preserved.
Cold does not mean OS, page-cache,
image or database cold. Source admission limits remain unchanged. The driver
waits outside timed ingress for sufficient actual limiter budget and records that
pacing; it does not reset counters, raise limits or hide HTTP 429 retries.

Distinct-chat concurrency and same-chat bursts use synchronized process waves.
A coalesced/rejected action without an observed screen ACK is not counted as a
successful screen observation. Representative background adds bounded actual
provider-verification and notification jobs per wave; it is not a sustained
production arrival-rate model. Queue drain and population growth accompany each
cell; idle/background never share a percentile population.

Nearest-rank p50/p95/max retain outliers. Known failed-attempt durations remain in
`all_observed`; `successful` is separate. Warmups are counted but excluded.
Schema-4 reports distinguish sampling completion from evidence quality.
`sampling.completed` requires two completed independent measured batches;
`gate.status` is `exploratory` only with complete attempt coverage, observed
successful boundaries, above-floor timings and stable batch p95. Failures,
unknowns, incomplete phases or instability remain `inconclusive`; diagnostics
are `not_applicable`. The 0.1 ms floor and 20% descriptive batch spread remain.
No status is a statistical confidence interval, H1 approval or Django comparison.

## Evidence and resources

Each artifact directory contains sanitized attempt JSONL, per-cell accounting,
continuous resource JSONL, admission resources, a run manifest, report and
checksums. The manifest freezes revision, script/lock/asset hashes, exact images,
runtime versions, schema hashes, PostgreSQL settings, dataset manifests and driver
commands. Sensors record actual cgroup CPU/memory/peak/swap/events, OOM/restarts,
PostgreSQL connections/lock wait counts, Redis ready/reserved/delayed backlog and
host memory/swap/load. Sensor failures are explicit unknowns. Sampling is periodic,
so transient peaks in connections/backlog can fall between samples; cgroup peak
memory and cumulative CPU/events remain separate observations.
The sensors themselves consume capped service CPU and open one short-lived
observer connection to the administrative `postgres` database, avoiding a
connection race while replacing `disposable`. Reported application connection
counts exclude that observer. Use the same schedule for the candidate. Host load
and swap are context, not attribution of latency to one cause.

The probe fsyncs every completed sanitized attempt to a private evidence spool
before returning a whole batch. On interruption, the runner stops the isolated
probe process group, copies valid completed records, and marks only missing
attempts unknown. It fsyncs host evidence before resetting disposable volumes.
If containment or evidence recovery cannot be confirmed, the run stops and leaves
the private disposable storage intact; `run.json` records the recovery requirement.
Inspect/copy that synthetic spool before an explicit P01 reset. Failed runs still
produce reports and checksums. A failure never authorises skipping a cell or
resuming with unaccounted workers.

Every phase commits an atomic, fsynced checkpoint before the aggregate journal is
appended. Resume reconstructs journals from validated checkpoints without duplicate
identities, recovers an active spool before any reset, and uses a new batch identity
for an interrupted measurement. Completed cells are skipped; a partial batch cannot
satisfy the three-completed-batch requirement. Runtime sessions and host boot IDs
keep monotonic/resource observations from separate boots distinguishable.

Only synthetic Telegram/provider fixture evidence is generated here. Provider
sandbox, live Lhiestore Testing Bot and device-visible evidence remain separate
P01/P08/H1 obligations requiring attested safe targets. No credential acquisition,
webhook change, customer delivery, real-money request or production-scale claim is
authorised by these commands. PostgreSQL is real; this benchmark does not replace
synchronized money/stock/claim race acceptance.

## Context7 references

Context7 resolved `/laravel/docs`, then queried the indexed Laravel 13.x branch
separately for worker lifecycle and database query timing:

- [Queue worker timeouts and retry_after](https://github.com/laravel/docs/blob/13.x/queues.md):
  retain bounded work and keep timeout below retry expiry. Workers are isolated
  from the driver and stay inside the application's resource allocation.
- [Database query events](https://github.com/laravel/docs/blob/13.x/database.md#listening-for-query-events):
  `DB::listen` observes `QueryExecuted::time`. The probe accumulates duration only;
  SQL, bindings and rendered queries never enter measurement evidence.

Installed framework source remains the API authority for the locked comparator.
