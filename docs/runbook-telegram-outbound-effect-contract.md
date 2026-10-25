# Telegram outbound effect contract runbook

Issue #274 is the contract boundary for the Telegram outbound-effect
migration. The ledger is authoritative after the final migration; the old
claim columns are not rollback storage.

## Deployment order

1. Apply only the additive/expand migrations and deploy the compatible
   application code. It must materialize, claim,
   finalize, recover, and project migrated Telegram effects through
   `telegram_outbound_effects` only.
2. After the candidate passes internal and public smoke gates, drain or stop
   old workers, then run:

   ```sh
   php artisan telegram:outbound-contract --json
   php artisan telegram:recover-outbound --queue=telegram-outbound
   php artisan telegram:outbound-contract --strict
   ```

   `preflight_ready` must be true before the migration. A report with
   `missing_effects` or `unsafe_effects` is a blocker; resolve it by creating
   the correct ledger effect or recording the evidence as `unknown` or
   `manual_review`.
3. Run the migrations. The final contract migration drops only the old claim
   columns and their recovery indexes, after its preflight guard passes.
4. Restart long-lived queue workers after deployment:

   ```sh
   php artisan queue:restart
   ```

   Then verify application and queue health:

   ```sh
   php artisan ops:health --db --queue-schema --queue-depth
   php artisan telegram:outbound-contract --strict
   ```

The automatic release workflow applies the final contract only when the
operator explicitly dispatches with `apply_telegram_outbound_contract=true`,
after `scripts/backup-db.sh` creates an encrypted backup and verifies its
OpenPGP packet plus gzip readability. The release records the backup path,
SHA-256 checksum, and `contract-backup-verified.txt` marker in the release
artifact before any recovery or destructive migration. The backup encryption
configuration remains on the VPS; no GitHub token or backup secret is sent
through Actions.

The contract phase starts only after the candidate is healthy and public smoke
has passed. If the input remains false, the compatible candidate is released
with the legacy columns intact. If recovery or the strict preflight remains
blocked, the compatible candidate stays running with the legacy columns intact
for investigation; do not roll application code back after the destructive
contract migration starts.

The restart is required because queue workers are long-lived processes and do
not load the newly deployed application code until restarted.

## Point of no return

After `2026_08_18_000001_contract_telegram_outbound_effects` succeeds, the
legacy claim columns and indexes are gone. Do not restore them by resetting an
ambiguous effect to `pending`, and do not use a rollback migration. Preserve
the ledger row and its `unknown`/`manual_review` outcome; resolve it through
the operator review flow or restore the database from an approved backup.

Historical migrations that introduced the old columns remain in the repository
for reproducible fresh-install history. They are not runtime compatibility
artifacts after the contract migration.

## Dedicated receipt queue cutover (#651)

Receipt credential units, their ordered successors and the text fallback use
`telegram-receipts`, not `notifications`. The durable ledger and
`telegram:recover-outbound` remain the correctness owners; the cutover only
moves wakeups and persisted routing metadata.

1. Record the source queue depths before stopping anything:

   ```sh
   php artisan ops:check-queue-depth --queue=notifications --queue=telegram-receipts --include-reserved-and-delayed
   ```

2. Stop the notification worker gracefully and let its current job finish.
   Do not SIGKILL it. In the Compose runtime, stop
   `laravel-notification-worker:*`; the receipt worker is not started yet.
   If the previous release still serves receipts and payments from the old
   topology, stop `laravel-update-worker:*` after its current job finishes so
   a payment backlog cannot keep consuming default updates.
3. Run the dry run and inspect the counts:

   ```sh
   php artisan telegram:receipt-queue-cutover --from=notifications --dry-run --json
   ```

   `effects.matched` counts persisted receipt effects, and `queued.pending` /
   `queued.delayed` count the queued wakeups that would move. `queued.reserved`
   is reported, never stolen; those jobs belong to an in-flight worker.
4. Run the real cutover:

   ```sh
   php artisan telegram:receipt-queue-cutover --from=notifications --json
   ```

   The command rewrites only `receipt.credentials`, `receipt.order_actions` and
   `receipt.txt_download` metadata. Admin notices, email, payment proofs, Sales
   Proof and cleanup remain on `notifications`. Pending wakeups move atomically
   per payload and the destination queue is notified. A worker that crashes
   while holding a reserved job leaves that job recoverable by the source
   worker after `retry_after`; run the cutover again after the retry appears.
5. Start the new topology and restart long-lived workers:

   ```sh
   php artisan queue:restart
   php artisan ops:health --db --queue-schema --queue-depth --supervisor
   ```

   The supervised queues are `default`, `payments`, `telegram-receipts`,
   `telegram-pins`, `notifications`, `telegram-outbound`, `telegram-broadcast`,
   `telegram-stock`, `binance-verification` and `suppliers`.

