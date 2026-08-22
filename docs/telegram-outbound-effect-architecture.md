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

The review service and review projection are a narrow adapter for operator
actions across families. They reuse the ledger protocol and do not introduce a
second claim or ambiguity protocol.

### Effect-family adapters

Every durable family owns eligibility, source loading, rendering, Telegram
transport details, and synchronization of its own read projection:

| Family | Adapter responsibility | Queue boundary |
| --- | --- | --- |
| Checkout instructions | Provider-specific instruction rendering and customer Order notification projection | telegram-outbound |
| Receipt credentials | Ordered chunk rendering, credential delivery projection, and completion cleanup | telegram-outbound / notifications |
| Admin Order notifications | Pending, paid, and expired admin projections | telegram-outbound / notifications |
| Manual payment proof | Proof attachment forwarding and Payment projection | notifications |
| Sales Proof Post | Store Channel announcement rendering and SalesProofPost projection | notifications |
| Admin Broadcast | Recipient snapshot, locale/content wrappers, batching, rate limiting, and aggregate projection | telegram-broadcast |
| Stock notification | Inventory-recipient batching and aggregate projection | telegram-broadcast |
| Price notification | Localized customer and Store Channel rendering, batching, and price-event projection | telegram-broadcast |

The principal adapters are
CheckoutInstructionDeliveryPipeline, ReceiptDeliveryPipeline,
AdminOrderNotificationDeliveryPipeline, ManualPaymentProofDeliveryPipeline,
SalesProofDeliveryPipeline, AdminBroadcastDeliveryPipeline,
StockNotificationDeliveryPipeline, and
ProductPriceChangeNotificationService. They all use the same ledger seam.

## Durable handoff and dispatch

For checkout, Order lifecycle, receipts, payment proof, Sales Proof Post,
stock, and price notifications, the source mutation and the pending effect are
created in the same database transaction. A queue dispatch happens after the
transaction commits, but losing that dispatch does not remove the committed
effect; telegram:recover-outbound can find it.

The dispatch path is deliberately split into three concerns:

1. the source service atomically records the business state and effect;
2. the dispatcher maps the effect to a queue job after commit; and
3. the adapter claims and finalizes through the ledger protocol.

Queue uniqueness and afterCommit reduce duplicate work and ordering hazards.
They are not remote Telegram idempotency and do not replace the ledger's claim
token or recovery rules.

## Ledger state contract

| State | Meaning | Recovery behavior |
| --- | --- | --- |
| pending | A durable effect is owed and may be dispatched when its retry window is due. | Dispatch through the shared dispatcher. |
| sending | A worker owns the effect under a lease and claim token. | A live lease remains with that worker; an expired lease becomes unknown. |
| sent | Telegram returned a successful response with a valid positive integer message identifier, or an operator verified the delivery. | Never dispatch again. |
| failed | Delivery failed definitely before completion. | Retry only when the family policy marks the failure retryable and the next-attempt time is due. |
| unknown | The remote outcome cannot be proven because of transport or worker ambiguity. | Never automatically redispatch; expose it for Manual Delivery Review. |
| manual_review | An operator-controlled review state for an Ambiguous Telegram Delivery. | Never automatically redispatch. Mark sent after evidence or create an explicit resend. |

The protocol rejects a sent transition unless the Telegram response is
successful and its message identifier is a positive integer. A timeout,
connection failure, worker interruption, HTTP 408, or uncertain 5xx response
is an Ambiguous Telegram Delivery unless a pre-send validation error proves
that no request could have been sent. HTTP 400/403 and family terminal errors
remain definite failures according to policy.

Receipt effects are grouped and sequenced, with one explicit non-credential
exception. The first `invoice` `sendPhoto` unit is a public-safe presentation
unit: a definite `failed` outcome or an ambiguous `unknown` outcome is
resolved for ordering and unlocks later Product Detail/text units. The invoice
effect remains independently reviewable, and `unknown` is never automatically
redispatched. A `manual_review` invoice remains blocked until an operator
marks it sent or creates an explicit resend.

Product Detail and credential/text units retain strict predecessor ordering:
any earlier unit that is not `sent`, including `failed`, `unknown`, or
`manual_review`, blocks later units. Marking the predecessor sent through
Manual Delivery Review unlocks the next unit; it does not resend the
predecessor.

Buyer fulfillment projection is based on the text/credential units. When all
non-invoice units are `sent`, `ReceiptDeliveryPipeline` sets
`orders.telegram_receipt_sent_at` and dispatches `DeleteBuyerCheckoutMessages`.
That marker and best-effort cleanup do not require the invoice photo to be
`sent`, so cleanup may run while the photo remains `unknown`; cleanup never
changes that ledger outcome or triggers an automatic resend.

Focused receipt evidence is kept at
`src/tests/Feature/Telegram/TelegramReceiptOutboundEffectTest.php`:
`test_invoice_failure_dispatches_product_detail_and_completion_ignores_photo_failure`
and `test_ambiguous_invoice_is_unknown_but_product_detail_still_delivers`
cover the invoice exception, while
`test_ordered_receipt_stops_after_an_ambiguous_credential_unit_without_resending_previous_units`
proves that a later unit remains pending after credential ambiguity.

## Operational recovery

The scheduler runs telegram:recover-outbound --queue=notifications every
minute with withoutOverlapping(). The command performs source discovery and
delivery recovery in this order:

1. TelegramOutboundEffectRecovery asks the ledger protocol to turn expired
   sending leases into unknown, fencing the old claim token.
2. The command selects due pending and retryable failed ledger rows through
   readyToDispatchQuery() and dispatches them through
   TelegramOutboundEffectDispatcher.
3. It materializes missing checkout, receipt, admin notification, Sales Proof
   Post, stock, and price effects from committed source state when the
   deterministic effect key proves that the effect is absent.
4. It redispatches recoverable Admin Broadcast handoffs through the same locked
   broadcast queue boundary.
5. Non-ledger cleanup work, such as deleting old customer-facing checkout
   messages, remains a separate cleanup job and is not treated as delivery
   claim authority.

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

recoverableBroadcastsQuery() selects every non-terminal broadcast whose
handoff is still unmaterialized, or whose materialized recipient effects are
due. Recovery force-dispatches the same queue boundary, so process death after
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
