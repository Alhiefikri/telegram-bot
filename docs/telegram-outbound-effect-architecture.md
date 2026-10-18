# Telegram Outbound Effect architecture and recovery contract

Status: current implementation contract after issues #297–#300 and the
accepted design in [ADR-0007](adr/0007-telegram-outbound-effects.md).

This document records the final boundary for the durable Telegram delivery
refactor in #286. It is an architecture and operations contract, not a second
business specification. The domain vocabulary is defined in
[CONTEXT.md](../CONTEXT.md).

## Scope boundary

The #286 change set covers the durable Telegram Outbound Effect ledger,
effect-family adapters, recovery, operator review, Admin Broadcast handoff,
and their verification gates. The following child issues are part of that
scope:

- #297 — validate successful Telegram message identifiers;
- #298 — make the ledger protocol the only delivery-state seam;
- #299 — add PostgreSQL race and deletion-test evidence;
- #300 — close the deferred Admin Broadcast materialization window.

Commit 81e1fa9 (fix: normalize existing USDT product prices) is a
standalone product-admin change. It changes USDT form normalization and
product-dashboard formatting, and is explicitly excluded from the #286
architecture and verification scope. It must be reviewed, reverted, or
cherry-picked as a product-admin change independently; no Telegram Outbound
Effect behavior depends on it.

## Boundary at a glance

    source business transaction
      ├─ writes source state and a pending Telegram Outbound Effect
      └─ commits
           │
           └─ afterCommit queue dispatch (useful, not the correctness guarantee)
                 │
                 └─ effect-family adapter
                       ├─ loads source snapshot and renders content
                       ├─ asks TelegramOutboundEffectLedgerProtocol to claim
                       ├─ performs the Telegram request
                       └─ asks the protocol to finalize sent, failed, or unknown
                             │
                             ├─ family adapter updates its read projection
                             └─ unknown/manual_review → Manual Delivery Review

