# Durable Telegram Outbound Effects

## Status

Accepted - 2026-08-17

## Decision

Durable business deliveries to Telegram use one transactional Outbound Effect Ledger written in the same database transaction as the Order, Payment, inventory, Sales Proof, or other source change that requires the delivery, except for the deferred-materialization handoffs documented below. The ledger tracks ordered delivery units with `pending`, `sending`, `sent`, `failed`, `unknown`, and `manual_review` outcomes, using a database lease and claim token for ownership. It does not include ephemeral callback acknowledgements or cleanup calls.

Telegram does not document an outbound idempotency key for `sendMessage` or `sendPhoto`, so a timeout, connection failure, or worker crash after the send may have an ambiguous outcome. Ambiguous effects are never blindly retried. They remain auditable for operator verification or an explicit new resend effect. Existing `orders.checkout_key` remains responsible for checkout mutation idempotency, while queue uniqueness and `afterCommit()` remain supporting optimisations rather than delivery guarantees.

### Final protocol boundary

`TelegramOutboundEffectLedgerProtocol` is the sole durable delivery-state
seam. It owns row-locked claim ownership, leases, claim-token fencing, state
transitions, ambiguity classification, retry scheduling, terminal-failure
rules, receipt ordering, and operator-confirmed finalization. The family policy
supplies attempt limits and ordering constraints without becoming a second
state machine.

Receipt adapters use the protocol's `nextReceiptEffect()` successor seam for
both initial group materialization (sequence `0`) and continuation selection;
version-specific predecessor exceptions therefore have one query/readiness
owner.
`isReceiptGroupComplete()` applies the same family policy to distinguish a completed group from a blocked group when no successor is dispatchable. Materialization, delivery, recovery, and Manual Delivery Review must consume these protocol seams rather than repeat sequence/state selection.

Effect-family adapters own only eligibility, source loading, rendering,
Telegram transport, and their read projection. The dispatcher maps effect
types to queue jobs. Materialization and safe review summaries remain in the
effect service, but no caller may reintroduce claim/finalization pass-throughs
or a family-specific delivery-state switchboard. See the companion
[architecture and recovery contract](../telegram-outbound-effect-architecture.md).

### Store Balance top-up family boundary

`StoreBalanceTopUpOutbound` is the single family seam for Store Balance
instruction and receipt effects. It owns source eligibility, deterministic
effect identity, materialization, dispatch handoff, and safe callback
navigation matching. Interactive callbacks consume its safe results and do not
inspect Telegram effect rows or delivery states directly. The family delegates
claim, lease, ambiguity, and finalization to
`TelegramOutboundEffectLedgerProtocol`.

An effect is `sent` only after a successful Telegram response with a valid
positive integer message identifier. Transport ambiguity and expired leases
become `unknown`; they are not returned to `pending` by recovery. Operator
resolution uses the same protocol boundary, while an explicit resend creates a
new auditable effect version and leaves the ambiguous record unchanged.

### Receipt ordering exception

Legacy receipt effects keep one explicit ordering exception: the first
invoice-photo unit is non-credential. Its `failed` state (including
deterministic render or definite Telegram failure) and its `unknown` state
(ambiguous transport) are resolved for ordering, so Product Detail may
continue. The legacy invoice row remains independently reviewable; `unknown`
is never converted back to `pending` or automatically resent. A legacy
invoice in `manual_review` remains blocked until an operator marks it sent or
creates an explicit resend.

New `receipt-photo-caption-v1` groups are different: the first
`invoice_caption` photo can contain credentials, so it is a strict
predecessor. Its `failed`, `unknown`, or `manual_review` state blocks every
continuation. The same strict rule applies to the explicit text-fallback
template and every later chunk. This prevents a timeout after a credential
photo request from being followed by an uncoordinated continuation.

Buyer fulfillment completion is projected from successful delivery of all
credential-bearing units. For legacy groups, the non-invoice Product
Detail/text units determine completion and the invoice exception may still be
unknown. For photo-caption and text-fallback groups, every child must be
`sent`. Claim-token fencing and the duplicate-risk acknowledgement required
for explicit resend are unchanged.

The pipeline writes `orders.telegram_receipt_sent_at` only after the relevant
completion rule holds, then dispatches the best-effort
`DeleteBuyerCheckoutMessages` cleanup. Cleanup does not alter the invoice
ledger outcome, delete review history, or trigger an automatic resend.

