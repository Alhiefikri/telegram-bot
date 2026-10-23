# Paid receipt latency baseline and timing contract

Issue #649 (part of #648). This is local measurement and instrumentation, not a
production monitor, not a production worker-count claim, and not a production SLO.

## Why

Observed local evidence (command, environment, dataset, cold/warm tables and
counts): [telegram-latency-baseline-evidence.md](telegram-latency-baseline-evidence.md).


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

Issue #652 adds separate pin boundaries. They are reported outside the receipt
completeness set because a failed pin is allowed to leave no pin timestamp:

| Pin boundary | Meaning |
| --- | --- |
| `pin_queue_wait_ms` | Pin job enqueue to pin worker pickup. |
| `pin_duration_ms` | Pin attempt duration, including the fixture transport. |
| `pin_finished_after_receipt_completion_ms` | Positive means the pin finished after the receipt group completed. |
| `pin_finished_after_admin_ack_ms` | Positive means the pin finished after the admin paid notice was acknowledged. |

`pin-dispatched` / `pin-job-picked-up` / `pin-finished` events stay correlated
by the existing HMAC order digest and never include a chat ID, message ID or
credential value.

Button ACK is separate from action completion:

- `dogfood.telegram.webhook_ack` carries `ack_kind` (`callback_query`/`message`)
  and `action_state`; it measures the webhook response only.
- `dogfood.telegram.handler_finished` carries `action_completed_at`; it measures
  the queued handler/action result.

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
| `dogfood.telegram.credential_pin_dispatched` | `ReceiptDeliveryPipeline` | `pin_enqueued_at`, sequence, correlations |
| `dogfood.telegram.credential_pin_job_picked_up` | `PinTelegramCredentialReceipt` | enqueue/pickup timestamps, `queue_wait_ms`, unknown flag |
| `dogfood.telegram.credential_pin_finished` | `TelegramCredentialPinService` | `pin_started_at`, `pin_finished_at`, `pin_duration_ms`, `pin_outcome` (`pinned`/`failed`/`ineligible`/`claim_contended`/`claim_lost`) |

Correlations are keyed HMAC digests. Credentials, tokens, chat identifiers and
message payloads are never logged.

