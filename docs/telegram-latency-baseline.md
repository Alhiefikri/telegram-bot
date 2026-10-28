# Paid receipt latency baseline and timing contract

Issue #649 (part of #648). This is local measurement and instrumentation, not a
production monitor, not a production worker-count claim, and not a production SLO.

## Why

Observed local evidence (command, environment, dataset, cold/warm tables and
counts): [telegram-latency-baseline-evidence.md](telegram-latency-baseline-evidence.md).

Final integrated gate (#656), including retained raw samples and rollout limits:
[telegram-latency-gate-evidence.md](telegram-latency-gate-evidence.md).


The paid receipt path previously recorded queue wait only when a dispatch site
happened to pass an enqueue timestamp. The initial paid handoff passed only the
order ID, so `queue_wait_ms` was empty instead of zero. The missing field was
honest but not measurable across the initial, retry and recovery dispatches.

## Measurement contract

| Boundary | Meaning |
| --- | --- |
| `provider_paid_at` | Provider-reported payment time. It is not when this application noticed it. |
| `application_observed_at` | When the application accepted an authoritative provider status in `KlikQrisCallbackProcessor`. |
| `paid_committed_at` | When the paid lifecycle transaction committed and its post-commit receipt handoff ran. |
| `job_enqueued_at` | When the receipt job was pushed to the queue. |
| `job_picked_up_at` | When a queue worker started `SendTelegramReceipt`. |
| `queue_wait_ms` | `job_picked_up_at - job_enqueued_at`. A missing enqueue timestamp keeps this `null`; it is never converted to zero. |
| `telegram_acknowledged_at` | The Telegram Bot API response for the first credential-bearing receipt unit. This is not proof the customer read the message. |
| `receipt_completed_at` | The receipt effect group reached its complete sent state. |
| `admin_paid_notification_acknowledged` | The Telegram Bot API response for the paid admin notice. |
| `provider_paid_to_observation_ms` | `application_observed_at - provider_paid_at`; proves provider time and application observation time are separate. |

Issue #668 removes automatic credential receipt pins. The #652 pin boundaries
and timing events are retired, not part of the current report or receipt
completeness set. No pin is dispatched or recovered, and no pin completion
timestamp is expected. Receipt completion, admin acknowledgement and authorized
List Order/detail access remain independent of historical pin columns.
The [#652 evidence](telegram-latency-baseline-evidence.md#issue-652-pin-isolation)
and integrated #656 artifacts remain unchanged historical measurements.

Button ACK is separate from action completion:

- `dogfood.telegram.webhook_ack` carries `ack_kind` (`callback_query`/`message`)
  and `action_state`; it measures the webhook response only.
- `dogfood.telegram.handler_finished` carries `action_completed_at`; it measures
  the queued handler/action result.

For KlikQRIS and historical Pakasir Order checks (#653), handler completion
means the current snapshot and verification-pending notice were rendered,
not that provider verification finished. `VerifyProviderPaymentStatus` runs
on the separate `payments` worker. Its
`dogfood.telegram.provider_status_job_picked_up` event records enqueue/pickup
timestamps and queue wait, correlated by Payment digest; authoritative
observation and paid commit retain their existing lifecycle events.
The job does not re-render a saved navigation target after completion.
See the [isolated slow-provider smoke](telegram-latency-baseline-evidence.md#issue-653-status-refresh)
for separately measured ACK, handler completion and provider I/O.

## Enqueue stages

`SendTelegramReceipt` records the stage and reason supplied by its dispatcher:

| Stage | Reasons |
| --- | --- |
| `first_enqueue` | `paid_commit`, `manual_fulfillment_completion`, `supplier_fulfillment_completion`, `effect_dispatch` |
| `retry_enqueue` | `explicit_resend` |
| `recovery_enqueue` | `recovery_sweep` |

The initial paid handoff, manual/supplier fulfillment handoffs, the shared
effect dispatcher and `telegram:recover-outbound` all pass an ISO enqueue
timestamp. An older persisted job or a caller that does not supply one logs
`queue_wait_ms: null`, `queue_wait_unknown: true` and `enqueue_stage: unknown`.

## Timing events

All events use the existing `DogfoodTiming` switch and the existing sanitization
boundary. No second observability system, table or queue is added.

| Event | Emitted by | Key fields |
| --- | --- | --- |
| `dogfood.telegram.payment_observed` | KlikQRIS callback/reconciliation processor | provider status, `provider_paid_at`, `application_observed_at`, observation source, correlations |
| `dogfood.telegram.paid_committed` | Order lifecycle post-commit handoff | `paid_committed_at`, enqueue stage/reason, correlations |
| `dogfood.telegram.receipt_job_picked_up` | `SendTelegramReceipt` | enqueue/pickup timestamps, `queue_wait_ms`, unknown flag, stage/reason |
| `dogfood.telegram.receipt_first_credential_acknowledged` | `ReceiptDeliveryPipeline` | `telegram_acknowledged_at`, sequence, correlations |
| `dogfood.telegram.receipt_completed` | `ReceiptDeliveryPipeline` | `receipt_completed_at`, sequence, correlations |
| `dogfood.telegram.admin_paid_notification_acknowledged` | Admin notification pipeline | `telegram_acknowledged_at`, correlations |

Correlations are keyed HMAC digests. Credentials, tokens, chat identifiers and
message payloads are never logged.

For #656, live Context7 queries checked Laravel 13
[after-commit queues and unique jobs](https://github.com/laravel/docs/blob/13.x/queues.md)
and [HTTP fakes / `preventStrayRequests`](https://github.com/laravel/docs/blob/13.x/http-client.md).
Queue uniqueness remains an optimization; PostgreSQL settlement/effect claims
remain authoritative when Redis and its locks disappear.

## Local baseline runner

Prerequisites: Docker with the application image (the same image used for a
local/production-equivalent run) and no production credentials in the shell.
The script starts disposable PostgreSQL 16 and Redis 7 containers on an internal
Docker network, applies migrations and removes the containers/network on exit.
It uses `APP_ENV=testing`, synthetic credentials and no external network access.
The application image must already exist:

```bash
docker build -t telegram-bot:local .
LATENCY_BASELINE_IMAGE=telegram-bot:local \
  scripts/telegram-latency-baseline.sh --batches=3 --samples=7 --warmup-batches=1
```

The same runner can be called inside an already isolated stack:

```bash
php artisan telegram:latency-baseline \
  --confirm-disposable \
  --scenario=all \
  --batches=3 --samples=7 --warmup-batches=1 \
  --worker-topology=split \
  --output=/artifacts/run
```

### Comparing source revisions (#656)

```bash
scripts/telegram-latency-compare.sh --scenario=all \
  --batches=3 --samples=7 --warmup-batches=1 \
  --interval-ms=250 --worker-sleep=1 --timeout-seconds=90 --backlog=20
```

This runs sequentially, not concurrently. The default baseline is `ca408381`
(#649, before tuning); override with `LATENCY_BASELINE_REVISION`. The baseline
comes from `git archive`; the integration source is a private working-tree
snapshot excluding ENV files, vendor/node_modules and generated caches/logs.
Both use the same read-only installed vendor directory and application image.
The temporary snapshots are removed on exit.

Only the `LatencyBaseline/` measurement directory, its command and the
`QueueTopology` name list are overlaid on the historical source. The baseline's
checkout, provider selection, settlement, receipt, recovery and notification
owners are not replaced. The wrapper records source revisions, dirty file names,
harness hashes, image ID and a manifest of the integration PHP/config/schema
source. It runs the historical shared topology and the integrated split topology.
These controlled local workers are not a production capacity simulation.

Each batch seeds its own measured Orders, starts fresh workers, waits, records
results and stops those workers before the next batch. Warm-up batches remain
separate. The callback burst uses the actual HTTP kernel/route in testing mode,
which dispatches immediately rather than accumulating process-termination hooks.
The same-chat Home result is measured separately from its callback ACK and from
the provider's delayed verification.


### Guards

The command refuses to run unless all of these hold:

- `APP_ENV` is `local` or `testing`;
- the default database connection is PostgreSQL and its database name contains
  `baseline` or `test`;
- the queue connection and cache store are Redis;
- `DOGFOOD_TIMING_ENABLED=true`;
- fixture mode is enabled (`DOGFOOD_FAKE_TELEGRAM_OUTBOUND=true`); the runner
  binds the real `TelegramBotClient` to bounded local HTTP fakes;
- a synthetic numeric admin chat target and Telegram token exist;
- `--confirm-disposable` is passed.

The script blanks provider credentials. Parent and worker processes install
`Http::preventStrayRequests()`; only synthetic Telegram requests are faked, and
the provider fixture implements the existing adapter contract without HTTP.
The internal Docker network also blocks non-Laravel stray traffic. Burst probes
require `APP_ENV=testing`. No real payment or customer message is created.

### Scenarios

Each measured order goes through the real checkout/order models, the real paid
lifecycle, the real receipt effect pipeline and the real admin notice pipeline.
The queues are real Redis queues consumed by real `queue:work` processes
according to the selected topology, not `Queue::fake` or a synchronous
in-process call.

| Scenario | Dataset / seed | Delay fixture | Worker |
| --- | --- | --- | --- |
| `idle` | One local-stock Order per sample, one allocated synthetic credential | healthy fixture | Redis, selected topology; CLI-recorded sleep/tries/timeout |
| `backlog-expired` | `--backlog` terminal Expired Orders/Payments plus active targets | history returns `PENDING`; real reconcile command uses ten requests/sweep | same |
| `slow-notification` | One unrelated expired admin notification ahead of measured Orders | ≥ 500 ms for admin-chat HTTP only, on both revisions; buyer transport stays healthy | split: receipt and notification workers; combined: shared |
| `slow-payment` | One local payment-queue probe followed by one real `/start` Telegram update; measured orders run in parallel | probe occupies the payments queue for 3 s, no provider call | split: default and payments are separate; combined: one `payments,default` worker |
| `slow-provider` | Same order dataset | KlikQRIS fixture status delay ≥ 500 ms | same as receipt topology |
| `slow-telegram` | Same order dataset | fake Telegram latency ≥ 500 ms | same as receipt topology |
| `burst-interactions` | Ten credentials per Order; two Check callbacks followed by Home on the same Customer chat | actual status-refresh owner reaches a 3 s provider fixture before Home is enqueued | split payments/default; combined payments/default |
| `failed-provider` | Provider outage, unchanged pending state, then authoritative recovery on the same Payment | two ≥ 500 ms status attempts, first throws a connection exception | same receipt topology |
| `failed-telegram` | Definite rejection of the first credential-bearing request, then healthy transport | HTTP 400; existing receipt fallback/retry policy is unchanged | same |
| `ambiguous-telegram` | Accepted credential send whose ACK is lost; bounded recovery is replayed | connection exception after fixture acceptance; `unknown` must block blind resend | same |
| `redis-loss` | Commit paid sources with workers stopped; flush queue/cache Redis DBs; invoke existing recovery and start workers | lost wakeups and cache locks, not lost PostgreSQL | same |

Retired by #668: `pin-normal`, `pin-slow`, `pin-failed` and `pin-recovery`.
They are not accepted by the current runner or included in `--scenario=all`.
Their pin-dispatch/pickup/duration events, report metrics,
`--pin-latency-ms` option and `DOGFOOD_FAKE_TELEGRAM_PIN_*` fixture knobs are
removed, without aliases or fallback behavior. Use the immutable #652 evidence
to interpret historical results; do not treat those scenarios as current
acceptance gates.

Worker topology is selected with `--worker-topology=split|combined`:

- `split` starts separate `telegram-receipts`, `notifications` and
  `telegram-outbound` workers, plus `default` and `payments` for interaction
  scenarios. Queue ownership matches the runtime; the CLI sleep is a controlled
  measurement setting, not a claim that all supervised workers use that sleep.
- `combined` shares receipts/notifications, and payments/default where
  needed; outbound stays separate. On historical source the receipt queue
  constant still resolves to `notifications`. This is comparison-only.

The provider reports synthetic `SUCCESS` with `paid_at` two seconds before
the status call. Observation and paid commit remain separate. Older terminal
history reports `PENDING`; future measured targets stay pending until their
own observation. Every sweep has the same ten-request cap. Cursors persist
between samples in a batch, so the report can show history progress as well
as active-target sweep count. Cache/cursors reset at the next batch.

Healthy direct observations are replayed through the same lifecycle processor
without another provider request, checking idempotent settlement and stock.
The report includes main-process status requests, history reads/distinct rows
and separate worker verification reads. Structured credentials are synthetic.
Telegram inspection checks email/password-to-Customer recipient binding in memory,
including admin traffic; only booleans, counts and digests enter timing events.
Automatic receipt attempts are distinguished from authorized detail responses.

### Bounds

`--batches` 1–10, `--samples` 1–25, `--warmup-batches` 0–3,
`--interval-ms` 0–10000, `--timeout-seconds` 5–300, `--worker-sleep` 0–5,
`--backlog` 0–50. `--worker-topology` is `split`
or `combined`. Workers exit by `--max-time` and are stopped after every batch.
The script removes its containers/network. No cron, scheduler or persistent
monitor is installed.

## Report

Artifacts are written next to the command's `--output` (the shell script uses
`artifacts/telegram-latency-baseline/<run-id>/`, which is git-ignored):

- `report.md` — human-readable tables and limits;
- `report.json` — summaries plus scenario dataset/worker/fixture metadata;
- `raw-samples.json` — sanitized per-sample boundaries.

`raw-samples.json` contains HMAC correlations, boundary timestamps/durations,
enqueue stage/reason, per-unit states/attempts, stock/settlement counts,
failed-job counts and missing boundaries. Each batch retains interaction ACK,
queue/handler/completion measurements, resource observations, history progress
and recovery outcomes. It contains no Order/Customer/chat IDs, credentials,
tokens, message bodies or document content.

Nearest-rank p50/max use the declared known sample count; p95 is withheld below
20 known samples, and p99 is never emitted. Missing values count as `unknown`,
not zero. Warm-up batches are excluded from warmed summaries. Successor pickups
are not retries; retries come from effect attempt counts.

An ambiguous delivery intentionally has missing ACK/completion boundaries.
`complete` stays false; only a proven one-attempt `unknown` barrier can be
`expected_blocked`. Other missing warm boundaries, unobserved requested faults
or failed correctness/interaction/recovery invariants make the command fail.
After all scenarios, `final_audit` rechecks every measured Order against the
ledger and transport events, including any later Redis-loss recovery. This
prevents a later blind resend from escaping an earlier scenario's snapshot.

## Limits

- This is a local synthetic baseline over disposable PostgreSQL/Redis. It is not
  production latency and does not prove a production root cause.
- Percentiles from a small run, including the original three review samples, are
  not production SLOs. Use repeated warm runs on the same image/topology for
  before/after comparisons (#656).
- A Telegram Bot API acknowledgement is not a read receipt.
- The fixture provider is not custody/provider qualification; it only exercises
  the application owners with bounded latency and deterministic status.
- Callback ACK timings are HTTP-kernel response timings in a local process, not
  internet/web-server round trips. Handler completion and the resulting Home
  state are separate measurements.
- Worker peak RSS is sampled from local `queue:work` processes and includes
  shared mappings and synthetic invoice rendering; it is not `memory.current`
  and not proof of production cgroup headroom.
- `combined` exists only to reproduce the old shared-worker shape. It is not a
  deployable topology.
- The `slow-payment` probe is a local queue occupant, not a real provider
  verification call; it measures update pickup behind a payments backlog, not
  provider latency.
