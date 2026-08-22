# Durable Telegram Outbound Effects

## Status

Accepted - 2026-08-17

## Decision

Durable business deliveries to Telegram use one transactional Outbound Effect Ledger written in the same database transaction as the Order, Payment, inventory, Sales Proof, or other source change that requires the delivery. The ledger tracks ordered delivery units with `pending`, `sending`, `sent`, `failed`, `unknown`, and `manual_review` outcomes, using a database lease and claim token for ownership. It does not include ephemeral callback acknowledgements or cleanup calls.

Telegram does not document an outbound idempotency key for `sendMessage` or `sendPhoto`, so a timeout, connection failure, or worker crash after the send may have an ambiguous outcome. Ambiguous effects are never blindly retried. They remain auditable for operator verification or an explicit new resend effect. Existing `orders.checkout_key` remains responsible for checkout mutation idempotency, while queue uniqueness and `afterCommit()` remain supporting optimisations rather than delivery guarantees.

### Final protocol boundary

`TelegramOutboundEffectLedgerProtocol` is the sole durable delivery-state
seam. It owns row-locked claim ownership, leases, claim-token fencing, state
transitions, ambiguity classification, retry scheduling, terminal-failure
rules, receipt ordering, and operator-confirmed finalization. The family policy
supplies attempt limits and ordering constraints without becoming a second
state machine.

Effect-family adapters own only eligibility, source loading, rendering,
Telegram transport, and their read projection. The dispatcher maps effect
types to queue jobs. Materialization and safe review summaries remain in the
effect service, but no caller may reintroduce claim/finalization pass-throughs
or a family-specific delivery-state switchboard. See the companion
[architecture and recovery contract](../telegram-outbound-effect-architecture.md).

An effect is `sent` only after a successful Telegram response with a valid
positive integer message identifier. Transport ambiguity and expired leases
become `unknown`; they are not returned to `pending` by recovery. Operator
resolution uses the same protocol boundary, while an explicit resend creates a
new auditable effect version and leaves the ambiguous record unchanged.

### Receipt ordering exception

Receipt effects remain ordered, but the first invoice photo is a
non-credential unit. Its `failed` state (including a deterministic render or
definite Telegram failure) and its `unknown` state (an ambiguous transport
outcome) are both resolved for ordering, so Product Detail may continue. The
invoice row remains independently reviewable; `unknown` is never converted
back to `pending` or automatically resent. An invoice in `manual_review`
remains blocked until an operator marks it sent or creates an explicit resend.

Every later Product Detail/text unit is credential-bearing or part of the
credential delivery plan and keeps strict predecessor ordering. A predecessor
in any state other than `sent` — including `failed`, `unknown`, or
`manual_review` — blocks later units. Operator mark-sent finalization unlocks
the next unit without resending the predecessor. Claim-token fencing and the
duplicate-risk acknowledgement required for explicit resend are unchanged.

Buyer fulfillment completion is projected from successful text/credential
delivery, not invoice-photo success. When all non-invoice units are `sent`,
the pipeline writes `orders.telegram_receipt_sent_at` and dispatches the
best-effort `DeleteBuyerCheckoutMessages` cleanup. Cleanup may therefore run
while the invoice remains `unknown`; it does not alter the invoice ledger
outcome, delete the review history, or trigger an automatic resend.

The focused contract is covered by
`TelegramReceiptOutboundEffectTest::test_invoice_failure_dispatches_product_detail_and_completion_ignores_photo_failure`,
`TelegramReceiptOutboundEffectTest::test_ambiguous_invoice_is_unknown_but_product_detail_still_delivers`,
and
`TelegramReceiptOutboundEffectTest::test_ordered_receipt_stops_after_an_ambiguous_credential_unit_without_resending_previous_units`.


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
`telegram:recover-outbound` command selects every non-terminal broadcast whose
handoff is still unmaterialized, and redispatches it through the same locked
queue boundary as the original request. `queue_dispatched_at` records a
dispatch attempt but never hides an unmaterialized handoff from recovery.

This exception preserves asynchronous recipient fan-out, recipient
independence, locale snapshots, batching, rate limiting, and ambiguous-delivery
handling. No recipient-level Telegram Outbound Effect is considered durable
until its recipient snapshot exists; the committed broadcast handoff is the
recovery guarantee that closes that interval.

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
- Admin Broadcast remains the documented deferred-materialization exception:
  the committed broadcast row with a null `recipients_materialized_at` is the
  durable handoff, and recovery redispatches that handoff through the same
  locked queue boundary until recipient effects are materialized.
