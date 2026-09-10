# Store Balance ledger and projection

## Status

Accepted - 2026-09-10

## Decision

Store Balance is a Customer-owned IDR balance represented by two tables:

- `customer_balance_accounts` stores exactly one account row per Customer and
  the current available balance projection;
- `store_balance_entries` stores immutable, append-only ledger entries.

Every entry carries a positive integer IDR `amount`, a `kind`
(`top_up_credit`, `purchase_debit`, `refund_credit`), the resulting
`balance_after`, an immutable economic `source_type`/`source_id`, an
`idempotency_key`, and a creation timestamp. The kind owns the direction; no
entry stores a negative amount.

`StoreBalanceLedger` is the only seam that may move money. One database
transaction locks the Customer account row, deduplicates on the idempotency
claim and on the economic source identity, appends one entry, and updates the
projection. Reapplying the same source or key returns the existing economic
result without a second entry. Insufficient funds raise
`InsufficientStoreBalance` inside the transaction, so no account, entry, or
partial projection survives.

PostgreSQL check constraints keep the committed projection and the recorded
`balance_after` non-negative and the amount positive; unique indexes on
`(customer_balance_account_id, idempotency_key)` and
`(source_type, source_id, kind)` prevent duplicate economic effects even if an
application path is bypassed. The account `balance` column is not mass
assignable, so Eloquent callers cannot set it directly.

`StoreBalanceLedger::snapshot()` reads the projection as a bounded point read
and verifies it against the newest immutable entry. A projection that drifted
is reported as unavailable instead of being displayed, which keeps the
Telegram home from inventing funds.

`store-balance:check` (`StoreBalanceReconciliation`) compares each account
projection with the signed sum of its entries and reports mismatches without
changing money. A repair is intentionally not automatic: a later repair must
be an explicit, idempotent, and audited operation.

Global Store Activity metrics (Telegram Customers and canonical successful
Orders) stay in the bounded-staleness Telegram cache. A Customer's Store
Balance is never stored in shared global cache and is always read fresh.

Funding-policy configuration for Store Balance top-ups is deliberately outside
this ledger. `StoreBalanceFundingPolicyManager` owns its versioned singleton,
strict provider-bounded validation, and operator audit; changing the minimum or
preset list never creates a balance entry or changes any Customer projection.

## Consequences

- Money moves only through ledger-owned transactions; controllers, jobs, and
  current checkout/refund callers consume `StoreBalanceLedger` instead of
  writing the projection.
- Store Balance checkout is an internal full-payment rail. The canonical
  `OrderCheckoutService` transaction locks the Cart/Product snapshot, debits
  the exact IDR Order total through this ledger, and delegates paid state and
  fulfillment to `OrderLifecycle`.
- Store Balance refunds are owned by `OrderLifecycle` and call
  `StoreBalanceLedger::refund()` with the original Order source identity.
  The refund requires the matching purchase debit and is replay-safe.
- Idempotency is enforced twice: the account lock serializes same-Customer
  claims in the application, and the unique indexes are the final database
  guard for concurrent or out-of-band writes.
- Rendering an untrusted projection shows a localized recovery state rather
  than a numeric balance.
- The check command is advisory evidence; a mismatch fails the command and
  requires operator action. Money is never silently repaired.