Scheduled recovery repeats this projection step for deterministic receipt groups
whose children are already `sent` while the Order marker is still null. The
conditional marker update is idempotent; the command's existing cleanup scan
then applies normal cleanup eligibility and queues the unique handoff.
Recovery never dispatches a receipt effect for this case.

Scheduled recovery also scans sent checkout-instruction effects whose Order
notification marker is still null and conditionally restores the ledger
message identifier, chat, and sent timestamp. It never claims or dispatches
the already-sent effect; the repair runs before cleanup reconciliation so
cancelled Binance and BSC checkout instructions remain deletable after a
worker crash.

Scheduled recovery also scans sent Admin Order-notification effects whose
matching Order projection is stale after ledger finalization. It conditionally
restores the pending, paid, or expired notification timestamp; pending effects
also restore the safe admin chat/message snapshot without clearing an
already-recorded pending cleanup marker. When explicit versions coexist, only
the latest sent version for a source and effect type can repair the projection.
It never claims or redispatches the already-sent effect, and repeated recovery
is idempotent.

Scheduled recovery also scans sent manual payment-proof effects whose Payment
forwarding marker is still null and conditionally restores that projection.
This repair never claims or dispatches the already-sent effect, so a worker
crash between ledger finalization and projection update cannot cause a
duplicate proof delivery.

Scheduled recovery also scans sent Sales Proof effects whose matching
`SalesProofPost` read projection is stale and conditionally restores its sent
status, message identifier, attempts, and timestamp. It never claims or
dispatches the already-sent effect; `unknown` and `manual_review` effects
remain untouched.

Scheduled recovery also scans sent Admin Broadcast recipient effects whose
recipient projection may still be stale after a worker stops between ledger
finalization and projection synchronization. It conditionally restores the
recipient `sent_at`, clears stale failure fields, and recomputes the affected
broadcast aggregate counts and status. It never claims or redispatches the
already-sent effect; independent `unknown` or `failed` recipients retain their
own outcomes.

Scheduled recovery also asks `StockNotificationDeliveryPipeline` to repair
stock recipient projections for effects whose expired leases were fenced to
`unknown`. It copies the ledger attempt count and safe error text, then
refreshes the Inventory Movement aggregate transactionally. An unresolved
unknown recipient keeps `stock_notification_sent_at` null, and the repair
never claims or redispatches the effect. Repeated recovery is idempotent.

Scheduled recovery also asks `ProductPriceChangeNotificationService` to repair
customer recipient and Store Channel projections for price-notification effects
whose expired leases were fenced to `unknown`. It copies the ledger attempt
count and safe error text, clears stale failure fields, never claims or
redispatches the effect, and remains idempotent across repeated recovery.

The Price Notification lease-recovery contract is covered by
`ProductPriceChangeNotificationTest::test_recovery_synchronizes_expired_price_notification_leases_without_redispatching`.

The Stock Notification lease-recovery contract is covered by
`ProductInventoryRaceTest::test_lease_recovery_synchronizes_stock_recipient_and_movement_projection_without_redispatching`.

The Admin Broadcast recovery contract is covered by
`AdminTelegramBroadcastOutboundEffectTest::test_recovery_repairs_sent_recipient_and_broadcast_projection_without_redispatching`.

The Admin Order-notification recovery contract is covered by
`TelegramLifecycleOutboundEffectTest::test_recovery_repairs_sent_admin_order_projections_without_redispatching_them`
and
`TelegramLifecycleOutboundEffectTest::test_recovery_preserves_recorded_admin_pending_cleanup_projection`.

The focused contract is covered by
`TelegramReceiptOutboundEffectTest::test_invoice_failure_dispatches_product_detail_and_completion_ignores_photo_failure`,
`TelegramReceiptOutboundEffectTest::test_ambiguous_invoice_is_unknown_but_product_detail_still_delivers`,
and
`TelegramReceiptOutboundEffectTest::test_ordered_receipt_stops_after_an_ambiguous_credential_unit_without_resending_previous_units`.

`TelegramReceiptOutboundEffectTest::test_recovery_repairs_a_complete_sent_receipt_without_resending_it`
also covers recovery after the ledger projection window.

The manual payment-proof recovery contract is covered by
`TelegramLifecycleOutboundEffectTest::test_recovery_repairs_a_sent_manual_payment_proof_projection_without_resending_it`
and
`TelegramLifecycleOutboundEffectTest::test_recovery_repairs_a_late_manual_payment_proof_without_changing_payment_evidence`.

