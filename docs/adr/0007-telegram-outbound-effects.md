# Durable Telegram Outbound Effects

## Status

Accepted - 2026-08-17

## Decision

Durable business deliveries to Telegram use one transactional Outbound Effect Ledger written in the same database transaction as the Order, Payment, inventory, Sales Proof, or other source change that requires the delivery. The ledger tracks ordered delivery units with `pending`, `sending`, `sent`, `failed`, `unknown`, and `manual_review` outcomes, using a database lease and claim token for ownership. It does not include ephemeral callback acknowledgements or cleanup calls.

Telegram does not document an outbound idempotency key for `sendMessage` or `sendPhoto`, so a timeout, connection failure, or worker crash after the send may have an ambiguous outcome. Ambiguous effects are never blindly retried. They remain auditable for operator verification or an explicit new resend effect. Existing `orders.checkout_key` remains responsible for checkout mutation idempotency, while queue uniqueness and `afterCommit()` remain supporting optimisations rather than delivery guarantees.

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
