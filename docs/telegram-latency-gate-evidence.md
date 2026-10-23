# Integrated Telegram latency gate (#656)

Measured locally on 2026-10-23. The final comparison command exited 0 on both
source revisions. This is synthetic PostgreSQL/Redis evidence and a rollout
recommendation, not a production latency claim, SLO or deployment approval.

## Reproduction and provenance

```bash
scripts/telegram-latency-compare.sh --scenario=all \
  --batches=3 --samples=3 --warmup-batches=0 \
  --worker-sleep=1 --interval-ms=250 --timeout-seconds=90 --backlog=20
```

Final run: `artifacts/telegram-latency-gate/20261023T113922Z/`.
Baseline batch runner: `baseline/20261023T113924Z-74239/`.
Integration batch runner: `integration/20261023T114534Z-77962/`.
Wall time for the sequential comparison, migrations and cleanup: 684.69 s.

- Baseline: `ca408381d127be1181f471e213f0c0087fbf9115` (#649, before tuning).
- Integration: `f0b0b6f2e41e1bf9e0cca22e870c791cfa27401c` plus the measurement
  changes in this working tree. The wrapper snapshots source before either run;
  later edits cannot change a running comparison. Existing unrelated storefront
  work was present in the integration snapshot and was not edited by this task.
- Application image: `telegram-bot:php-security-8.4.27`, image ID
  `sha256:dd317783b9d9a16690f805aa75e326aec9f1719f1a2dffa41e3db3f3324f4de5`.
- PHP 8.4.27, Laravel 13.34.0, PostgreSQL 16.15, Redis 7.4.11.
- Intel Core i5-8265U, 8 logical CPUs, 7,972,020 KiB machine RAM; Linux
  6.18.48-1-cachyos-lts, x86_64. PHP memory limit 128M, Asia/Jakarta app timezone.
- No application cgroup CPU/memory limits were imposed. These measurements do
  not reproduce the production container's 1.50 CPU / 896 MiB constraints.

Retained, sanitized raw samples and source manifests:

- [Baseline raw JSON](evidence/telegram-latency-656/baseline.json)
- [Integration raw JSON](evidence/telegram-latency-656/integration.json)
- [Revision, image and harness provenance](evidence/telegram-latency-656/provenance.txt)
- [Integration source SHA-256 manifest](evidence/telegram-latency-656/integration-source-sha256.txt)

The raw files retain every scenario/batch/sample, timestamps, missing boundaries,
interaction results and final cross-scenario correctness audit. Original local
artifacts additionally contain generated `report.json` / `report.md`. The
historical source receives measurement-code overlays only, documented in
[the runner contract](telegram-latency-baseline.md#comparing-source-revisions-656).
Business owners are not replaced with the integrated implementations.

## Dataset, fixtures and statistical limits

Each revision ran 15 scenarios, three separate batches per scenario and three
Orders per batch: 135 Orders per revision, 270 samples total. Workers start and
stop per batch. No warm-up batch was used in the final run; the report's `warm`
key means measured/non-excluded samples, not proof of steady-state warm caches.
The first sample of every batch includes worker startup cost.

Each Order has its own synthetic Customer, Product, checkout and Payment. Most
Orders allocate one structured credential; `pin-slow`, `burst-interactions` and
`ambiguous-telegram` allocate ten and exercise photo + TXT receipt units. Total
allocation is 378 credentials per revision. Credentials are never real accounts.
The history fixture has 20 terminal Expired Payments; each real reconcile sweep
has a ten-request cap. Main-loop observations are 250 ms apart, not a one-minute
production scheduler. No polling-interval delay is fabricated into the timings.

Provider/Telegram slow fixtures are 500 ms; provider outage fails one 500 ms
attempt, then recovers through another 500 ms authoritative status read. Check
verification uses a 3 s pending provider fixture. Pin delay is 3 s. Definite
Telegram failure is HTTP 400 on the first credential-bearing request, followed
by healthy transport and the existing fallback policy. Ambiguous delivery accepts
one send but loses the ACK, then exercises bounded recovery without blind resend.
Redis-loss drops queue/cache DBs after paid commit with workers stopped, invokes
the existing recovery command, then starts workers. This is lost-wakeup recovery,
not a Redis TCP outage during an in-flight send.

Both parent and worker install `Http::preventStrayRequests()` and use an internal
Docker network. Provider, Telegram and mail stay local. The runner uses real
Redis workers and actual checkout/lifecycle/ledger/receipt/admin owners. It does
not run production, CI/CD, real payments or customer messaging.

Nine observations per scenario support descriptive p50/max only. p95 is withheld
below 20 known values; no p99 is emitted. These samples cannot establish a tail
SLO or statistically stable regression threshold. Raw observations, including
failures and unknowns, are retained rather than discarded to improve a result.

## Observation to admin ACK comparison

All durations below are milliseconds. The ambiguous row ends at the admin
intervention notice, not successful buyer fulfillment.

| Scenario | n per revision | Baseline p50 / max ms | Integration p50 / max ms |
| --- | ---: | ---: | ---: |
| `idle` | 9 | 2989.24 / 3628.97 | 2616.11 / 3350.99 |
| `backlog-expired` | 9 | 1516.31 / 2640.88 | 2069.86 / 2885.11 |
| `slow-notification` | 9 | 5115.63 / 5541.53 | 2958.84 / 3130.46 |
| `slow-payment` | 9 | 2073.04 / 2575.21 | 1879.82 / 2314.58 |
| `slow-provider` | 9 | 1442.57 / 2144.68 | 1327.01 / 2298.52 |
| `slow-telegram` | 9 | 7574.41 / 8307.13 | 4433.25 / 4627.31 |
| `pin-normal` | 9 | 2885.75 / 3433.27 | 2501.82 / 3239.13 |
| `pin-slow` | 9 | 8576.08 / 12166.15 | 2421.52 / 3756.70 |
| `pin-failed` | 9 | 1843.36 / 2821.79 | 2656.33 / 3699.50 |
| `pin-recovery` | 9 | 3511.15 / 4103.22 | 2247.17 / 2857.67 |
| `burst-interactions` | 9 | 2535.86 / 3876.20 | 2548.19 / 3327.69 |
| `failed-provider` | 9 | 1512.96 / 3133.05 | 1394.95 / 2400.12 |
| `failed-telegram` | 9 | 3286.56 / 4040.09 | 2279.34 / 2680.78 |
| `ambiguous-telegram` | 9 | 2168.17 / 4253.31 | 2916.92 / 3790.03 |
| `redis-loss` | 9 | 12284.94 / 13730.80 | 7310.15 / 10979.50 |

This is not an across-the-board latency improvement. `backlog-expired`,
`pin-failed` and ambiguous admin notices have slower observation-to-admin medians
in the integrated sample. `burst-interactions` is essentially unchanged at this
boundary. Queue phase, worker startup and concurrent invoice work remain part of
the measured cost; nine samples do not establish a production regression.

The backlog's observation-to-admin number starts only when authoritative success
is observed, excluding earlier history sweeps. Its separate observation-driver
boundary and sweep count capture the improvement: all nine active targets needed
three sweeps on baseline, one on integration. Provider observation-driver p50
was 892.61 ms versus 46.91 ms. Every batch still inspected all 20 distinct history
rows. Per-batch main provider reads were 66 versus 26 (history reads 60 versus 20);
provider observations of not-yet-ready targets account for the remaining reads.

## Separate stage boundaries

The raw files contain every boundary for every scenario. The idle control below
shows the independent stages; n=9 per boundary per revision. Provider `paid_at`
is a synthetic fixture fact roughly two seconds before status lookup, not the
application's observation time or customer payment time.

| Idle boundary | Baseline p50 / max ms | Integration p50 / max ms |
| --- | ---: | ---: |
| `provider_observation_ms` | 3.22 / 8.37 | 2.30 / 17.44 |
| `provider_paid_to_observation_ms` | 2462.19 / 2948.84 | 2618.49 / 2731.23 |
| `observation_to_paid_commit_ms` | 163.01 / 216.38 | 152.50 / 234.23 |
| `paid_commit_to_enqueue_ms` | 3.27 / 7.02 | 3.54 / 7.62 |
| `queue_wait_ms` | 1411.00 / 1909.00 | 1556.00 / 2062.00 |
| `pickup_to_first_credential_ack_ms` | 345.45 / 781.54 | 350.65 / 901.60 |
| `pickup_to_receipt_completion_ms` | 347.68 / 784.73 | 353.00 / 903.61 |
| `receipt_completion_to_admin_notice_ms` | 892.57 / 1322.69 | 511.43 / 960.38 |
| `e2e_observation_to_admin_notice_ms` | 2989.24 / 3628.97 | 2616.11 / 3350.99 |

Callback ACK and action completion are separate. Each burst batch sends two
Check callbacks per Customer, waits for a real status lookup to start, then sends
Home on the same chats. All 27 callbacks per revision returned valid fast ACKs,
processed successfully and left Home rendered. ACK p50/max: baseline
11.95/237.28 ms; integration 9.46/285.60 ms. Home completion p50 over nine actions:
4120.21 ms versus 1133.72 ms. For the one provider lookup actually active in each
batch, all three integrated Home actions completed before the provider finished;
all three baseline Home actions completed after it. The other six comparisons
remain null because their Payment had no observed worker lookup, not fabricated
successes. Both revisions made one worker status lookup per batch; repeated
checks did not produce unlimited verification requests.

## Pin and noncredential isolation

With the 3 s pin fixture and ten credentials per Order, pickup-to-full-receipt
p50 was 4017.34 ms on baseline versus 651.96 ms integrated. Every integrated
receipt completed and every admin ACK arrived before its pin finished (9/9).
The pin stays optional; its own queue delay is recorded separately.

The unrelated admin notification fixture delays only admin-chat HTTP on both
revisions, leaving buyer transport healthy. Receipt queue-wait p50 was
2580 ms baseline versus 1501 ms integrated; pickup-to-completion p50 was
409.10 versus 271.96 ms. The integrated receipt worker never consumes the
unrelated notification. The resource cost of the separation is recorded below.

All nine `pin-failed` Orders completed receipts/admin delivery despite rejected
pins. All nine `pin-recovery` Orders were unpinned before recovery, recovered
through one bounded recovery sweep per batch, then replayed with zero further
pin calls and unchanged pointers. No changed pin eligibility or forced pin
success was used.

## Correctness and failure accounting

Final cross-scenario audits passed for all 135 Orders on each revision, including
a re-read after later recovery/Redis-loss scenarios:

- One processed settlement event per measured Payment and the exact allocated
  stock quantity per Order. Replayed authoritative observations caused no second
  settlement or stock allocation.
- Zero duplicate automatic credential payload sends, duplicate first-ACK events,
  wrong-recipient sends or failed queue jobs. Recipient checks include synthetic
  email and password canaries in buyer/admin text and TXT transport content.
- Nine injected provider failures per revision, each retaining pending state
  before authoritative recovery; no outage was interpreted as unpaid/cancelled.
- Nine definite credential transport rejections per revision. Receipt fallback
  completed safely; no ambiguous send was treated as a definite rejection.
- Nine accepted-but-unknown credential effects per revision. Each had exactly
  one transport attempt and remained blocked after recovery, including subsequent
  Redis flushes. First credential ACK/full receipt boundaries remain unknown for
  these nine Orders; they are not zero or successful receipt completions.
- Receipt effect retry count was zero. Successor units and the definite-failure
  fallback are distinct ledger units, not retries of an accepted send. Raw
  per-unit state/attempt counts preserve the failed original and sent fallback.
- Redis-loss batches went from nine queued wakeups to zero; recovery restored
  queued work and all three paid Orders per batch completed receipts. Recovery
  also revisits earlier durable fixture work: total restored wakeups were
  103/103/102 baseline and 103/103/98 integrated, not merely the three new receipts.

A privacy scan of retained raw JSON rejected raw Order/Payment/Customer/chat/
message identifiers and payload/password/token fields. Neither file contains
synthetic email addresses, passwords, bot token or provider signature values.
This proves the fixture/artifact boundary for this dataset, not universal secret
leak freedom for every production payload. Original artifacts are local only.

### Admin unresolved policy

`AdminPaidOrderOutcome::ESCALATION_SECONDS` remains 120. It is an escalation
policy for an unresolved outcome, not a mandatory delay for every paid Order and
not automatically a latency regression. An unknown/terminal intervention can
notify earlier; a still-pending fulfillment may legitimately reach 120 seconds.
The existing 119/120-second regression was exercised in the focused suite. No
threshold, financial authority, ambiguous-send barrier or message contract was
changed to improve these measurements.

## Resource and provider tradeoffs

| Measured topology | Idle workers | Peak sum of sampled worker RSS | Burst workers | Peak sum of sampled worker RSS |
| --- | ---: | ---: | ---: | ---: |
| Historical combined | 2 | 170.68 MiB | 3 | 269.64 MiB |
| Integrated split | 4 | 340.01 MiB | 6 | 526.34 MiB |

RSS sums include shared mappings and are not cgroup physical memory. These
controlled runs include outbound workers but not PHP-FPM, all production rails,
broadcast/stock/supplier workers or a running scheduler. More workers consume
more memory/DB connections and can increase CPU contention even when queue
blocking improves. Do not infer headroom under the 896 MiB production cap.

Provider tradeoff: active/history fairness reduced reads for this backlog,
while history still progressed. The Check burst coalesced worker reads, but this
dataset does not establish live provider rate-limit capacity. No intervals,
financial retries or provider authority were weakened. #654's independently
measured constrained-CPU cycle over one minute remains a residual scheduling
risk; this gate does not claim all scheduler cycles now finish within a minute.

## Child dispositions and verification

All prerequisite issues were closed before the final run. Their implementations
are included in the integration revision, not alternative parallel mechanisms:

| Issue | Integrated commit | Owner/disposition |
| --- | --- | --- |
| [#649](https://github.com/Alhiefikri/telegram-bot/issues/649) | `ca408381` | Timing contract and bounded runner; immutable comparison base |
| [#650](https://github.com/Alhiefikri/telegram-bot/issues/650) | `45e9d902` | Disjoint active/history budgets and cursors |
| [#651](https://github.com/Alhiefikri/telegram-bot/issues/651) | `3d5f72ab` | Receipt/payment worker isolation and persisted queue cutover |
| [#652](https://github.com/Alhiefikri/telegram-bot/issues/652) | `f1f1fd70` | Optional fenced pin after receipt/admin wakeup |
| [#653](https://github.com/Alhiefikri/telegram-bot/issues/653) | `f0b0b6f2` | Unique queued provider Check outside chat lock |
| [#654](https://github.com/Alhiefikri/telegram-bot/issues/654) | `e4c02ac5` | Bounded/fair isolated reconciliation; constrained-CPU cycle limit retained |
| [#655](https://github.com/Alhiefikri/telegram-bot/issues/655) | `19bb4cff` | Batched outcome counts/transient TXT reuse; PNG reuse across durable handoff deliberately not added |

Live Context7 Laravel 13 documentation informed
[queue after-commit/unique-job handling](https://github.com/laravel/docs/blob/13.x/queues.md)
and [stray-request blocking/connection failure fixtures](https://github.com/laravel/docs/blob/13.x/http-client.md).
The ledger remains the recovery/idempotency authority, consistent with ADR-0007,
ADR-0014 and ADR-0031.

Executed local checks:

- Focused receipt/lifecycle/provider/reconciliation/action-panel and measurement
  suites: 195 passed / 1766 assertions. Five topology checks initially lacked
  required root config mounts; with Supervisor/Compose mounted, 5 passed /
  91 assertions. No application test failure was hidden by changing assertions.
- Final measurement subset (`TelegramLatencyBaselineReportTest`,
  `TelegramLatencyBaselineCommandTest`, `TelegramReceiptLatencyTimingTest`):
  13 passed / 60 assertions, overlapping the focused run.
- Disposable PostgreSQL race lane, `vendor/bin/phpunit -c phpunit.concurrency.xml
  tests/Feature/TelegramReceiptPostgresConcurrencyTest.php
  tests/Feature/TelegramCredentialPinPostgresConcurrencyTest.php`:
  3 passed / 35 assertions. The fixture created its own internal network and
  `telegram_latency_test` database and removed them afterward.
- Targeted Pint passed, shell syntax checks passed, LSP diagnostics reported no
  issues in the measurement directory. The actual command comparison above is
  the runtime proof; unit tests alone are not the gate.

Earlier attempts were rejected, not merged into the final statistics: the audit
initially used mutable Customer names; backlog chose the first pending observation
rather than the first successful one; mixed console/stderr output could damage
measurement lines; recovery needed bounded pages; and batch shutdown initially
mistook an admin failure notice for receipt completion. The final harness uses
stable identities, success observations, quiet worker stdout, bounded recovery
pages and explicit receipt-complete/unknown conditions. None of these corrections
changed production business owners. Larger failed runs are not tail evidence.

## Rollout recommendation

Accept the local functional gate for #648's tuning and retain the measured
regressions/resource limits above. Recommend an operator-controlled rollout only
after capacity and queue-state preflight, not an automatic deploy from this task:

1. Follow [queue topology/cutover](telegram-queue-topology.md#cutover-and-drain)
   and [the persisted-work runbook](runbook-telegram-outbound-effect-contract.md#dedicated-receipt-queue-cutover-651).
   Drain reserved work; dry-run then apply queue metadata/pending/delayed-job
   migration. Never retarget a reserved job or reset an unknown delivery.
2. Measure actual cgroup peak, host CPU/memory and DB connections under the full
   production-equivalent process mix before approving the existing memory cap.
   Do not merge receipts back into notifications to hide a capacity problem.
3. Keep active/history fairness, separate verification workers, durable recovery,
   pin isolation and the 120-second unresolved policy. Inspect failed/unknown
   outcomes through the existing operator workflow, never blind resend.
4. If production latency/SLO evidence is wanted, authorize a separate bounded
   production measurement. This local gate does not grant that permission.

No CI/CD run was inspected or dispatched, no deployment occurred, and no
persistent monitor/cron was installed. Queue docs now name the #653 verification
producer and link this evidence; the existing cutover procedure is unchanged,
and no rollout command was run.
