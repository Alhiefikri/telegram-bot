# Paid receipt latency baseline evidence — 2026-10-22

Issue #649. Local synthetic baseline only; this is not production latency, a
production SLO, a release budget, or proof of a production root cause. No CI,
deploy, production probe, real payment or customer message was involved.

## Command

```bash
LATENCY_BASELINE_IMAGE=telegram-bot:php-security-8.4.27 \
LATENCY_BASELINE_SCENARIO=all \
scripts/telegram-latency-baseline.sh \
  --batches=3 --samples=5 --warmup-batches=1 \
  --interval-ms=250 --timeout-seconds=90 \
  --worker-sleep=3 --backlog=10
```

Exit code `0`. Wall time ~176 s. Artifacts (git-ignored):
`artifacts/telegram-latency-baseline/20261022T135120Z-197513/`
(`report.md`, `report.json`, `raw-samples.json`, run id
`20261022T135132Z-39e5d48f`).

## Environment

| Item | Value |
| --- | --- |
| Application image | `telegram-bot:php-security-8.4.27` |
| PHP | 8.4.27 |
| Laravel | 13.34.0 |
| App env | `local` |
| Database | disposable PostgreSQL 16.15 container |
| Queue/cache | disposable Redis 7.4.11 container, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis` |
| Worker | one real `queue:work redis --queue=notifications --sleep=3 --tries=3 --timeout=60 --max-time=180` |
| Timezone | Asia/Jakarta |
| CPU | 8 logical CPUs |
| PHP memory limit | 128M |

The script starts fresh PostgreSQL/Redis containers, runs all migrations, mounts
the current `src/` tree, blanks provider credentials, and removes the containers
on exit. The queue/cache database is disposable and is cleared per scenario.

## Dataset and fixtures

Each scenario: 1 warm-up batch (cold) + 3 measured batches (warm), 5 orders per
batch = 20 orders; 100 orders total. Each order is created through the real
checkout, has one local Product Stock Item, one pending KlikQRIS Payment, and is
observed/payed through the real application owners.

| Scenario | Additional seed | Delay fixture |
| --- | --- | --- |
| `idle` | none | none |
| `backlog-expired` | 10 older KlikQRIS backlog Payments (pending status, deadline passed) ahead of the measured payment in the real `klikqris:reconcile` active-class sweep | none |
| `slow-notification` | 1 expired admin notification job ahead of the measured orders | fake Telegram 500 ms |
| `slow-provider` | none | KlikQRIS fixture status delay 500 ms |
| `slow-telegram` | none | fake Telegram 500 ms |

The KlikQRIS fixture is a local adapter implementing the real provider contract;
it performs no HTTP. Backlog rows report `PENDING`, measured rows report
`SUCCESS` with a recorded `paid_at` two seconds before observation. Receipts use
synthetic structured credentials and the fake Telegram transport; no real bot
token or chat is used.

## Results (warm runs, n=15 per boundary)

All 15 warm samples per scenario completed every boundary. Each scenario also
had 5 cold samples; they are reported separately and excluded here.

### idle

| Boundary | p50 ms | p95 ms | max ms |
| --- | ---: | ---: | ---: |
| provider observation | 0.54 | 1.28 | 1.28 |
| provider paid_at → observation | 2392.04 | 2731.57 | 2731.57 |
| observation → paid commit | 77.27 | 98.35 | 98.35 |
| paid commit → enqueue | 1.60 | 2.81 | 2.81 |
| queue wait | 1450 | 2355 | 2355 |
| pickup → first credential ACK | 212.31 | 268.58 | 268.58 |
| pickup → receipt completion | 213.65 | 270.39 | 270.39 |
| receipt completion → admin notice ACK | 1724.53 | 2665.63 | 2665.63 |
| observation → admin notice ACK | 4000.80 | 4789.46 | 4789.46 |

### backlog-expired

| Boundary | p50 ms | p95 ms | max ms |
| --- | ---: | ---: | ---: |
| provider observation (includes two bounded history sweeps) | 277.38 | 316.59 | 316.59 |
| provider paid_at → observation | 2709.03 | 3104.54 | 3104.54 |
| observation → paid commit | 74.21 | 92.79 | 92.79 |
| paid commit → enqueue | 1.92 | 3.21 | 3.21 |
| queue wait | 2455 | 4311 | 4311 |
| pickup → first credential ACK | 252.53 | 282.58 | 282.58 |
| pickup → receipt completion | 255.01 | 284.28 | 284.28 |
| receipt completion → admin notice ACK | 1770.10 | 4846.59 | 4846.59 |
| observation → admin notice ACK | 5093.71 | 6887.59 | 6887.59 |

The observation boundary is dominated by two `klikqris:reconcile` invocations
(command bootstrap plus 10 backlog rows on the first sweep, then only the
target). Every measured payment took exactly two sweeps (`observation_sweeps`
`2`); the cost is command/runtime overhead, not provider HTTP. This run predates
#650; since #650 all per-class cursors are reset per observation, and these
pending backlog rows still fill the active-class budget first, so the two-sweep
shape is expected to remain for this dataset.

### slow-notification

| Boundary | p50 ms | p95 ms | max ms |
| --- | ---: | ---: | ---: |
| provider observation | 0.40 | 0.90 | 0.90 |
| provider paid_at → observation | 2517.93 | 2858.91 | 2858.91 |
| observation → paid commit | 70.99 | 90.43 | 90.43 |
| paid commit → enqueue | 1.36 | 2.45 | 2.45 |
| queue wait | 14731 | 22457 | 22457 |
| pickup → first credential ACK | 670.93 | 785.23 | 785.23 |
| pickup → receipt completion | 672.03 | 787.01 | 787.01 |
| receipt completion → admin notice ACK | 21925.32 | 24796.36 | 24796.36 |
| observation → admin notice ACK | 37400.43 | 43231.30 | 43231.30 |

The unrelated 500 ms-per-call notification and the receipt/admin traffic share
the single `notifications` worker. Queue wait and admin delay reflect that
contention, not a provider delay.

### slow-provider

| Boundary | p50 ms | p95 ms | max ms |
| --- | ---: | ---: | ---: |
| provider observation | 500.75 | 501.31 | 501.31 |
| provider paid_at → observation | 2958.92 | 3450.20 | 3450.20 |
| observation → paid commit | 96.08 | 119.87 | 119.87 |
| paid commit → enqueue | 2.03 | 4.23 | 4.23 |
| queue wait | 2632 | 6301 | 6301 |
| pickup → first credential ACK | 240.12 | 4326.36 | 4326.36 |
| pickup → receipt completion | 243.36 | 4328.54 | 4328.54 |
| receipt completion → admin notice ACK | 1780.72 | 2294.52 | 2294.52 |
| observation → admin notice ACK | 4715.46 | 9508.89 | 9508.89 |

### slow-telegram

| Boundary | p50 ms | p95 ms | max ms |
| --- | ---: | ---: | ---: |
| provider observation | 0.38 | 0.61 | 0.61 |
| provider paid_at → observation | 2538.47 | 2950.15 | 2950.15 |
| observation → paid commit | 62.71 | 79.65 | 79.65 |
| paid commit → enqueue | 1.56 | 2.37 | 2.37 |
| queue wait | 14186 | 22785 | 22785 |
| pickup → first credential ACK | 661.94 | 676.98 | 676.98 |
| pickup → receipt completion | 663.08 | 678.29 | 678.29 |
| receipt completion → admin notice ACK | 21088.73 | 24371.42 | 24371.42 |
| observation → admin notice ACK | 36000.35 | 41428.25 | 41428.25 |

The first credential ACK itself carries the 500 ms fake transport delay plus
render/landing work. The admin notice is withheld until the receipt outcome is
resolved, so the completion→notice boundary includes the worker queue behind
the receipt effect chain.

## Counts

| Scenario | Warm complete | Warm incomplete | Failed jobs | Retried samples | Duplicate samples | Queue-wait unknown |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| idle | 15 | 0 | 0 | 0 | 0 | 0 |
| backlog-expired | 15 | 0 | 0 | 0 | 0 | 0 |
| slow-notification | 15 | 0 | 0 | 0 | 0 | 0 |
| slow-provider | 15 | 0 | 0 | 0 | 0 | 0 |
| slow-telegram | 15 | 0 | 0 | 0 | 0 | 0 |

Each cold set had 5 complete samples and the same zero failure/retry/duplicate
counts. Every measured order ended with one sent `receipt.credentials`, one sent
`receipt.order_actions`, one sent admin paid notice and one sent credential ACK.

## Cold vs warm

Cold summaries are in `report.md`/`report.json` and were not merged into the warm
percentiles. Cold queue waits and end-to-end values differ materially from warm
values (for example idle e2e p50 2.57 s cold vs 4.10 s warm under this seed and
arrival rate). No percentile from this run is a production SLO.

## Sanitization check

The raw and report artifacts contain only correlations, boundary durations,
enqueue stage/reason, effect-state counts, statuses, missing-boundary names and
environment metadata. Checks over both artifact files found zero occurrences of
invoice numbers, seeded synthetic email/password values, the synthetic bot
token, the synthetic admin chat id, `@example.test`, or credential text. Worker
stderr is parsed for dogfood events and is not persisted.

## Limits

- Local synthetic dataset and local fixture transports; PostgreSQL/Redis were
  disposable and are now gone.
- One worker, one queue. This measures the current shared worker topology, not a
  production worker count or provider mix.
- Telegram Bot API acknowledgement is not a customer read receipt.
- The fixture provider is not custody, provider or finality qualification.
- Queue wait per sample includes the runner's 250 ms arrival interval and one
  shared worker; the per-order end-to-end boundary is the more comparable number
  for #656.

## Follow-up

#656 should rerun this runner (same image, dataset and bounded options) before
and after tuning. Use the resulting raw samples and counts, not this single
run, for before/after claims.

## Issue #651 queue isolation

Issue #651 changed the worker topology, so this section compares the old
shared-worker shape with the supervised split topology using the same local
runner, dataset, fixture and sleep. It is local synthetic evidence, not a
production measurement.

### Commands

```bash
# Shared-worker comparison (measurement only; not a runtime topology)
LATENCY_BASELINE_IMAGE=telegram-bot:php-security-8.4.27 \
LATENCY_BASELINE_SCENARIO=idle,slow-notification \
scripts/telegram-latency-baseline.sh \
  --batches=3 --samples=5 --warmup-batches=1 \
  --interval-ms=250 --timeout-seconds=150 \
  --worker-sleep=3 --worker-topology=combined