The Sales Proof recovery contract is covered by
`TelegramLifecycleOutboundEffectTest::test_recovery_repairs_a_sent_sales_proof_projection_without_resending_it`
and
`TelegramLifecycleOutboundEffectTest::test_recovery_leaves_unknown_and_manual_review_sales_proof_effects_untouched`.

### Deferred Store Balance receipt materialization

A successful Store Balance settlement changes authoritative top-up and ledger
state inside `StoreBalanceTopUpService`'s transaction. The service emits the
`StoreBalanceTopUpSettled` application event, which implements
`ShouldDispatchAfterCommit`; `MaterializeStoreBalanceTopUpReceipt` reloads the
committed top-up and delegates the receipt handoff to
`StoreBalanceTopUpOutbound::handoffReceipt`. The listener owns only the
after-commit trigger; the family owns eligibility, deterministic effect
identity, materialization, and dispatch. No Telegram service or dispatcher
runs under financial locks. If the process stops after commit before the
listener runs, `telegram:recover-outbound` asks the same family to discover
paid settled top-ups without a receipt effect and perform the handoff. Replays
reuse the effect key.

### Deferred Admin Broadcast materialization

Admin Broadcast is an explicit deferred-materialization exception to the
same-transaction effect rule. The confirmation transaction durably writes the
`telegram_broadcasts` source row first; its nullable
`recipients_materialized_at` value is the durable handoff marker. A null value
means that the audience snapshot and recipient-level Telegram Outbound Effects
are still owed, not that the delivery intent is absent.

The request path only dispatches the bounded worker after commit. The worker
materializes recipients and effects in idempotent chunks and sets
`recipients_materialized_at` only after that work is complete. A process death
before queue dispatch, before worker pickup, or during chunked materialization
therefore leaves a committed source row that recovery can find. The
`telegram:recover-outbound` selects every broadcast whose handoff is still
unmaterialized, regardless of aggregate status, and redispatches it through
the same locked queue boundary as the original request. For already
materialized broadcasts it selects only non-terminal rows with due work;
`queue_dispatched_at` records a dispatch attempt but never hides an
unmaterialized handoff from recovery.

This exception preserves asynchronous recipient fan-out, recipient
independence, locale snapshots, batching, rate limiting, and ambiguous-delivery
handling. No recipient-level Telegram Outbound Effect is considered durable
until its recipient snapshot exists; the committed broadcast handoff is the
recovery guarantee that closes that interval.

### Deferred Stock Notification materialization

Canonical positive stock intake is the second deferred-materialization
exception. The inventory transaction durably creates a
`StockNotificationEvent`; its nullable `recipients_snapshotted_at` and
`materialization_cursor` identify the audience snapshot still owed. The
transaction does not render every customer message or create every Telegram
effect while product and stock rows are locked.

The after-commit `telegram-stock` worker snapshots at most 100 customers per
pass, inserts recipient rows idempotently, materializes missing ledger effects
in bounded slices, and sends at most one recipient effect per job execution.
The job self-dispatches until the event and its effects are complete.
`telegram:reconcile-stock-notifications` and `telegram:recover-outbound`
rediscover events whose snapshot marker is null, so a process death before
queue pickup cannot lose the committed restock intent. Existing compatibility
paths that explicitly opt out of notification dispatch retain eager
materialization.

## Consequences

- A committed checkout or lifecycle transition cannot lose its durable Telegram effect merely because the process dies before queue dispatch.
- A committed Admin Broadcast cannot lose its delivery intent in the deferred-materialization interval; the source row is the durable handoff and scheduled recovery is the fallback publisher.
- The system accepts conservative non-delivery and operator work in ambiguous cases to avoid duplicate credentials, payment instructions, and public Sales Proof Posts.
- Existing provider boundaries remain intact. KlikQRIS recovery continues to use the same Payment and its existing single-flight service.
- Legacy claim columns are removed only after an expand-contract migration proves that the ledger is authoritative for every effect family.
- The scheduled `telegram:recover-outbound` command is a recovery publisher and
  source-discovery safety net. It first fences expired leases as `unknown`,
  then dispatches only due `pending` or policy-retryable `failed` effects
  through the shared dispatcher. It does not automatically resend an
  Ambiguous Telegram Delivery.
- Admin Broadcast and canonical Stock Notification remain documented deferred-materialization exceptions: their committed source row/event and null snapshot marker are the durable handoff, and recovery redispatches the handoff through its locked queue boundary until recipient effects are materialized.
