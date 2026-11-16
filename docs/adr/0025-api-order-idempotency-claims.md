# API order idempotency claims

## Status

Accepted — 2026-10-16. Implements [#623](https://github.com/Alhiefikri/telegram-bot/issues/623) (S06) under the binding contract of [#617](https://github.com/Alhiefikri/telegram-bot/issues/617) sections 4.2, 5, 5.1, 6, 7 and 10.

## Context

`POST /api/v1/orders` creates a paid, balance-funded Order. The customer's backend
may lose the response, time out, or restart, and may retry concurrently. Existing
`orders.checkout_key` idempotency ([ADR-0002](0002-telegram-direct-checkout-idempotency.md))
is derived from a Telegram **prompt message identity**, which a backend client does
not have. A request key supplied by an untrusted client therefore needs a durable
claim that binds the canonical payload, the resolved payment preference and the
admitted credential before any money moves.

## Decision

Add `customer_api_order_claims`, owned by `App\Services\CustomerApi\CustomerApiOrderClaims`.

- **Durable identity.** The unique index is `(customer_id, operation, idempotency_key)`
  with `operation = create_order`. The key is never scoped by credential version,
  so rotation cannot reset an accepted claim. Admission serializes new claims per
  Customer and uses the unique index to arbitrate any external insert race.
- **Canonical payload fingerprint.** A claim stores the SHA-256 fingerprint of the
  versioned canonical payload: Product id, normalized quantity, the normalized
  supported-input map (empty today), the *requested* preference mode and the
  normalized expected totals. A different fingerprint on the same key is
  409 `idempotency_conflict` and never re-uses the claim.
- **Resolved preference snapshot.** The claim stores the resolved preference
  (`request.preference` when supplied, otherwise the Customer's account preference
  read at admission) and its source. The canonical checkout is always called with
  that snapshot, so later account edits, price edits, restarts, replays and key
  rotation cannot change that request's outcome.
- **Fenced state machine.** `in_progress` with `attempt_token` and
  `lease_expires_at`; `accepted` with exactly one `order_id` (unique) and the
  immutable `orders.api_order_claim_id` origin marker. A claim is either accepted
  against an Order or not accepted at all: the PostgreSQL shape constraint couples
  `state = 'accepted'` with `order_id IS NOT NULL`, so no writer — in or outside the
  application — can store an acceptance without its Order. A retry may take over an
  abandoned claim only through one atomic conditional `UPDATE` on an expired lease.
  Each takeover attempt bounds its own row-lock wait (`lock_timeout`, hundreds of
  milliseconds) so it cannot queue behind a holder's financial commit; a timed-out
  attempt is a lost takeover, never an error and never a steal, and the public wait
  still ends at its documented bound. The financial transaction locks the claim
  after the Customer/credential fence, verifies the token before any checkout write
  and retains the row lock through commit. A suspended stale worker cannot purchase,
  and an expired lease cannot be stolen during financial commit. Acceptance rejects
  a stale token. Accepted claims never expire, are never pruned and can never
  create a second purchase. After rollback, `abandon()` releases only its own token
  for recovery.
- **Bounded non-accepted history.** Accepted claims are permanent. An in-progress
  claim is eligible for opportunistic deletion only at least 24 hours after its
  original creation, after its lease expires and while its row is locked against
  checkout/takeover. Existing Orders using its origin or canonical checkout key
  protect it from deletion. PostgreSQL excludes these economic recovery claims
  from the rejected-claim quota using indexed Order lookups. A stale worker cannot
  commit without re-locking its original claim and verifying its token. Each
  new-key admission examines at most 100 eligible rows; per Customer at most
  10,000 non-accepted claims without an Order are admitted. At capacity a new key
  gets 503 `temporarily_unavailable` with `Retry-After: 5`; cleanup commits even
  if legacy history still exceeds the cap, so retries make progress as leases
  expire. Accepted replays bypass the cap. After deletion a never-accepted key can
  be reused for a different payload; until deletion its fingerprint remains binding.
  SQLite lacks PostgreSQL's built-in SHA-256 expression: an unmarked legacy Order
  found only by canonical checkout key is still protected from deletion there,
  but remains counted and can occupy an early cleanup batch. If such exceptional
  legacy history exhausts SQLite capacity, reconcile/adopt the affected claims
  before retrying; never delete their economic evidence.
- **Namespaced canonical checkout key.** `api-` + `sha256('api-order:<claim id>')[0..60]`,
  exactly 64 characters, derived only from the durable claim row, unique on
  `orders`. A bot key is a 64-character HMAC hex, so the namespaces cannot collide.
- **Isolated API checkout inputs.** The API Cart and its single Cart item are created
  inside the canonical checkout transaction, so a rolled-back request leaves zero
  cart rows and a committed API cart exists only as `checked_out`. `carts.origin`
  (`customer` | `api`, immutable) keeps API carts invisible to every customer-facing
  cart read and write.
- **Money authority.** The accepted Order/Payment, the single wallet debit, stock and
  credential allocation, supplier reservations and durable work still commit inside
  the shared `OrderCheckoutService` transaction. The claim layer never writes money
  and never becomes a second checkout.
- **Revocation fence.** Each claim insert, takeover, lease release and financial
  transaction locks the Customer before revalidating the credential. New-claim
  admission takes Customer exclusive to serialize its quota; takeover, release
  and financial commit take Customer shared. Rotation/disable take Customer
  exclusive before the credential row. Revocation committed before the fence
  prevents claim mutation; an already-fenced mutation may finish first.
- **Audit identity.** The claim stores the admitted `credential_id` and safe
  request metadata. No credential-bearing HTTP response body is stored; replay
  metadata is reconstructed from the canonical Order/Payment/items.

## Consequences

- Three or more independent concurrent matching requests produce exactly one
  Order/Payment/debit/allocation; the losers either replay the accepted Order or
  receive 409 `in_progress` with `Retry-After` after a bounded wait that never
  queues behind the winner's locks for longer than one takeover attempt.
- Replay after a response loss, process restart, account preference edit, reseller
  price edit or API key rotation returns the same Order and the original
  currency/amount without reading current stock or price.
- A request only admitted before revocation but fenced afterwards fails with 401 and
  performs no mutation; the accepted pre-revocation semantics above are documented.
- The API and the Telegram/Web flows remain different purchase intents that compete
  safely for the same money and stock through the existing checkout locks.
- The financial lock graph gains `Customer shared -> credential shared -> claim
  FOR UPDATE -> isolated API Cart -> existing checkout chain`, published in the
  [balance A0 baseline](../balance-a0-baseline.md). Initial claim insertion commits
  separately under Customer exclusive → credential shared → claim/cleanup, with
  no locks held when entering the financial transaction. Insert and takeover
  PostgreSQL lock waits are bounded by the remaining public deadline.
- Claim identity and acceptance are immutable while retained. PostgreSQL guards
  reject deletion of accepted, recent, live-lease or Order-protected claims;
  eligible non-accepted rows may be deleted only after the retention period.