# Split runtime topology, same dataset
LATENCY_BASELINE_IMAGE=telegram-bot:php-security-8.4.27 \
LATENCY_BASELINE_SCENARIO=idle,slow-notification \
scripts/telegram-latency-baseline.sh \
  --batches=3 --samples=5 --warmup-batches=1 \
  --interval-ms=250 --timeout-seconds=90 \
  --worker-sleep=3 --worker-topology=split

# Payment verification vs bot update
LATENCY_BASELINE_IMAGE=telegram-bot:php-security-8.4.27 \
LATENCY_BASELINE_SCENARIO=slow-payment \
scripts/telegram-latency-baseline.sh \
  --batches=2 --samples=3 --warmup-batches=1 \
  --interval-ms=250 --timeout-seconds=90 \
  --worker-sleep=1 --worker-topology=combined

LATENCY_BASELINE_IMAGE=telegram-bot:php-security-8.4.27 \
LATENCY_BASELINE_SCENARIO=slow-payment \
scripts/telegram-latency-baseline.sh \
  --batches=2 --samples=3 --warmup-batches=1 \
  --interval-ms=250 --timeout-seconds=90 \
  --worker-sleep=1 --worker-topology=split
```

Run ids: split receipts `20261022T152441Z-258680`; combined receipts
`20261022T152729Z-261341`; split payment `20261022T152729Z-261336`; combined
payment `20261022T152954Z-263887`. All four runs had 15/15 (receipts) or 6/6
(payment probe) complete warm samples and zero failed jobs.

### Receipt isolation (warm samples, n=15)

| Scenario | Queue wait p50 | p95 | e2e observation→admin p50 | p95 |
| --- | ---: | ---: | ---: | ---: |
| `idle`, combined shared worker | 9416 ms | 10613 ms | 10277.87 ms | 11886.47 ms |
| `idle`, split | 2628 ms | 3329 ms | 4770.56 ms | 6676.04 ms |
| `slow-notification`, combined shared worker | 22589 ms | 34497 ms | 24691.41 ms | 36639.52 ms |
| `slow-notification`, split | 2566 ms | 3137 ms | 5591.46 ms | 7252.59 ms |

The split `slow-notification` queue wait is statistically the same as split
`idle` (2566 ms vs 2628 ms p50) even though the unrelated notification worker
is 500 ms slower per Telegram call. The combined worker remains blocked:
22589 ms p50. The earlier #649 run (same shared worker, 15 samples) measured
14731 ms p50, so this is a real contention shape, not a one-off.

In split `slow-notification`, pickup→first credential ACK was 352.21 ms p50
(378.47 p95) and receipt completion→admin notice was 2544.24 ms p50. The admin
notice still waits on the slower notification worker; that is separate from
receipt pickup and completion.

### Payment verification vs bot update

Each scenario enqueues a local `SlowPaymentVerificationProbe` for 3000 ms on
`payments`, waits for the probe to start, then posts a real `/start` update to
`ProcessTelegramUpdate` on `default`. The probe performs no provider call.

| Topology | Update enqueue→pickup | Pickup→processed | Complete |
| --- | ---: | ---: | --- |
| combined `payments,default` | 3665 ms | 251.49 ms | yes |
| split `payments` / `default` | 2198 ms | 401.22 ms | yes |

The split update does not wait for the 3 s probe; the remaining ~2.2 s is the
worker's `--sleep=1` poll boundary plus update handling, not payment backlog.
The combined topology has one worker, so the update waits until the probe
finishes.

### Cutover smoke (disposable Redis/PostgreSQL)

A disposable PostgreSQL 16 / Redis 7 stack was migrated, seeded with one
`receipt.credentials` effect on `notifications`, one admin paid effect, one
pending `SendTelegramReceipt`, one delayed `SendTelegramReceipt` and one admin
job. `telegram:receipt-queue-cutover --from=notifications --dry-run --json`
reported `effects.matched=1`, `queued.pending=1`, `queued.delayed=1`,
`queued.reserved=0` and changed nothing. The real run moved the receipt effect
to `telegram-receipts`, left `notifications=1` (the admin job), and moved the
delayed entry to `queues:telegram-receipts:delayed`. The admin effect remained
on `notifications`. No receipt job remained on `notifications`. The containers
were removed afterward; no deployment, production Redis or customer message was
involved.

### Multi-worker receipt correctness

`TelegramReceiptPostgresConcurrencyTest` (PostgreSQL concurrency lane) created a
paid order with 12 structured credentials, materialized a two-chunk receipt
group, and raced two workers on the same receipt wakeup. Each chunk plus the
completed action panel was sent exactly once; the credential chunk sequences
were `1, 2`; every effect kept `attempts = 1`; the losing worker consumed no
claim attempt; `orders.telegram_receipt_sent_at` was set. Result: 1 test,
18 assertions, passed. This exercises duplicate wakeup and claim fencing with
two processes; ordered chunk delivery is also covered by the existing
`TelegramReceiptOutboundEffectTest` ambiguity/ordering cases. Redis-loss
recovery is unchanged: `test_recovery_materializes_a_paid_receipt_without_a_legacy_claim`
and the other recovery tests rediscover pending ledger rows, and the type-based
dispatcher routes every receipt back to `telegram-receipts`.

### Worker footprint

| Scenario | Workers | Peak RSS per worker | Sum |
| --- | ---: | ---: | ---: |
| split receipts/notifications | 2 | 90.5–96.9 MB | 187.3 MB |
| split receipts/notifications/interactions/payments | 4 | 80.3–99.0 MB | 367.9 MB |
| combined receipts/notifications | 1 | 99.1 MB | 99.1 MB |
| combined payments/interactions | 2 | 98.3–99.4 MB | 196.3 MB |

These are sampled peak RSS values from local `queue:work` processes in the
disposable app container. They include shared opcache/file mappings and a
synthetic invoice-rendering workload; the sum overstates physical container
usage. They are not proof that the production 896 MiB app cgroup has headroom.
See [the queue topology note](telegram-queue-topology.md#local-resource-observation).

### Limits

- Local synthetic providers/Telegram transports; PostgreSQL and Redis were
  disposable and are gone.
- The split receipt worker used `--sleep=3` in the comparison to isolate
  topology; production uses `--sleep=1`.
- The payment probe is a local synthetic queue occupant, not a real provider
  verification job. It measures queue pickup, not provider latency.
- No production SLO, root-cause claim, deployment, real payment or customer
  message is implied.

## Issue #652 pin isolation

Issue #652 moves the optional credential pin from an inline call inside
`ReceiptDeliveryPipeline` to a `PinTelegramCredentialReceipt` job on the
dedicated `telegram-pins` worker. The receipt is persisted as `sent`, the
successor/cleanup handoffs run, and the admin wakeup is queued before the pin
is enqueued. This run compares normal, slow, failed and recovered pins on the
same local runner, dataset and fake Telegram transport.

### Command

```bash
LATENCY_BASELINE_IMAGE=telegram-bot:php-security-8.4.27 \
LATENCY_BASELINE_SCENARIO=pin-normal,pin-slow,pin-failed,pin-recovery \
scripts/telegram-latency-baseline.sh \
  --batches=2 --samples=3 --warmup-batches=0 \
  --interval-ms=250 --timeout-seconds=120 \
  --worker-sleep=1 --worker-topology=split --pin-latency-ms=3000