If a wakeup is lost, the receipt effect rows are still pending in PostgreSQL.
`telegram:recover-outbound` redispatches them through the type-based dispatcher,
which always routes receipt families to `telegram-receipts`. The same sweep
repairs a sent credential receipt whose optional pin was lost: it applies the
unchanged pin eligibility and claim rules and never resends the receipt. Do not
recreate receipt content or resend an ambiguous unit by hand.

Rollback uses the same command in reverse while the receipt worker is stopped:

```sh
php artisan telegram:receipt-queue-cutover --from=telegram-receipts --to=notifications --dry-run
php artisan telegram:receipt-queue-cutover --from=telegram-receipts --to=notifications
```

Rollback moves wakeups and metadata only. It does not change a receipt's
delivery state, claim token, sequence or ambiguity record. Deploy the previous
application image before a rollback: the current dispatcher routes receipt
families by type and would keep sending them to `telegram-receipts` even after
the metadata is moved back.

## Runtime recovery contract

The dedicated `outbound-recovery` Compose service owns this sweep; the
scheduler does not launch it with `runInBackground`. It starts one child every
60 seconds with `--chunk=25 --queue=notifications --max-items=25
--max-seconds=40`. `max-items` bounds each recovery facet, and each facet uses
a rotating keyset cursor for fairness; the cursor is only a cache hint, so a
Redis restart cannot lose work. PostgreSQL statements have a 10-second session
timeout. Each stage is isolated: a failed stage is logged and the remaining
stages still run. At 50 seconds the supervisor sends SIGTERM and escalates to
SIGKILL after at most 2 seconds, keeping the child lifetime below the 60-second
cadence. Telegram API requests retain their explicit 10-second request and
3-second connect timeouts.

For an isolated one-shot run, use the bounded command:

```sh
php artisan telegram:recover-outbound --chunk=25 --queue=notifications --max-items=25 --max-seconds=40
```

The command requires `pcntl` for its hard 40-second alarm. Prefer the
supervised container for production operation so process-age/RSS observation,
the external deadline, heartbeat, and runtime alerts remain active.

The worker writes `storage/framework/telegram-outbound-recovery-heartbeat`
only after a successful sweep. The `ops:health --outbound-recovery-heartbeat=180`
check fails when this heartbeat is missing or stale. Each supervised child logs
its PID, age, and sampled/peak RSS; the supervisor sends SIGTERM at 50 seconds
and escalates to SIGKILL within 2 seconds. Do not run a second worker against
the same storage concurrently.

The command first asks `TelegramOutboundEffectRecovery` to fence expired
`sending` leases as `unknown`. It then asks `ReceiptDeliveryPipeline` to
reconcile deterministic receipt groups whose children are already `sent` but
whose `orders.telegram_receipt_sent_at` marker is still null. That repair uses
the same completion rule as normal delivery and conditionally writes the
marker. The command's existing cleanup scan then applies normal eligibility
and queues the unique cleanup job; it never dispatches the receipt again.

It also asks `CheckoutInstructionDeliveryPipeline` to reconcile sent
checkout-instruction effects whose Order notification marker is still null.
The conditional repair copies the ledger chat, message identifier, and sent
timestamp before the cleanup scan; it never claims or redispatches the sent
effect. `unknown` and `manual_review` effects remain review-only.

It also asks `AdminOrderNotificationDeliveryPipeline` to reconcile sent
pending, paid, and expired Order-notification effects whose Order projection is
stale. The conditional repair restores the ledger timestamp and, for pending
notifications, the safe chat/message metadata without clearing an existing
cleanup marker. If explicit versions coexist, only the latest sent version
repairs the projection. It never claims or redispatches the already-sent effect.

It also asks `ManualPaymentProofDeliveryPipeline` to reconcile sent
manual payment-proof effects whose `payments.payment_proof_forwarded_at`
marker is still null. This projection repair is conditional and does not
claim or dispatch the sent proof effect.

It also asks `SalesProofDeliveryPipeline` to reconcile sent Sales Proof effects
whose matching `sales_proof_posts` projection is stale. The conditional repair
copies the ledger's message identifier, attempts, and sent timestamp without
claiming or dispatching the already-sent effect. `unknown` and `manual_review`
effects are not selected for this repair.

It also asks `AdminBroadcastDeliveryPipeline` to reconcile sent Admin
Broadcast recipient effects whose recipient snapshot or broadcast aggregate is
stale. The conditional repair restores `sent_at` and recalculates counts and
status without claiming or redispatching the sent effect; `unknown` and other
recipient outcomes remain independent.

