# Private Supplier Fulfillment and external capacity

Status: Accepted for `feature/supplier-api-reseller`; production activation requires operator qualification.

The store keeps its existing Product identities and customer payment lifecycle while privately mapping equivalent Supplier products as fulfillment sources. Supplier stock is a freshness-limited capacity projection, not owned inventory: local stock and supplier wallet/SKU commitments remain separate, and an explicit `availability` movement feeds existing restock delivery without pretending that supplier restock was local Stock Intake.

Payment confirmation creates a durable external acquisition obligation rather than proof of completed delivery. PostgreSQL retains immutable source/cost snapshots, purchase identity, commitments, dispatch claims and encrypted delivery evidence; Redis only wakes recoverable work. HTTP occurs outside financial locks, and successful delivery gates receipts and completion. Supplier identity, modal prices, API credentials and raw provider failures remain private operator data. API keys are entered through Admin and encrypted in database rather than requiring ENV changes.

We rejected direct supplier calls inside paid/checkout transactions and copying supplier stock into `Product.stock`: transaction retries could double-spend, and shared external stock/balance cannot satisfy local reservation invariants. We also rejected automatic fallback after every error. A timeout may already have bought the product; only a documented no-purchase result permits a replacement source. Known supplier order IDs recover via reads; narrowly bounded same-identity replay is allowed only for documented provider idempotency. Unresolved attempts retain their holds and require operator evidence, including before refund.

The consequence is intentionally conservative availability and occasional Manual Fulfillment Review rather than an unprovable exactly-once promise across third-party APIs. Store Balance refunds remain owned by OrderLifecycle/StoreBalanceLedger; externally funded customer payments require the existing confirmed external-refund path, not a silent conversion into Store Balance. Supplier delivery credentials retain order ownership and never return to the Available Product pool.

See [implementation plan](../supplier-api-reseller-implementation-plan.md) for provider contracts, lock ordering, acceptance and verification scope. This extends rather than supersedes ADR-0002, ADR-0007, ADR-0008 and ADR-0014.