```

Run id: `20261022T233736Z-14226` (exit `0`). Environment: PHP 8.4.27,
Laravel 13.34.0, PostgreSQL 16.15, Redis 7.4.11, app image
`telegram-bot:php-security-8.4.27`, split topology with real Redis workers for
`telegram-receipts`, `telegram-pins`, `notifications` and `default`. Six warm
samples per scenario, no cold-start batch, 250 ms interval. The runner waited
for both the admin acknowledgement and the pin event in these scenarios.

Dataset: six synthetic paid orders per scenario, one structured local-stock
credential each, real checkout/paid lifecycle/receipt/admin pipelines, the
existing KlikQRIS fixture provider, and the local fake Telegram transport.
`pin-slow` delays only `pinChatMessage` by 3000 ms on the pin worker;
`pin-failed` makes the same call return HTTP 400; `pin-recovery` stops the
workers after six failed attempts and runs `telegram:recover-outbound` twice
with the failure disabled. No real Telegram call, provider call, payment or
customer message was involved.

### Pin boundaries (warm, n=6 per scenario)

| Scenario | Pin attempts | Pinned | Pin duration p50 / p95 (ms) | Pin queue wait p50 / p95 (ms) | Finished after receipt p50 / p95 (ms) | Finished after admin ACK p50 / p95 (ms) |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| `pin-normal` | 6 | 6 | 6.86 / 23.71 | 403 / 960 | 459 / 1018 | -347 / 603 |
| `pin-slow` | 6 | 6 | 3014.59 / 3024.19 | 6179 / 14508 | 9233 / 17558 | 8572 / 16553 |
| `pin-failed` | 6 | 0 | 8.73 / 49.70 | 514 / 932 | 592 / 1182 | 102 / 357 |
| `pin-recovery` | 12 | 6 | 10.13 / 15.98 | 359 / 889 | 2276 / 3664 | 1485 / 2763 |

A positive `finished after` value means the pin finished later than that
boundary. In `pin-slow`, every receipt group completed and every admin notice
was acknowledged 8.5–17.6 s before the corresponding 3 s pin finished. In
`pin-failed`, the receipt and admin paths completed before or independently of
the rejected pin; no Order received a pin pointer (`pinned = 0`,
`pointer_on_credential_effect = 0`). In `pin-recovery`, all six Orders were
unpinned before recovery, the first bounded recovery pinned all six, the second
recovery made zero pin calls (`attempts_second_recovery = 0`), and the recorded
pointer was unchanged (`pointer_unchanged_on_replay = true`).

The pin queue wait grows in `pin-slow` because one pin worker serializes 3 s
pins. That backlog is isolated to `telegram-pins`; it is not receipt work.

### Receipt and admin boundaries (warm, n=6)

| Scenario | Receipt queue wait p50 (ms) | Pickup → first credential ACK p50 (ms) | Pickup → receipt completion p50 (ms) | Receipt completion → admin notice p50 (ms) |
| --- | ---: | ---: | ---: | ---: |
| `pin-normal` | 991 | 222.45 | 223.76 | 664.95 |
| `pin-slow` | 623 | 182.18 | 183.46 | 660.30 |
| `pin-failed` | 1675 | 316.54 | 318.36 | 490.14 |

The 3 s pin does not appear in the receipt or admin boundaries; their p50s are
in the same local range as the normal and failed cases in this run. The
per-scenario receipt suite had 6/6 complete warm samples and zero failed jobs.
These are local synthetic samples, not a production SLO.

### Correctness verification

`TelegramCredentialPinTest` covers deferred dispatch after `sent`, successor
dispatch being queued before the pin work, no pin for an unknown delivery,
exactly-one pin, failed-pin recovery by `telegram:recover-outbound`, replay
after success making no additional call, unknown/foreign/non-credential/
already-pinned effects, the claim lease, and one canonical message across two
sent credential chunks.
`TelegramCredentialPinPostgresConcurrencyTest` races two processes and asserts
exactly one winner, one canonical pointer and stale-claim reclamation.

### Limits

- Six warm samples per scenario, no cold start and no production-equivalent
  hardware; the numbers are not percentiles for an SLO.
- The pin delay/failure and all Telegram responses are local fixtures. The
  fake transport measures queue separation and the application contract, not
  Telegram's real latency. The Context7 MCP endpoint was not mounted in this
  environment, so the queue/after-commit behavior was checked against the
  installed Laravel 13 framework source and the repository's existing
  Context7-cited queue notes rather than a live Context7 query.
- `pin-normal` and `pin-failed` can finish the pin before the admin
  acknowledgement on some samples; only `pin-slow` proves the admin path does
  not wait for a slow pin, and the receipt boundaries prove the same for
  delivery.
- Disposable PostgreSQL/Redis containers and local artifacts only; no CI,
  deploy, production probe, real payment or customer message was involved.
