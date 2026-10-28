# Telegram queue and worker topology

Issue #651 (part of #648). This note records the supervised queue ownership,
the cutover/drain sequence, timeout consistency and the local resource
observation. It is not a production capacity guarantee or a production SLO.

## Ownership

| Queue | Producer | Supervised worker | Settings |
| --- | --- | --- | --- |
| `default` | `ProcessTelegramUpdate` from the webhook/recovery ingress | `laravel-update-worker` | sleep 1 s, tries 3, timeout 60 s |
| `payments` | KlikQRIS webhook wakeup, customer-cancellation reconciliation, unique `VerifyProviderPaymentStatus` for KlikQRIS/historical Pakasir Check (#653) | `laravel-payment-verification-worker` | sleep 1 s, tries 3, timeout 60 s |
| `telegram-receipts` | Paid receipt units, successors, text fallback, TXT download, action panel | `laravel-receipt-worker` | sleep 1 s, tries 3, timeout 60 s |
| `notifications` | Credential email, admin Order/review notices, payment proofs, Sales Proof, Store Balance notices, cleanup jobs | `laravel-notification-worker` | sleep 3 s, tries 3, timeout 60 s |
| `telegram-outbound` | Checkout instructions and other interaction effects | `laravel-outbound-worker` | sleep 1 s, tries 3, timeout 60 s |
| `telegram-broadcast` | Admin broadcasts, price notifications | `laravel-broadcast-worker` | sleep 1 s, tries 3, timeout 60 s |
| `telegram-stock` | Stock recipient notifications | `laravel-stock-worker` | sleep 1 s, tries 3, timeout 60 s |
| `binance-verification` | Binance amount/Order-ID verification | `laravel-binance-verification-worker` | sleep 3 s, tries 3, timeout 120 s |
| `suppliers` | Supplier acquisition on the `supplier` connection | `laravel-supplier-worker` | sleep 1 s, tries 1, timeout 120 s |

`QueueTopology` is the shared name list. `/etc/supervisor/conf.d/supervisord.conf`
is the runtime owner; `QueueConsumerTopologyTest` fails when an operational
queue has no consumer, when receipt work shares another worker, when payments
share `default`, or when a worker timeout is not below the Redis
`retry_after`.

### Why the split

Laravel's `queue:work --queue=a,b` chooses the next job by queue order, but it
does not preempt a job that is already running. Sharing `notifications` let a
slow email/admin/proof job delay the next receipt unit; sharing
`payments,default` let a payment-verification backlog delay bot updates.

The receipt family is `receipt.credentials`, `receipt.order_actions` and
`receipt.txt_download`. They stay on the ordered ledger protocol. The
dispatcher derives the receipt queue from the effect type, so recovery,
successor dispatch, fallback and explicit resend cannot route a receipt back to
`notifications` even if a persisted row still carries the old value. The
cutover command normalizes that metadata for operators and review screens.

Email, admin notices, proofs, Sales Proof and receipt cleanup remain on
`notifications`; they are not moved or silently dropped. `default` still owns
webhook-driven Telegram updates. No credential value is added to a queue
payload, cache entry or new table.

Issue #668 removes automatic credential receipt pins entirely, superseding
the pin feature from #652. Delivery and recovery do not enqueue
`PinTelegramCredentialReceipt` or call `pinChatMessage`; there is no
`telegram-pins` consumer or operational health requirement. Receipt `sent`,
ordered successors, admin wakeup, unknown/manual-review handling and
authorized List Order/detail access remain unchanged. Existing pins are not
unpinned; historical pin pointer/claim columns are retained but unused.

## Timeout and retry consistency

The Compose runtime sets `REDIS_QUEUE_RETRY_AFTER=180`. Every supervised
`--timeout` is below it (maximum 120 s for supplier/Binance verification; 60 s
for interactions, payments and receipts). `tries=3` is explicit on every
worker; `suppliers` uses `tries=1` because its job owns a 120 s purchase lease
and its connection has an independent 180 s `retry_after`.

The receipt worker keeps one process (`numprocs=1`). A receipt unit is delivered
by one claim; additional workers would only compete for the same ordered
ledger. No queue gets a second process without a measured memory and database
connection budget.

## Cutover and drain

The full command sequence is in
[the outbound-effect runbook](runbook-telegram-outbound-effect-contract.md#dedicated-receipt-queue-cutover-651).
The short form:

1. Record queue depth including reserved and delayed jobs.
2. Gracefully stop `laravel-notification-worker:*` and let its current job
   finish. Stop the old `payments,default` worker only after its current job
   finishes.
3. `php artisan telegram:receipt-queue-cutover --from=notifications --dry-run --json`
4. Run the same command without `--dry-run`.
5. Start the new workers, run `php artisan queue:restart`, then
   `php artisan ops:health --db --queue-schema --queue-depth --supervisor`.

The command moves persisted receipt effect metadata and pending/delayed Redis
or database wakeups. It never moves a reserved Redis wakeup away from its
owner; the source worker finishes or the job returns after `retry_after`, and
a second cutover run moves it. A lost wakeup is still recoverable from
PostgreSQL through `telegram:recover-outbound`; the ledger, claim token and
sequence remain the delivery authority.

Rollback is the same command in reverse (`--from=telegram-receipts
--to=notifications`) with the receipt worker stopped and the previous
application image deployed. It does not change a receipt's state, message
identity, sequence or ambiguity record; the current dispatcher routes receipt
families by type, so metadata alone cannot undo the code cutover.

### Automatic pin retirement (#668)

Before deploying code that removes the pin job class, stop all old pin
producers/recovery and the dedicated worker gracefully, including in-flight
jobs. Inspect and selectively remove only retired
`App\Jobs\PinTelegramCredentialReceipt` payloads from ready, delayed, reserved
and failed storage; never purge an entire queue or Redis database. Preserve
unrelated jobs and the receipt ledger. Follow the complete
[targeted retirement plan](runbook-telegram-outbound-effect-contract.md#automatic-credential-pin-retirement-668);
`queue:restart` alone is not a producer/worker stop barrier.

## Local resource observation

The latency baseline records the sampled peak RSS of every `queue:work`
process. The 2026-10-22 disposable runs measured:

| Topology / scenario | Workers | Peak RSS per worker | Sum of sampled peaks |
| --- | ---: | ---: | ---: |
| split, `slow-notification` | 2 (receipts, notifications) | 90.5 MB / 96.9 MB | 187.3 MB |
| split, `slow-payment` | 4 (receipts, notifications, interactions, payments) | 80.3–99.0 MB | 367.9 MB |
| combined, receipt `slow-notification` | 1 | 99.1 MB | 99.1 MB |
| combined, `slow-payment` | 2 | 98.3–99.4 MB | 196.3 MB |

The checked-in app container is capped at 1.50 CPU / 896 MiB and also runs
PHP-FPM (`pm.max_children=6`) plus nine configured supervised queue-worker
processes after #668. Removing `laravel-pin-worker` (`numprocs=1`) reduces the
local configured process count by one compared with #652. It is not a claim
about deployed production process count, CPU savings or memory savings.
The table is a peak RSS observation, not `memory.current`: it includes shared
file-backed and opcache mappings per process, so the arithmetic sum overstates
physical container usage. It is also a local synthetic workload that renders
invoices; the historical measurements are unchanged.

Do not treat this table as proof the existing 896 MiB cap has headroom. Before
any production rollout, capture `docker stats --no-stream` and the app cgroup
peak under the real mix, and record PostgreSQL connection counts. If the app
cgroup cannot fit the two additional workers, do not increase `numprocs` and do
not merge receipts back into `notifications` or payments back into `default`;
deploy the receipt worker in a separate bounded container or raise the app
memory cap after measuring the peak. This change adds no production monitoring
or deployment action.

The [integrated #656 gate](telegram-latency-gate-evidence.md) retains raw samples,
baseline/integration source manifests and worker RSS from the completed tuning.
It does not establish cgroup headroom or authorize the cutover steps above.


## Verification

- `QueueConsumerTopologyTest` parses `supervisord.conf` and checks consumers,
  receipt isolation, payments/interaction isolation and timeout margins.
- `TelegramReceiptQueueCutoverTest` covers persisted effect metadata, database
  queued jobs, delayed/reserved reporting, dry-run, and the dispatcher routing
  a legacy persisted `notifications` receipt row to `telegram-receipts`.
- `TelegramReceiptPostgresConcurrencyTest` races two workers on one multi-chunk
  receipt wakeup and asserts each chunk is sent exactly once, in sequence, with
  no attempt consumed by the losing worker.
- The #652 pin-isolation tests and
  `pin-normal`/`pin-slow`/`pin-failed`/`pin-recovery` scenarios are retired by
  #668. Their [historical evidence](telegram-latency-baseline-evidence.md#issue-652-pin-isolation)
  remains unchanged and does not describe current workers or acceptance.
- A disposable Redis smoke moved two receipt wakeups (one pending, one delayed)
  from `notifications` to `telegram-receipts`, left the admin notice in place,
  and rewrote only the receipt effect metadata. See
  [the latency evidence](telegram-latency-baseline-evidence.md#issue-651-queue-isolation).
