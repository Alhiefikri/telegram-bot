# P04 verification records

Issue [#686](https://github.com/Alhiefikri/telegram-bot/issues/686); canonical
[#679](https://github.com/Alhiefikri/telegram-bot/issues/679). The commands below
ran locally against P01-owned disposable PostgreSQL/Redis. No production target,
customer, real-money provider, webhook change, deployment or CI/CD operation was
used. Context7 references and the measurement contract are in [README.md](README.md).

## Exercised checks

The staged runner/protocol changes passed the complete local migration-harness
suite: `python3 -m unittest discover -s tests/migration_harness -v`, 61 tests.
This includes primary/diagnostic separation, the 199/200-success boundary,
completed-batch qualification, profile isolation and interrupted evidence.
The older 53-test result below and retained service proofs apply to their captured
source hashes; they are not fresh runtime verification of the staged replacement.
The staged disposable runner smoke also completed: 20 reduced diagnostic cases,
40 successful attempts, no errors/timeouts/unknowns, zero qualified primary cases,
and owned runtime cleanup `reset`. Command:
`python3 tools/migration_benchmark/runner.py --smoke --output .local/migration-p04/staged-runner-smoke`.
Its six sanitized `staged-runner-smoke-*.gz` evidence files retain run provenance,
report, raw samples, accounting, resources and original checksums.

| Check | Observed result |
| --- | --- |
| `python3 -m unittest discover -s tests/migration_harness -v` | 53 tests passed |
| PHP lint of the mounted `probe.php` in the frozen comparator image | No syntax errors |
| Current asset build, P01 verified-dependency image build, `build_assets.py` | Commands succeeded; exact image/asset identities are retained in run manifests |
| `smoke.py --output .local/migration-p04/v2-proof-final` | 12 cases passed; 73 attempts, 55 successes, 18 intentional coalesced/no-owning-ACK observations, no error/timeout outcomes |
| Repeated independent seed construction | Small and large each produced the same logical checksum on two independent seeds |
| Pristine clone admission and mutated clone refusal | Both dataset profiles admitted; each of the 12 mutated case clones refused |
| `resume_smoke.py --output .local/migration-p04/v2-resume-proof-01` | Real runner kill, frozen probe cohort and stopped-container recovery passed; one completed attempt preserved, replacement batch 2, no duplicate attempts, original phase remains incomplete |
| Disposable cleanup after both smoke commands | P01-owned runtime/volumes reset |
| Real Redis job/wakeup sensor fixture | Two ready jobs, one reserved, three delayed and nine wakeup tokens distinguished correctly |

The smoke exercises all eight navigation transitions plus checkout and
paid/delivery, 1/5/10 clients, current/normalized worker profiles, cold/warm,
idle/background and same-chat ownership. It is not the complete Cartesian sample
matrix. Backend ACKs are synthetic Telegram API-response observations, not visible
latency on a device or live Telegram qualification.

The two 10-tap same-chat checkout/payment cases each produce one originating
operation and nine non-owning observations. Those nine callbacks do not borrow
that operation's checkout ACK or its provider-paid timestamp. These are separate
from the seed's delivery-unknown effect-ledger history.

## Deterministic synthetic data

| Profile | Categories | Families | Products | Customers | Orders / Payments | Stock items | Late reviews | Delivery-unknown Orders |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Small | 4 | 12 | 36 | 20 | 60 / 60 | 1840 | 10 | 10 |
| Large | 20 | 120 | 360 | 200 | 600 / 600 | 9616 | 100 | 100 |

Small logical SHA256:
`fd5a8a4c88736489f99da32d5c25bc9c5112ef861b5ef9f215a1366d81c0419a`.
Large logical SHA256:
`68c6c859de3e25ecadaedd28a04bc4455fdb7177f5c8a33bda28e47d4cb2c08e`.

The history cycles pending, paid, expired, cancelled, late-paid review and
ambiguous delivery. Audits observed zero queue backlog, failed jobs and invalid
stock projections at seed completion. Every Order had its related Payment;
Stock Intake operation counts matched the Product counts. Domain owners, not
fixture projection edits, produced those states.

An earlier concurrent seed allowed admin notices to race receipt completion and
sometimes materialize extra outcome-update effects. Its large logical checksum
therefore drifted. Seed construction now resolves receipt/outbound work before
starting notification workers; two independent replay checks proved the resulting
logical identity. This ordering is outside measurement. Measured worker
concurrency, source queue policies and durability are not changed by that repair.

These cardinalities and distributions are test assumptions, not verified
production statistics. The logical checksum excludes randomized encrypted bytes,
UUIDs and runtime timestamps. A private exact-row fingerprint separately protects
admission of the physical PostgreSQL clone.

## Interrupted history retained

`.local/migration-p04/baseline-03` was a version-1 ordered/shared-database run. The
runner process was absent and the disposable containers had stopped. The cause
of those exits was not established. Read-only recovery from the owned storage
volume preserved 305 completed records beyond the 1270 records already on the
host, for 1575 total raw records. The retained measured outcomes include 1490
successes and five errors; 80 further records are warmups. No torn spool records
were rejected.

This run covers only 14 cells and one batch. It does not qualify the baseline.
Its raw records and provenance remain separate from version-2 independent-cell
measurements. Earlier interrupted/repair diagnostics remain locally under
`.local/migration-p04/baseline-01`, `baseline-02`, `path-smoke-before-fixes`,
`fixed-path-smoke`, `final-paid-smoke` and `interrupted-clone-smoke`; they are not
silently pooled into the qualified sample population.

## Sanitized retained artifacts

The repository [evidence directory](evidence/) contains gzip-compressed JSON/JSONL
with deterministic gzip headers and [SHA256 checksums](evidence/checksums.json):

- `path-smoke-v2.json.gz`: source/image/version/command provenance, seed manifests,
  per-case outcomes and untimed harness-phase accounting.
- `path-smoke-v2-samples.jsonl.gz` and `path-smoke-v2-resources.jsonl.gz`: raw
  sanitized smoke timestamps and actual cgroup/connection/backlog sensors.
- `resume-proof-v2.json.gz`, `resume-run-v2.json.gz`,
  `resume-samples-v2.jsonl.gz`, `resume-accounting-v2.jsonl.gz`: real interrupted
  cohort/restart evidence and preserved attempts.
- `interrupted-v1-samples.jsonl.gz`, `interrupted-v1-summary.json.gz`,
  `interrupted-run-v1.json.gz`: historical partial-run recovery, explicitly
  unqualified and not mixed with version 2.

Every artifact carries its own captured source identity. The resume diagnostic
precedes the final worker-option and telemetry alignment; its source hash identifies
the recovery paths actually exercised, not the final baseline's source bundle.
The retained final smoke manifest (`path-smoke-v2.json.gz`, local original
`.local/migration-p04/v2-proof-final/smoke.json`) captures these SHA256 identities:

| Source | Captured SHA256 |
| --- | --- |
| `protocol.json` | `1672f8bb1d9a30492691712cfa16a61052f750898b540d477af7ee41bc67b2a1` |
| `runner.py` | `3bf3289798db7ecb40f8c5a31a7dd86c3afc9e58ba596fcf62fbd45cc530a253` |
| `report.py` | `32888bc927505949ebc20a6f952e5fa8797463900ec82b57f799be2041090878` |
| `probe.php` | `53bfa394d5a2971895db9818a083ea2762ee54fedb450c544ba24ebf25c2db0b` |

The full source map remains in that manifest. These identities scope the prior
smoke proof; they are not a verification claim for the staged replacement.

## Historical staged baseline boundary (superseded planning)

The later implementation-first revision in [the current scope](README.md#current-implementation-first-scope-2026-10-31)
supersedes the measurement requirements below. These records describe prior
execution and are retained, not instructions to complete the old run. The
planning revision did not run benchmarks, change runtime defaults or stop services.

On 2026-10-31 the owner explicitly approved stopping the 960-cell Cartesian
fullrun in this conversation and replacing P04 measurement scope with the
[then-approved staged scope](https://github.com/Alhiefikri/telegram-bot/issues/686#issuecomment-6542990884). This is not a
claim that the former acceptance requirement was met.

The old independent-cell run at `.local/migration-p04/baseline-final` was stopped
with explicit SIGINT. It retained 1940 records and 44 phases; `run.json` records
`meta.status = failed` because of that explicit stop. Recovery preserved 12
completed spool samples, filled missing observations as unknown, and completed
disposable cleanup. All old files remain locally, including raw samples,
checkpoints, accounting/resources, reports, checksums and provenance.
Neither that run nor any earlier partial/smoke run qualifies the old 960-cell
requirement (768 mandatory navigation cells). Do not delete, relabel as passing,
or pool these observations into the staged run.

The new authoritative run location is `.local/migration-p04/staged-baseline`.
Its `run.json`, atomic checkpoints, `samples.jsonl`, `accounting.jsonl`,
`resources.jsonl`, `report.json` and `checksums.json` must retain the new
protocol/source identity. The approved plan has:

- 48 primary navigation cases: eight transitions × users 1/5/10 × two PHP
  profiles, fixed large/warm/distinct-chat/representative-background conditions.
  Each starts with three independent batches of 70 measured observations and
  10 excluded warmups per batch. Qualification requires at least 200 successful
  screen observations and three completed batches; only under-200 or unstable
  primary cases may extend, bounded at six batches.
- 64 selected navigation diagnostics: eight transitions × two profiles × four
  variants, users 10 only. Variants are small/warm/distinct/representative,
  large/cold/distinct/representative, large/warm/same-chat/representative and
  large/warm/distinct/idle.
- 28 checkout/paid-delivery diagnostics: both flows × users 1/5/10 × both
  profiles at large/warm/distinct/representative (12), plus both flows × both
  profiles × the same four variants at users 10 (16).

All 92 diagnostics use three independent batches of 10 measured observations;
they have no minimum-200 qualification, no automatic extension and no navigation
target pass. Cold results remain separate and same-chat coalesced unknowns stay
visible. Failed/interrupted attempts and outliers remain in all reports; an
incomplete phase cannot satisfy completed-batch requirements. Unstable primary
results at the bound stay inconclusive.

This scope approval is P04-only, not H1 approval or closure of any human gate.
No staged completion is claimed here. Candidate measurements are absent, so
P11 comparison remains `not_evaluated`, not a Django 30% improvement.
Canonical H1 authority, real Testing Bot/device evidence and the still-unset
checkout/detection/delivery numerical budgets remain unchanged.

That schema-3 staged run was subsequently stopped with explicit SIGINT for the
lightweight cutover. `run.json` records `status = failed`,
`disposable_cleanup = reset` and `active = null`; its interrupted attempts and
recovery accounting remain retained under the original protocol/source identity.
No staged completion or qualification is claimed.

## Lightweight execution (schema 4)

The schema-4 comparison plan is current PHP, 24 navigation cases and two fixed
batches of 25 measured attempts after five warmups. Following the owner's
subsequent stop decision, CLI defaults to smoke; `--benchmark` is explicit P11 work.
Sampling never extends for failure or instability. Reports expose completed batches
separately from exploratory/inconclusive quality; old qualification fields and
minimum-200/three-batch extension code were removed.

Focused regression command:

```sh
python3 -m unittest discover -s tests/migration_harness -p 'test_benchmark*.py' -v
```

Observed: 39 tests passed in 0.180 seconds. Superseded fixed-matrix/extension
tests were removed; focused regressions retain interruption recovery, failure/
unknown preservation, diagnostic isolation and bounded sampling behavior.

Actual disposable runner smoke:

```sh
python3 tools/migration_benchmark/runner.py --smoke --output .local/migration-p04/lightweight-smoke
```

Observed: `status = complete`, ten current-PHP flows, 20/20 successful attempts,
zero errors/timeouts/unknowns, all diagnostic-only, and disposable cleanup reset.
This exercised actual Laravel HTTP-kernel, Redis workers and PostgreSQL, including
checkout and paid-observation/credential-delivery boundaries, with synthetic
provider/Telegram transport. It did not exercise a real provider or Testing Bot.

The first lightweight baseline invocation stopped during setup with zero measured
attempts. Its safe run record retained `runner_error` and cleanup `reset`; the
existing command wrapper discarded subprocess detail, so the original cause is
unknown, not claimed fixed. Resource observations contained no sensor unknowns,
OOM or container restarts. The isolated large-seed diagnosis subsequently
completed through the actual domain owners: 600 Order/Payment pairs, 9,616 stock
items, zero backlog/failed jobs/invalid stock projections, 100 expected/actual
ambiguous-delivery Orders and 100 expected/actual Late Payment reviews.
One explicit resume retained the original failed session/history; it did not
increase the per-case sampling budget or erase the setup failure.

### Owner-approved stop and P11 handoff

The owner chose “Hentikan; prioritaskan implementasi” after asking why this
benchmark was delaying the migration. The resumed driver was interrupted with
SIGINT. Final state: `failed` (not rewritten as success), cleanup `reset`,
`active = null`; no P01 disposable containers remained.

Retained partial coverage: eight observed navigation cases, seven with both
measured batches complete; 375 successful measured attempts and 80 excluded
warmups, zero recorded errors/timeouts/unknowns. Three cases were exploratory
and five inconclusive. This is not 24-case completion, production p95 evidence,
a Django comparison or a 30% improvement; candidate remains absent.

P04 now supplies the safe reusable comparator, actual initial transaction/
navigation smoke and these limits. P11 owns paired PHP/Django measurement after
the Django slice exists; reuse applicable evidence and collect missing/changed
cases there. No more standalone baseline runs are required to finish P04.

Compressed sanitized smoke/partial run, report, raw samples, accounting,
resources and admission/checksum artifacts are retained under `evidence/` as
`lightweight-smoke-*` and `lightweight-partial-*`.
[`lightweight-partial-summary.json`](evidence/lightweight-partial-summary.json)
records the stop; [`lightweight-checksums.json`](evidence/lightweight-checksums.json)
identifies the new retained artifacts. Original `.local` records remain intact.

The final CLI default/explicit-benchmark dispatch was checked through the actual
argument parser without Docker mutation, and `runner.py --help` lists the two
mutually exclusive modes. The retained real-service smoke predates that CLI-only
dispatch change; underlying probe, selection and reporting paths are unchanged.

## Limits

The comparator uses concurrent CLI HTTP-kernel clients and real Redis worker
paths, not Nginx/FPM throughput or production-capacity measurements. The whole
stack is capped at 2 CPU/2048 MiB with no container swap; OS, Docker and host
observation overhead remain outside that cap. Cgroup/connection/backlog samples
are periodic; transient connection/queue spikes may fall between observations.
Host memory/swap/load are context, not proof of a latency cause.
The final smoke retained 150 resource snapshots with no sensor unknowns, OOM-kill
events, container restarts or container swap use. Peak observed application DB
connections were 20. Redis `:notify` wakeup lists are reported separately from job
backlog; earlier diagnostic sensors combined them under `ready`, so those legacy
resource values must not be interpreted as counts of runnable jobs.

Only synthetic provider/Telegram transport ran. Provider sandbox qualification,
live Lhiestore Testing Bot, manual device-visible latency and H1 approval remain
separate. No production acquisition or HD completion is claimed. Real PostgreSQL
is used, but these measurements are not money/stock/claim race acceptance.