Laravel's afterCommit behavior prevents a queued worker from observing a
rolled-back transaction, while lockForUpdate inside a transaction protects
competing claims. Those framework mechanisms support the design; the ledger
row, claim token, lease, and recovery scan provide the delivery guarantee.
See the [Laravel 13 queue transaction guidance](https://laravel.com/docs/13.x/queues#jobs-and-database-transactions)
and [pessimistic locking guidance](https://laravel.com/docs/13.x/queries#pessimistic-locking).

## Ownership and locality

### The deep ledger seam

[TelegramOutboundEffectLedgerProtocol](../src/app/Services/Telegram/TelegramOutboundEffectLedgerProtocol.php)
is the only owner of durable delivery mechanics. It owns:

- row-locked claim ownership and lease creation;
- attempt accounting and token-checked finalization;
- the pending, sending, sent, failed, unknown, and manual_review state
  transitions;
- shared transport-ambiguity classification and retry-after extraction;
- retry scheduling, family terminal-failure limits, and receipt ordering;
- stale-lease recovery to unknown; and
- operator-confirmed finalization after a delivery is verified outside the
  Telegram API.

Receipt successor selection uses the protocol's `nextReceiptEffect()` seam.
Passing sequence `0` selects the first dispatchable unit for materialization;
passing a completed unit's sequence selects its next dispatchable successor.
`isReceiptGroupComplete()` is the matching completion query; it distinguishes a
completed group from a blocked group when no successor is dispatchable. These
seams apply the legacy invoice-photo exception and strict
`receipt-photo-caption-v1`/text-fallback predecessor rules, so materialization,
worker delivery, recovery, and Manual Delivery Review do not query receipt
sequence or state locally.

[TelegramOutboundEffectPolicy](../src/app/Services/Telegram/TelegramOutboundEffectPolicy.php)
supplies family-specific attempt limits, terminal error codes, retry timing,
and receipt ordering constraints. It does not become a second state machine.

[TelegramOutboundEffectDispatcher](../src/app/Services/Telegram/TelegramOutboundEffectDispatcher.php)
maps an effect type to its queue job. It does not claim, finalize, or mutate
ledger state.

[TelegramOutboundEffectService](../src/app/Services/Telegram/TelegramOutboundEffectService.php)
materializes deterministic effect rows, locates source records, builds safe
review summaries, and creates explicit resend versions. It is not a delivery
state façade. New callers must not add claim or finalization pass-throughs to
it, to a job, or to a family adapter.

`StoreBalanceTopUpOutbound` is the family boundary for Store Balance top-up
instructions and receipts. It owns source eligibility, deterministic effect
identity, materialization and dispatch handoff, plus safe navigation matches
for sent instruction and receipt messages. `StoreBalanceTopUpInstructionCleaner`
owns the separate post-settlement deletion claim for a sent instruction
message. Interactive callbacks consume these safe outcomes; they do not
inspect `TelegramOutboundEffectState` or query Store Balance effects
themselves. Delivery-state transitions remain owned by the shared ledger
protocol.

`UsdtTopUpOutbound` is the additive Customer USDT top-up receipt family
(`usdt.top_up_receipt`, issue #552), not an extension of the IDR top-up model.
The settlement transaction materializes one localized exact-amount snapshot
through `TelegramOutboundEffectService`; `SendUsdtTopUpReceipt` uses the
existing dispatcher and ledger protocol. Both `usdt-funding:recover` and the
generic due-effect sweep can rediscover the committed effect after Redis loss.
The family has three definite-failure attempts. Ambiguous send/ACK and expired
delivery leases retain the existing `unknown`/Manual Delivery Review rules.
Explicit operator resend copies the original receipt snapshot into a new
effect version with duplicate-risk acknowledgement; it never reruns settlement
or changes the original ambiguous effect. Review summaries redact the target
and exclude the stored message. Funding source, receipt, ledger and notification
identities remain distinct; receipt delivery failure never changes money.


The review service and review projection are a narrow adapter for operator
actions across families. They reuse the ledger protocol and do not introduce a
second claim or ambiguity protocol.

### Effect-family adapters

Every durable family owns eligibility, source loading, rendering, Telegram
transport details, and synchronization of its own read projection:

| Family | Adapter responsibility | Queue boundary |
| --- | --- | --- |
| Checkout instructions | Provider-specific instruction rendering and customer Order notification projection | telegram-outbound |
| Store Balance top-up | Instruction and receipt eligibility, deterministic handoff, callback navigation matching, and post-settlement instruction cleanup | telegram-outbound / notifications |
| Store Balance top-up payment proof | Manual-rail customer payment proof forwarded to the admin chat with operator approve/reject actions and admin projection | notifications |
| Store Balance owner alert | Admin alert for top-ups that may carry money but could not be settled automatically, deduplicated per top-up with sanitized diagnostics | notifications |
| Store Balance top-up channel proof | Public Store Channel photo proof for a settled top-up, reusing the paid-invoice image and masked-buyer caption | notifications |
| Receipt credentials | Ordered chunk rendering, credential delivery projection, and completion cleanup | telegram-outbound / notifications |
| Admin Order notifications | Pending, paid, and expired admin projections | telegram-outbound / notifications |
| Manual payment proof | Proof attachment forwarding and Payment projection | notifications |
| Sales Proof Post | Store Channel announcement rendering and SalesProofPost projection | notifications |
| Admin Broadcast | Recipient snapshot, locale/content wrappers, batching, rate limiting, and aggregate projection | telegram-broadcast |
| Stock notification | Inventory-recipient batching and aggregate projection | telegram-stock |
| Price notification | Localized customer and Store Channel rendering, batching, and price-event projection | telegram-broadcast |

The principal adapters are
CheckoutInstructionDeliveryPipeline, ReceiptDeliveryPipeline,
AdminOrderNotificationDeliveryPipeline, ManualPaymentProofDeliveryPipeline,
SalesProofDeliveryPipeline, AdminBroadcastDeliveryPipeline,
StockNotificationDeliveryPipeline, ProductPriceChangeNotificationService,
BalanceTopUpPaymentProofDeliveryPipeline, BalanceTopUpOwnerAlertDeliveryPipeline,
and BalanceTopUpChannelProofDeliveryPipeline. They all use the same ledger seam.

The Order-lifecycle and manual payment-proof families share one retry policy.
Pending, paid, and expired admin Order notifications and the forwarded manual
payment proof each stop after three definite-failure attempts: the ledger
records `admin_order_pending_notification_attempts_exhausted`,
`admin_order_paid_notification_attempts_exhausted`,
`admin_order_expired_notification_attempts_exhausted`, or
`manual_payment_proof_attempts_exhausted`, makes the effect terminal, and
removes it from `telegram:recover-outbound` discovery. Ambiguous send/ACK and
expired leases keep their `unknown` Manual Delivery Review contract.

The Store Balance top-up admin review family is delivered by
`BalanceTopUpPaymentProofDeliveryPipeline` under the
`admin.balance_top_up_payment_proof` effect type on the `notifications` queue.
The effect is admin-targeted, renders approve/reject action buttons
(`admin:topup-approve:{id}` / `admin:topup-reject:{id}`) for the operator, and is
never automatically resent; a manual top-up settles only through the confirmed
operator decision, which is audited and does not create a second review effect.

The Store Balance owner alert family is delivered by
`BalanceTopUpOwnerAlertDeliveryPipeline` under the
`admin.balance_top_up_owner_alert` effect type on the `notifications` queue.
`StoreBalanceTopUpOutbound::recoverMissingOwnerAlerts` materializes at most one
effect per top-up (`recovery_pending`, `invalid_provider_result`, or `expired`
with a customer payment proof), and delivery re-checks that the top-up still
qualifies so a resolved or compensated top-up is finalized without sending. The
message reports only the top-up identity, customer, requested amount, state, and
a sanitized diagnostic; the Admin Dashboard remains the decision surface, so the
alert never carries release actions.

The Store Balance top-up channel proof family is delivered by
`BalanceTopUpChannelProofDeliveryPipeline` under the
`balance.top_up_channel_proof` effect type on the `notifications` queue. A
settled top-up materializes at most one effect
(`balance-top-up:<id>:channel-proof:v1`) through the
`MaterializeStoreBalanceTopUpChannelProof` listener; `telegram:recover-outbound`
rediscovers settled top-ups that lost their handoff, bounded to the last seven
days so enabling the feature never floods the channel with old receipts. The
public post reuses the
same `PaidInvoiceRenderer` and masked-buyer caption seam as an order sales
proof, so it publishes an invoice-style photo with no raw identifiers, and it is
gated by the existing Store Channel `sales_proof_enabled` setting. It never
touches the order proof counter, because a top-up is not a successful order.

## Durable handoff and dispatch

For transactional materialization families (checkout instructions, payment
proof, Sales Proof Post, and price notifications), the source mutation and
pending effect are created in the same database transaction. Queue dispatch
happens after commit, but losing that dispatch does not remove the committed
effect; `telegram:recover-outbound` can find it.

Paid Order receipts use a deferred-materialization exception. The Order
lifecycle transaction atomically commits paid Order/Payment and inventory
state, then queues `SendTelegramReceipt` after the outermost commit with only
the Order ID. The worker builds the transient receipt plan and invoice image
before acquiring the short Order lock used to revalidate eligibility and write
the deterministic effect group. Store Balance checkout follows the same path:
the queued job waits for the outer checkout transaction, not merely the nested
Order lifecycle transaction. Explicit/admin resend paths likewise prepare
outside Order/effect locks and revalidate under lock. No credential, caption,
receipt plan, or image bytes are persisted in job/cache payloads.

The committed paid Order itself is the durable handoff marker. The existing
source-discovery pass in `telegram:recover-outbound` materializes an eligible
paid Order with no receipt effect group; the normal effect sweep recovers a
pending ledger group if the process stops after group commit but before queue
dispatch. Replaying materialization uses the canonical group key. The plan
and invoice preflight keep existing render-failure and text-fallback behavior.

The source-recovery pass isolates each Order: a materialization or queue-dispatch exception is logged with the Order ID, stage, status, allowlisted error code, and exception class, then the scan continues. It never logs the exception message, credentials, or queued payload. If an effect committed before dispatch failed, the next pass sees the pending ledger row; row locking and the ledger claim still prevent duplicate delivery. `telegram_receipt_sent_at` remains a projection of a fully completed receipt group, not proof inferred from Supplier Fulfillment.

Store Balance settlement is a deferred-materialization exception. The
`StoreBalanceTopUpService` transaction commits the authoritative top-up and
ledger state, then emits `StoreBalanceTopUpSettled`. The after-commit
`MaterializeStoreBalanceTopUpReceipt` listener reloads the committed source,
invokes `StoreBalanceTopUpOutbound::handoffReceipt`, and queues
`DeleteStoreBalanceTopUpInstruction` on the `notifications` queue. That cleanup
job deletes a previously sent QR instruction through
`StoreBalanceTopUpInstructionCleaner`; a delivery race also queues the same
cleanup after an instruction send observes that settlement already committed.
The listener does not own effect identity or delivery policy. Interactive
confirmation and top-up action callbacks invoke `handoffInstructions` only
after the KlikQRIS lifecycle returns both `isPayable()` and a non-null
`payable()` value. If the listener handoff is missed, the same family discovers
and repairs the missing receipt (and instruction) effect during recovery without
running Telegram work under financial locks.

The dispatch path is deliberately split into three concerns:

1. the source transaction commits its durable effect or recoverable handoff;
2. synchronous families write effects before commit, while deferred families
   materialize them after commit and use source discovery to close a missed
   dispatch interval; and
3. the adapter claims and finalizes each materialized effect through the ledger
   protocol.

Queue uniqueness and afterCommit reduce duplicate work and ordering hazards.
They are not remote Telegram idempotency and do not replace the ledger's claim
token or recovery rules.

### Deferred Stock Notification materialization

Canonical positive stock intake, supplier availability, stock deletion and
normal/late sold-out sales record the `StockNotificationEvent` handoff inside
the inventory transaction, but defer recipient rendering and Telegram effect
materialization until after commit. A null
`recipients_snapshotted_at` is the durable marker that the immutable audience
snapshot is still owed; `materialization_cursor` bounds each worker pass.

The `telegram-stock` job snapshots at most 100 customers per pass, creates
missing ledger effects idempotently, then claims at most 25 due recipient
effects for delivery per job execution. It self-dispatches while the event or its effects still have
work. `telegram:reconcile-stock-notifications` and the stock branch of
`telegram:recover-outbound` discover null snapshot markers and lost dispatches.
The source event therefore closes the interval between the inventory commit and
queue pickup without holding the inventory transaction during customer fan-out.

S11 (#628) moved sold-out sales from eager compatibility materialization to
this existing handoff after a 10,000-Customer checkout exceeded its HTTP
deadline while expanding recipients under financial locks. Renderer failure
now leaves the paid Order/debit/allocation committed; recovery remains owned
by this pipeline. See [integrated acceptance evidence](customer-api-s11-acceptance.md).

## Ledger state contract

| State | Meaning | Recovery behavior |
| --- | --- | --- |
| pending | A durable effect is owed and may be dispatched when its retry window is due. | Dispatch through the shared dispatcher. |
| sending | A worker owns the effect under a lease and claim token. | A live lease remains with that worker; an expired lease becomes unknown. |
| sent | Telegram returned a successful response with a valid positive integer message identifier, or an operator verified the delivery. | Never dispatch again. |
| failed | Delivery failed definitely before completion. | Retry only when the family policy marks the failure retryable, the family attempt limit is not exhausted, and the next-attempt time is due; an exhausted family becomes terminal. |
| unknown | The remote outcome cannot be proven because of transport, worker, or unproven-response ambiguity. | Never automatically redispatch; expose it for Manual Delivery Review. |
| manual_review | An operator-controlled review state for an Ambiguous Telegram Delivery. | Never automatically redispatch. Mark sent after evidence or create an explicit resend. |

The protocol rejects a sent transition unless the Telegram response is
successful and its message identifier is a positive integer. A successful
Telegram response that omits that identifier or carries an invalid one proves
neither delivery nor rejection, so `TelegramOutboundResponseValidator` raises
the shared ambiguous outcome and every family — `sendMessage`, `sendPhoto`,
and `sendDocument` alike — finalizes the effect as `unknown` instead of a
retryable definite failure. A timeout,
connection failure, worker interruption, HTTP 408, or uncertain 5xx response
is an Ambiguous Telegram Delivery unless a pre-send validation error proves
that no request could have been sent. HTTP 400/403 and family terminal errors
remain definite failures according to policy. Callers that need the identifier
for a later edit still fail closed on both shapes and never invent one.

Legacy receipt effects have one explicit non-credential exception. The first
`invoice` `sendPhoto` unit is a public-safe presentation unit: a definite
`failed` outcome or an ambiguous `unknown` outcome is resolved for ordering
and unlocks later Product Detail/text units. The invoice effect remains
independently reviewable, and `unknown` is never automatically redispatched.
A `manual_review` invoice remains blocked until an operator marks it sent or
creates an explicit resend.

New `receipt-photo-caption-v1` groups use an `invoice_caption` photo that can
contain credentials. The photo is therefore a strict predecessor: `failed`,
`unknown`, or `manual_review` blocks every continuation. The explicit
text-fallback template and all later chunks retain the same strict ordering.
Manual Delivery Review can mark a predecessor sent to unlock the next unit,
but it never resends the predecessor.

Buyer fulfillment projection follows the group version. Legacy groups require
all non-invoice units to be `sent`; photo-caption and text-fallback groups
require every child to be `sent`. `ReceiptDeliveryPipeline` then sets
`orders.telegram_receipt_sent_at` and dispatches `DeleteBuyerCheckoutMessages`.
That marker and cleanup never change a ledger outcome or trigger an automatic
resend.

Focused receipt evidence is kept at
`src/tests/Feature/Telegram/TelegramReceiptOutboundEffectTest.php`:
`test_invoice_failure_dispatches_product_detail_and_completion_ignores_photo_failure`
and `test_ambiguous_invoice_is_unknown_but_product_detail_still_delivers`
cover the invoice exception, while
`test_ordered_receipt_stops_after_an_ambiguous_credential_unit_without_resending_previous_units`
proves that a later unit remains pending after credential ambiguity.
`test_accepted_receipt_chunk_without_a_proven_message_id_is_ambiguous_and_never_resent`
proves that an accepted-but-unproven chunk response becomes `unknown` and is
never resent by `telegram:recover-outbound`.

`test_recovery_repairs_a_complete_sent_receipt_without_resending_it` proves
that recovery repairs a lost completion projection without redispatching the
receipt.

`SupplierFulfillmentTest::test_supplier_completion_without_materialized_receipt_is_recovered_without_reallocating`
proves that a Paid/Completed supplier Order with its original allocation but
no receipt effect is rediscovered without another supplier purchase or stock
allocation. `TelegramReceiptOutboundEffectTest::test_receipt_recovery_continues_after_one_orders_materialization_fails_and_logs_safely`
proves one Order's preparation error does not block later Orders and that the
diagnostic excludes exception text.

Manual payment-proof recovery evidence is kept at
`src/tests/Feature/Telegram/TelegramLifecycleOutboundEffectTest.php`:
`test_recovery_repairs_a_sent_manual_payment_proof_projection_without_resending_it`
proves the sent effect is repaired without queue redispatch, while
`test_recovery_repairs_a_late_manual_payment_proof_without_changing_payment_evidence`
proves late-payment state and effect evidence remain unchanged.
`test_issue_270_notification_families_become_terminal_after_three_definite_failures`
proves the pending, paid, and expired admin Order notifications and the manual
payment proof each stop after three definite attempts, record their exhausted
terminal code, and are never redispatched by `telegram:recover-outbound`.

Sales Proof recovery evidence is covered by
`test_recovery_repairs_a_sent_sales_proof_projection_without_resending_it`,
which proves the ledger's sent message identifier and timestamp repair the
SalesProofPost projection without queue redispatch. The companion
`test_recovery_leaves_unknown_and_manual_review_sales_proof_effects_untouched`
keeps review outcomes out of the automatic repair path.

Admin Broadcast recovery evidence is covered by
`src/tests/Feature/AdminTelegramBroadcastOutboundEffectTest.php`:
`test_recovery_repairs_sent_recipient_and_broadcast_projection_without_redispatching`
proves that a sent recipient repairs both its recipient snapshot and the
aggregate projection without sending again, while an independent ambiguous
recipient remains unresolved.

`AdminTelegramBroadcastQueueTest::test_recovery_redispatches_unmaterialized_handoff_after_sent_projection_repair`
proves that an unmaterialized handoff remains recoverable even when the
aggregate projection is temporarily terminal.

## Operational recovery

Admin Order-notification recovery evidence is covered by
`TelegramLifecycleOutboundEffectTest::test_recovery_repairs_sent_admin_order_projections_without_redispatching_them`.
It repairs pending, paid, and expired Order notification projections from
sent ledger effects without queue redispatch; the companion cleanup test keeps
an already-recorded pending deletion marker intact, and the version test keeps
older sent resends from overwriting the latest projection.

Paid and missing-credential review notices share the
`SendAdminOrderPaidNotification` job class but are distinct durable effects:
the paid notice is keyed to the Order
(`order:<order id>:admin.order_paid_notification:v1`) while the review notice
is keyed to the `ManualFulfillmentReview`
(`order-review:<review id>:owner:v1`, `admin.order_review_notification`).
Queue assertions therefore identify jobs by `effectId`, never by job class
alone. Managed-stock lifecycle fixtures allocate a real Product Stock Item
before checkout; numeric-stock fixtures opt out explicitly so the paid
transition exercises `local_credentials_missing`.
`TelegramLifecycleOutboundEffectTest::test_numeric_only_paid_stock_records_one_review_and_distinct_paid_and_review_effects`
pins one sale and one allocation for managed stock, one durable review and
zero credential allocation for numeric-only stock, and distinct paid/review
effect identities. The companion
`test_recovery_keeps_a_sent_paid_notice_while_recovering_the_independent_review_effect`
proves recovery repairs the sent paid notice without resending it while the
independent pending review effect is still redispatched and delivered, then
leaves both sent identities unchanged. `SakurupiahWebhookTest` applies the
same fixture distinction: `test_out_of_order_callbacks_cause_one_paid_sale_and_stale_callbacks_are_acknowledged`
uses an allocated credential and asserts one sale/allocation with no review,
and `test_paid_callback_with_numeric_only_stock_records_one_review_without_releasing_credentials`
asserts the review path with no credential release.

The scheduler runs `telegram:recover-outbound --queue=notifications` every
minute with a bounded 10-minute `withoutOverlapping` mutex. Production cutover
also clears stale scheduler mutexes. The command performs source discovery and
delivery recovery in this order:

1. TelegramOutboundEffectRecovery asks the ledger protocol to turn expired
   sending leases into unknown, fencing the old claim token.
2. ReceiptDeliveryPipeline scans sent receipt rows whose Order marker is still
   null and applies the same group-completion rule as normal delivery. It
   conditionally writes the marker; the command's existing cleanup
   reconciliation scan then queues the idempotent cleanup handoff. It never
   dispatches a receipt effect.

Before the cleanup reconciliation scan, `CheckoutInstructionDeliveryPipeline`
scans sent checkout-instruction effects whose Order notification marker is
still null. It conditionally restores the ledger message identifier, chat, and
timestamp without claiming or redispatching the sent effect, so Binance/BSC
cleanup can use the recovered message metadata.

Before the cleanup reconciliation scan, `AdminOrderNotificationDeliveryPipeline`
scans sent pending, paid, and expired Order-notification effects and
conditionally restores their Order projection. Pending effects also restore
the ledger chat and message metadata without clearing a recorded cleanup
marker. It never claims or redispatches the already-sent effect.

3. ManualPaymentProofDeliveryPipeline scans sent manual payment-proof effects
   whose Payment forwarding marker is still null and conditionally restores
   the projection. It never claims or dispatches the already-sent effect.
4. SalesProofDeliveryPipeline scans sent Sales Proof effects and conditionally
   restores the matching SalesProofPost status, message identifier, attempts,
   and timestamp. It never claims or dispatches the already-sent effect;
   unknown and manual-review effects remain outside this scan.
5. AdminBroadcastDeliveryPipeline scans sent Admin Broadcast recipient
   effects and conditionally restores stale recipient projections, then
   recomputes each affected broadcast's aggregate counts and status. It never
   claims or dispatches an already-sent effect.
6. `StockNotificationDeliveryPipeline` synchronizes each stock recipient
   projection whose lease was fenced to `unknown`, copying the ledger attempt
   count and safe error text, then refreshes the Inventory Movement aggregate
   in the same transaction. An unresolved unknown recipient never completes
   the movement, and recovery never redispatches it.
7. `ProductPriceChangeNotificationService` synchronizes each customer
   recipient and Store Channel projection whose price-notification lease was
   fenced to `unknown`, copying the ledger attempt count and safe error text,
   clearing stale failure fields, and never redispatching the effect.
8. The command selects due pending and retryable failed ledger rows through
   readyToDispatchQuery() and dispatches them through
   TelegramOutboundEffectDispatcher.
9. It materializes missing checkout, receipt, Store Balance top-up, admin
   notification, Sales Proof Post, stock, and price effects from committed
   source state when the deterministic effect key proves that the effect is
   absent.
10. It redispatches recoverable Admin Broadcast handoffs through the same locked
   broadcast queue boundary.
11. Non-ledger cleanup work, such as deleting old customer-facing checkout
   messages or settled Store Balance QR instructions, remains a separate
   cleanup job and is not treated as delivery claim authority.

The command reports the number of dispatches and the number of leases moved to
unknown. It must not turn unknown into pending or silently retry a delivery
whose remote outcome is ambiguous.

### Deferred Admin Broadcast materialization

Admin Broadcast is the approved exception to the source/effect same-transaction
rule. The confirmation transaction commits the telegram_broadcasts source
row first. A null recipients_materialized_at is the durable handoff marker:
the audience snapshot and recipient-level effects are still owed.

The initial request dispatches SendTelegramBroadcastBatch after commit through
a locked broadcast-specific boundary. queue_dispatched_at records a dispatch
attempt only; it never suppresses recovery. The worker snapshots eligible
recipients and locales in idempotent chunks, materializes recipient effects,
and sets recipients_materialized_at only after the materialization pass has
completed.

recoverableBroadcastsQuery() selects every broadcast whose handoff is still
unmaterialized, regardless of its aggregate status, or selects due work for a
non-terminal broadcast whose recipients are already materialized. Recovery
force-dispatches the same queue boundary, so process death after
commit and before worker pickup cannot lose the Admin Broadcast delivery
intent. Recipient independence, batching, locale snapshots, rate limiting,
and ambiguous recipient outcomes remain family-local behavior.

## Manual Delivery Review

The admin review list exposes only safe effect metadata and redacted target
information. It does not expose Telegram credentials, raw payloads, QR media,
claim tokens, or rendered credential content.

An authorized operator has two distinct actions:

1. **Mark sent after verification.** The operator supplies a positive integer
   Telegram message identifier. The review service finalizes the existing
   unknown or manual_review row through the ledger, synchronizes the family
   projection, and writes an audit record. It sends no Telegram request.
2. **Explicit resend.** The operator must acknowledge duplicate risk. The
   service creates a new pending effect version with a supersedes reference,
   leaves the original ambiguous row unchanged, writes an audit record, and
   dispatches the new version through the normal adapter.

An explicit resend is not an automatic retry and must remain distinguishable in
the effect history. A failed, definitely non-delivered effect may use its
family retry path; an Ambiguous Telegram Delivery must use review or explicit
resend.

## Verification contract

The committed evidence for the release gate is in
[docs/telegram-outbound-effect-issue-299-evidence.md](telegram-outbound-effect-issue-299-evidence.md).
The required checks are:

- focused Telegram Outbound Effect and family-adapter tests;
- protocol, deletion-boundary, and contract tests;
- PostgreSQL races for competing claims, expired-lease fencing, and stale
  claim-token finalization;
- full SQLite verification, with unrelated dirty-worktree failures called
  out separately; and
- the PostgreSQL concurrency gate with artifact and extension preflight. A
  permission or missing-extension failure must stop before database setup or
  PHPUnit execution.

No verification artifact or documentation may contain credentials, raw Telegram
payloads, QR media, claim tokens, or customer delivery content.

## Non-goals

This boundary does not change provider selection, KlikQRIS recovery, Pakasir
legacy behavior, Binance Payment Verification, checkout idempotency, inventory
invariants, queue names, or customer-facing message content. It does not claim
exactly-once remote Telegram delivery; it makes ambiguity explicit and
operator-controlled.


### Automatic TXT buyer receipts

New receipt groups snapshot `receipt_layout_version = v2`,
`credential_layout_version = copy-v1`, and the selected render mode.
Short deliveries use one native Telegram `pre` entity with `language = copy`
per account: `email | password | MFA/Auth Token` where present.
Opaque legacy payloads remain verbatim. Tutorial text, Product context and OTP
Mail links stay outside the copied block; OTP Mail remains an access button.
The renderer constructs UTF-16 entity ranges from the actual appended text,
and emoji conversion must not rewrite literal markup inside a `pre` entity.
Both initial normalization and custom-emoji fallback honor these entity ranges,
including JSON-encoded caption entities; fallback removes decoration, not
credential text.
Credential-bearing captions, continuation messages and image-fallback messages
use `protect_content = false` to allow copying. They can consequently also be
forwarded/saved; paid Customer ownership and confidentiality guidance remain
required. Non-credential receipt units retain content protection.
Their Support button, confidentiality/copy guidance, and thank-you footer live
together in the following action panel, without a separate footer message.
More than one delivered account selects TXT automatically. A single account
stays in chat unless its raw credential/access fields and delivery notes exceed
1024 Unicode characters (the existing photo-caption budget). Shared tutorials
and structured access fields are counted once; Telegram HTML, labels, line
length and newline count do not force a short credential into a document.
This policy is code-owned, with no presentation ENV settings.

Document groups contain one non-secret invoice summary (`sendPhoto`, or
`sendMessage` for the explicit image fallback), then one credential-bearing
`sendDocument`. Both units use the existing strict receipt ledger ordering.
The existing paid-order TXT exporter authorizes the Customer, includes all
credentials, delivery notes, guides, the signed invoice URL with the existing
Order-based expiry, and localized confidentiality guidance. The guidance uses
plain catalog text with Unicode emoji, never Telegram HTML; credential values
remain verbatim. Recovery Keys remain hash-only and are never reconstructed
or changed by export.

Files are generated in memory, bounded to the existing 1 MiB export limit, and
uploaded via multipart without plaintext persistence. Documents deliberately
use `protect_content = false` so the buyer can save the TXT; the caption warns
against sharing. Only successful delivery of the complete group marks the
Order receipt sent and materializes its one action panel. Failed or ambiguous
document sends block completion; ambiguous uploads are never automatically
resent. Image rejection can create the explicit text/document fallback, whose
completed group also gets an action panel and supports recovery of that
handoff. Already-materialized receipt rows without the new layout snapshot
retain their original chat layout and transport selection.

Credential layout is part of durable sequence identity. Existing text-only
and invoice/Product Detail templates retain their original flattened boundaries
and tutorial/token placement. Existing photo-caption/fallback groups without
`credential_layout_version = copy-v1` use the frozen
`TelegramCredentialDeliveryRendererV1` field layout and retain content protection.
Fallback inherits its source layout; explicit resend creates a new copy-layout
group without rewriting the original. Only closed `copy-v1`/`fields-v1` layout
identifiers are exposed in Manual Delivery Review, never credential contents.
Changing a historic renderer in place can resend an already-delivered account or
mark fulfillment complete while skipping a pending Auth Token.

Telegram API reference checked through Context7:
<https://core.telegram.org/bots/api#senddocument> (multipart uploads, caption
limit of 1024 characters, and content-protection behavior).
