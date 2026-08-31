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

## Runtime recovery contract

The scheduler runs `telegram:recover-outbound --queue=notifications` every
minute with a bounded 10-minute overlap mutex. Production cutover also runs
`schedule:clear-cache`, so a mutex left by the previous scheduler cannot
suppress recovery for Laravel's default 24-hour expiry. Run the command
manually after an outage or queue-worker interruption:

```sh
php artisan telegram:recover-outbound --chunk=100 --queue=notifications
```

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