It also asks `StockNotificationDeliveryPipeline` to synchronize stock
recipient projections for lease-recovered effects. The repair copies the
ledger attempt count and safe error text, refreshes the Inventory Movement
aggregate in one transaction, keeps `stock_notification_sent_at` null while
an unknown recipient remains unresolved, and never redispatches that effect.

After projection repair, recovery selects only due `pending` and
policy-retryable `failed` ledger rows and dispatches them through
`TelegramOutboundEffectDispatcher`. It also discovers committed source rows
whose deterministic effect is missing and redispatches recoverable Admin
Broadcast handoffs. A successful run reports both the dispatch count and any
leases moved to `unknown`.

An `unknown` row is an Ambiguous Telegram Delivery. Recovery must never reset
it to `pending` or silently send it again. `manual_review` is likewise not
dispatchable. A definite `failed` row is retried only when the family policy
allows it and its next-attempt time is due.

### Runtime supervision and alerts

The scheduler wrapper updates `storage/framework/scheduler-heartbeat` only
after a successful `schedule:run`. A non-zero exit opens the durable
`scheduler_runtime_failure` episode; recovery resolves it through the same
Telegram Outbound Effect path. The wrapper also compares cgroup v2
`memory.events` OOM-kill counts and reports new kills through that episode.
The recovery container records every interrupted, failed, or timed-out child
run, but opens `telegram_recovery_runtime_failure` only after two consecutive
failures; repeated failures dedupe within the open episode and a successful
sweep resolves it. A single transient database statement timeout therefore
does not page the operator. Use
`storage/framework/telegram-outbound-recovery-failures` to inspect the current
consecutive-failure count.

The production release gate verifies the recovery container's image and waits
for its successful-sweep heartbeat before accepting a candidate. Rollback to a
release without this service retains the older health contract. A Redis-to-
database rollback stops the recovery producer alongside app and scheduler
before checking that the Redis queue is empty.

Local Docker validation for issue #570 used an isolated PostgreSQL/Redis stack
with 2,000 pending effects and the actual scheduler/recovery entrypoints at
384 MiB and 0.35 CPU each. Four recovery sweeps finished successfully in
3.646–4.924 seconds and enqueued 100 notifications (25 per sweep). Over a
185-second observation window, sampled memory peaked at 55.87 MiB for recovery
and 307.5 MiB for scheduler; both containers reported `OOMKilled=false` and
`RestartCount=0`. The scheduler fixture lacked Pakasir credentials and logged
Pakasir reconciliation failures, so this is not evidence of a fully configured
production scheduler. A separate runtime smoke verified one durable alert
effect across repeated OOM/non-zero signals, SIGKILL escalation for a child
ignoring SIGTERM, and failed health checks for both stale heartbeats.

Both liveness checks can be exercised together:

```sh
php artisan ops:health --scheduler-heartbeat=180 --outbound-recovery-heartbeat=180
```

After a production rollout, preserve before/after evidence from the actual
containers. Check that scheduler and recovery logs contain no recurring
`Killed`, non-zero scheduler exits, or over-deadline child terminations, and
that inspect reports no scheduler OOM kill:

```sh
docker compose logs --since=1h scheduler outbound-recovery
docker inspect $(docker compose ps -q scheduler outbound-recovery) \
  --format '{{.Name}} OOMKilled={{.State.OOMKilled}} Memory={{.HostConfig.Memory}} RestartCount={{.RestartCount}}'
```

### Admin Broadcast handoff

Admin Broadcast uses the documented deferred-materialization exception in
[ADR-0007](adr/0007-telegram-outbound-effects.md). A committed broadcast with
`recipients_materialized_at` still null is owed audience and recipient-effect
materialization, even if `queue_dispatched_at` is already set. Recovery
redispatches it through the same locked `telegram-broadcast` queue boundary as
the original request. The worker materializes recipient and locale snapshots
in idempotent chunks and sets the materialization marker only after the pass
completes.

### Manual Delivery Review

Use the admin Telegram Outbound Effect review screen for `unknown` and
`manual_review` rows. The operator may:

1. mark the existing effect `sent` after verifying it in Telegram and entering
   a positive integer message identifier; this sends no Telegram request and
   records audit evidence; or
2. acknowledge duplicate risk and create an explicit resend version; the
   original ambiguous row remains unchanged and the new effect is dispatched
   through the normal family adapter.

Do not copy claim tokens, credentials, QR media, raw Telegram payloads, or
customer delivery content into an incident note or command output.

## Release evidence

The race, deletion-boundary, preflight, and suite results for this contract are
recorded in
[`docs/telegram-outbound-effect-issue-299-evidence.md`](telegram-outbound-effect-issue-299-evidence.md).
The architecture ownership and scope boundary are recorded in
[`docs/telegram-outbound-effect-architecture.md`](telegram-outbound-effect-architecture.md).