Framework behavior was checked against the Laravel 13 queue documentation for
[`queue:work`, worker signals and `--max-time`](https://laravel.com/docs/13.x/queues#the-queue-work-command)
and [clearing jobs](https://laravel.com/docs/13.x/queues#clearing-jobs-from-queues),
and the logging documentation for
[channels and listeners](https://laravel.com/docs/13.x/logging#writing-log-messages)
(the Context7 MCP endpoint was not mounted in this environment).

## Local baseline runner

Prerequisites: Docker with the application image (the same image used for a
local/production-equivalent run) and no production credentials in the shell.
The script starts disposable PostgreSQL 16 and Redis 7 containers, applies all
migrations, mounts the current `src/` working tree and removes the containers on
exit. The application image must already exist:

```bash
docker build -t telegram-bot:local .
LATENCY_BASELINE_IMAGE=telegram-bot:local \
  scripts/telegram-latency-baseline.sh --batches=3 --samples=5 --warmup-batches=1
```

The same runner can be called inside an already isolated stack:

```bash
php artisan telegram:latency-baseline \
  --confirm-disposable \
  --scenario=all \
  --batches=3 --samples=5 --warmup-batches=1 \
  --worker-topology=split \
  --output=/artifacts/run
```

### Guards

The command refuses to run unless all of these hold:

- `APP_ENV` is `local` or `testing`;
- the default database connection is PostgreSQL and its database name contains
  `baseline` or `test`;
- the queue connection and cache store are Redis;
- `DOGFOOD_TIMING_ENABLED=true`;
- the fake Telegram transport is enabled (`DOGFOOD_FAKE_TELEGRAM_OUTBOUND=true`);
- a synthetic numeric admin chat target and Telegram token exist;
- `--confirm-disposable` is passed.

The script also blanks provider credentials from the container environment, so a
local `.env` cannot trigger real provider calls. Provider and Telegram transports
are local fixtures; no real payment or customer message is created.

### Scenarios

Each measured order goes through the real checkout/order models, the real paid
lifecycle, the real receipt effect pipeline and the real admin notice pipeline.
The queues are real Redis queues consumed by real `queue:work` processes
according to the selected topology, not `Queue::fake` or a synchronous
in-process call.

| Scenario | Dataset / seed | Delay fixture | Worker |
| --- | --- | --- | --- |
| `idle` | One local-stock order per sample | none beyond the fixture provider | Redis, queue `notifications`, sleep 3 s, 3 tries, 60 s job timeout |
| `backlog-expired` | `--backlog` older KlikQRIS payments whose deadline already passed; they stay first in the active-class cursor, so the measured payment is observed after at least one full backlog sweep | none | same |
| `slow-notification` | One expired admin notification job ahead of the measured orders | fake Telegram latency ≥ 500 ms on the notification worker only, so the unrelated notification holds that worker | split: receipts and notifications; combined: one shared worker |
| `slow-payment` | One local payment-queue probe followed by one real `/start` Telegram update; measured orders run in parallel | probe occupies the payments queue for 3 s, no provider call | split: default and payments are separate; combined: one `payments,default` worker |
| `slow-provider` | Same order dataset | KlikQRIS fixture status delay ≥ 500 ms | same as receipt topology |
| `slow-telegram` | Same order dataset | fake Telegram latency ≥ 500 ms | same as receipt topology |
| `pin-normal` | Same order dataset; the run waits for the pin event as well as the admin acknowledgement | none | split: `telegram-pins` has its own worker |
| `pin-slow` | Same order dataset | fake `pinChatMessage` delay `--pin-latency-ms` (default 3000 ms), only on the pin worker | same as `pin-normal` |
| `pin-failed` | Same order dataset | fake `pinChatMessage` returns HTTP 400; the receipt and admin paths keep their normal fixture cost | same as `pin-normal` |
| `pin-recovery` | Same as `pin-failed`, then the runner stops the workers and calls `telegram:recover-outbound` twice with the failure disabled | first recovery pins; second recovery must make zero calls and leave the pointer unchanged | same as `pin-normal` |

Worker topology is selected with `--worker-topology=split|combined`:

- `split` starts one worker per queue: `telegram-receipts`, `telegram-pins`,
  `notifications`, `default` and `payments` (the latter two only when the
  scenario has an interaction probe). This is the runtime topology used by the
  application.
- `combined` reproduces the pre-cutover head-of-line blocking by serving
  `notifications,telegram-receipts,telegram-pins` in one worker and
  `payments,default` in another. It exists only for comparison and is not a
  supported runtime topology.

The default provider fixture reports `SUCCESS` for a synthetic order with a
recorded `paid_at` two seconds before observation. In `backlog-expired`, older
synthetic backlog reports `PENDING` and consumes the command's `max-payments`
budget first (`--backlog` rows on sweep 1, one candidate on later sweeps), so the
`provider_observation_ms` boundary includes the real bounded backlog sweep; all
per-class cursors are reset once per sample and the fixture never calls the
network. Credentials are synthetic structured accounts. The report records
`provider_status_delay_ms`, `telegram_latency_ms`, backlog size, worker
queue/sleep/tries/timeout, dataset shape, and `provider_paid_at` /
`observation_started_at` / `observation_finished_at`; it never records
credentials, tokens, chat payloads or real delivery.

### Bounds

`--batches` 1–10, `--samples` 1–25, `--warmup-batches` 0–3,
`--interval-ms` 0–10000, `--timeout-seconds` 5–300, `--worker-sleep` 0–5,
`--backlog` 0–50, `--pin-latency-ms` 100–10000. `--worker-topology` is `split`
or `combined`. The worker exits by `--max-time`, the runner stops it at the
end of every scenario, and the script removes containers/network. No cron,
scheduler or long-running monitor is installed.

## Report

Artifacts are written next to the command's `--output` (the shell script uses
`artifacts/telegram-latency-baseline/<run-id>/`, which is git-ignored):

- `report.md` — human-readable tables and limits;
- `report.json` — summaries plus scenario dataset/worker/fixture metadata;
- `raw-samples.json` — sanitized per-sample boundaries.

`raw-samples.json` contains correlation digests, boundary durations, enqueue
stage/reason, effect-state counts, order status, failed-job counts and missing
boundary names. It never contains an Order ID, customer ID, chat ID, credential,
token, message body or document content.

Percentiles are nearest-rank over available samples. Missing values are counted
as `unknown`, not zero. Warm-up batches are reported as cold start and excluded
from the warmed percentiles. The runner exits non-zero when a warmed sample has
an incomplete boundary set; a failed measurement is not silently converted into
a passing one.

## Limits

- This is a local synthetic baseline over disposable PostgreSQL/Redis. It is not
  production latency and does not prove a production root cause.
- Percentiles from a small run, including the original three review samples, are
  not production SLOs. Use repeated warm runs on the same image/topology for
  before/after comparisons (#656).
- A Telegram Bot API acknowledgement is not a read receipt.
- The fixture provider is not custody/provider qualification; it only exercises
  the application owners with bounded latency and deterministic status.
- The runner does not test webhook ingress ACK latency; it measures the payment
  observation → receipt → admin-notice chain. Button ACK remains separated in
  the existing webhook/handler events.
- Worker peak RSS is sampled from local `queue:work` processes and includes
  shared mappings and synthetic invoice rendering; it is not `memory.current`
  and not proof of production cgroup headroom.
- `combined` exists only to reproduce the old shared-worker shape. It is not a
  deployable topology.
- The `slow-payment` probe is a local queue occupant, not a real provider
  verification call; it measures update pickup behind a payments backlog, not
  provider latency.
