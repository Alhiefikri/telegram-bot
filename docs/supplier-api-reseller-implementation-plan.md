# Supplier API Reseller — implementation plan

Status: implemented and development-qualified on `feature/supplier-api-reseller`. Production activation and real purchases are not authorized by this development task.

## Scope and acceptance

- Keep the existing Product identity and customer-owned ordering flows. Connect an equivalent supplier SKU to an existing Product, or create a draft Product from a selected supplier catalog entry. Never overwrite customer-facing names, descriptions, icons, guides, or manual retail prices during synchronization.
- One Admin-managed Supplier connection supports many mapped SKUs. Support ProdSeller, CSxStore, ARRS, Warzone, AI Vault Hub, NyStore Official, and ToolOraX (ShopBot) through provider-specific normalized contracts. New SKUs do not need code; a new API contract does.
- Supplier name and API key are entered in the Admin Dashboard. Encrypt credentials in database, hide them from serialization and forms after saving, never commit supplied credentials or add supplier-key ENV settings.
- Support instant link/key/account delivery. Products explicitly requiring customer-email/manual activation are visible in private supplier catalog but cannot be activated as instant-delivery products. No pretend success or invented activation contract.
- Product-owned manual or automatic IDR pricing: percentage markup, fixed profit, or percentage with minimum profit; integer currency calculations, configured supplier conversion rate, bounded rounding, and minimum-profit purchase guard. Existing quantity retail tiers remain authoritative. Paid/pending Order Item retail snapshots never change retroactively.
- Maintain local stock separately from external stock. Local credentials first when available, supplier fulfillment for the remainder. External availability is a projection, never locally owned inventory. Supplier balance and in-flight commitments bound capacity. Checkout transaction rechecks capacity and reserves commitments atomically.
- Sync catalog/balance periodically with bounded work and freshness cutoff. A failed read does not manufacture a zero-stock/restock transition. Stale supplier data cannot authorize new checkout.
- Existing Telegram stock notifications, Store Channel notifications and Web waitlist pipeline handle genuine Product unavailable-to-available transitions. Customer content remains store-branded. Repeated sync and supplier-source changes do not duplicate restock events.
- After authoritative payment settlement, buy supplier units outside DB transactions. Durable purchase records survive queue loss. Fulfillment remains pending until complete delivery evidence is stored encrypted. Customer payment remains Paid if supplier rejects.
- Fallback only after a proven no-purchase outcome and only for equivalent, eligible sources within immutable order cost ceilings. Ambiguous outcomes never authorize a new supplier attempt or a refund that races a purchase.
- Store Balance refund stays with OrderLifecycle/StoreBalanceLedger. External refund confirmation stays with existing manual review policy, never fake automatic external refund and never silently convert to Store Balance.
- Unit, HTTP feature, lifecycle integration, PostgreSQL concurrency, bounded-query and security tests plus actual Admin/CLI runtime smoke evidence.

## Module ownership

`App\\Services\\Suppliers` owns normalized catalog/order contracts, private connections, synchronization, pricing and external purchase attempts. Existing owners remain canonical:

- OrderCheckoutService: checkout transaction, Order/OrderItem snapshots, shared lock sequence.
- InventoryLedger: local reservation/sale/release movement accounting.
- OrderLifecycle/OrderStateMachine: payment status and order fulfillment transitions; refund authority.
- ProductCredentialAllocator/CredentialDeliveryPresentation: local allocations and customer-owned paid delivery.
- StockNotificationDeliveryPipeline and ProductWaitlistNotificationService: restock delivery/dedupe.
- Admin adapters: role/CSRF/validation and private presentation, no direct financial mutations.
- PostgreSQL: durable supplier commitment and purchase state; Redis jobs only wake up recoverable work.

## State and concurrency invariants

1. Integer micro-units for supplier balances/prices; whole-IDR retail and cost amounts. Never binary floating-point arithmetic for economic decisions.
2. Product locks in ascending ID order precede supplier resource locks in ascending ID order. Order lifecycle locks Order/Payment before OrderItems/Product resources; Store Balance account stays before Order on refund. Network calls, queue dispatch and Telegram delivery do not occur inside financial locks.
3. Checkout reserves supplier wallet capacity and SKU units atomically, independent of local stock reservation. A unique OrderItem fulfillment identity prevents duplicate reservation/economic effect.
4. A purchase has a stable supplier idempotency reference and explicit persisted dispatch/claim token. Mark intent durably before sending HTTP. Token-checked finalization rejects stale workers. An expired dispatched claim means ambiguous outcome, not a free retry.
5. Non-idempotent providers receive at most one automatic POST for a purchase intent. Known order IDs recover via GET. Missing IDs without a safe correlation contract require explicit operator review; matching name/time/quantity is not proof.
6. Provider-specific documented dedupe may replay the same immutable request/key; never generate a replacement key or switch supplier on transport ambiguity.
7. Reserve/settle/release commitments exactly once. An ambiguous purchase retains its financial hold until an operator obtains evidence. Confirmed external debit updates local wallet observation conservatively and triggers re-sync.
8. Refund/cancel cannot release or credit an in-flight or ambiguous supplier purchase. Refund and successful finalization serialize on the same Order/resource locks. Delivered credentials are never returned to Available stock.
9. Persist supplier response delivery securely before customer delivery. Repeating Telegram delivery uses stored credentials, never a second purchase.
10. Mixed orders are not complete until all local and external units are satisfied. Partial supplier payload never produces complete fulfillment; retain evidence and review instead of repurchasing the full quantity.
11. Sync results are generation/version guarded; stale reads cannot overwrite changed credentials or later sync/purchase observations. Resource commitments survive sync replacement.
12. Product-level availability transitions, not individual supplier restocks, own notification identities. Source failures are distinct from authoritative stock zero.

## Security and performance

- Fixed HTTPS provider base URLs; no arbitrary user-controlled host, URL, redirect or server-side delivery-link fetch. Disable HTTP redirects so API-key headers never travel to an alternate origin.
- Supplier IDs/SKU IDs/order references validated and path-encoded. Reject header control characters and excessive key/body sizes. Bounded JSON parsing and payload counts/string lengths.
- Keys/credentials hidden, encrypted and not mass-assigned from public input. Do not use raw provider responses, supplier error strings or exception chains in customer messages, audit logs, logs, session old input, or public APIs.
- Owner/Admin management only, existing role guard and CSRF; finance/support cannot read secrets. Supplier audit records contain safe IDs, counts and changed-field names only.
- Customer order ownership and paid eligibility remain enforced by shared credential presentation. Supplier relationships and cost metadata are private even if eager-loaded.
- Project availability into Product for list reads; preserve `withAvailability()` and batched relationship reads, no per-Product supplier HTTP/query loop in customer catalog rendering.
- Bounded pages, bulk catalog upserts, indexed due-state scans, bounded retries and request budgets. Per-provider/account/IP HTTP rate budgets; purchasing must not be starved by full-catalog syncing.
- No HTTP under pessimistic locks; no unbounded history sweeps or polling every SKU at request time. Freshness and timestamps visible in private Admin.

## Delivery sequence

1. Branch and repository/API/Context7 research; freeze schema/interfaces and test seams.
2. Private supplier models/schema, exact pricing DTOs and provider transport/normalization.
3. Admin management and catalog import/mapping on those contracts.
4. Availability/pricing sync and existing restock handoff; separate local/external capacity.
5. Checkout commitments and paid durable fulfillment with safe replay/recovery/review/refund gates.
6. Behavior tests and PostgreSQL race tests; integrate once, run focused and existing suites, smoke actual CLI/Admin surface.
7. Update implementation map, glossary/ADR, operator runbook, README/release notes and evidence. Remove temporary verification scaffolding.

## Agreed test seams

User requested expanded tests and safety verification. Exercise Admin HTTP authorization/secret handling, provider normalized public operations with fake HTTP, exact pricing/availability results, actual checkout + OrderLifecycle + paid customer credential presentation, restock effects, and concurrent PostgreSQL checkout/purchase/refund/sync. Do not test copied strings, private methods or forwarding-only mocks.

## Primary references and unresolved supplier contracts

- ProdSeller: https://prodseller.com/api-docs/ — Idempotency-Key and order status; balance USDT; list may omit stock, details provide it; email activation is not instant.
- CSxStore: https://api.cssx.store/docs — USD prices; references idempotency but does not specify identifier field/header. One-shot purchase with ambiguous outcome review until a verified contract exists.
- ARRS: https://arrsnetworkzone.in/docs — quantity/stock/wallet/status, possible stock buffer; no documented dedupe; currency requires explicit Admin confirmation, not a guessed dollar symbol.
- Warzone: https://api.warzoneshop.in/ — orderable flag, tier/custom prices; 400 no debit, 429/503 documented no purchase; POST 500 may have created order. No documented dedupe; currency requires confirmation.
- AI Vault: https://reseller.aivaulthub.store/api/v1/docs — USD, tiers/discounts, quote and external_order_id. Do not set accept_normal_price to override a cost guard.
- NyStore Official: https://nystoreofficial.shop/ (API docs at `/`, base `/api/v1`) — X-API-Key, USD wallet (`wallet_balance`), `services[]` catalog, POST `/order` with required `Idempotency-Key` and `precheck/include_proofs` flags (the driver sends `false/false`, documented fastest mode; admin audit still runs after delivery), `order_status` success/pending semantics and `delivered_products[]`. A POST 503/409 can carry a saved `order_id`; the driver keeps it as identity evidence for GET recovery. Balance and catalog verified read-only; a live purchase is not yet qualified.
- ToolOraX (ShopBot): https://shopbot.00969600.xyz/shop-api/v1 — `X-Shop-API-Key` (Bearer also accepted), USD wallet (`balance`), `products[]` with `unit_price`/`stock_count`/`in_stock`, POST `/orders` `{product_id, quantity}`, order read `GET /orders/{order_code}` with `status: fulfilled` and `delivered_keys[]`. The create-response envelope is undocumented; the driver keeps the returned order code and hydrates it with one canonical GET when the create body lacks a status. Documented pre-purchase conflicts (insufficient balance, quantity range) reject safely; balance and catalog verified read-only, and a zero-balance purchase attempt resolved as a safe rejection. A funded live purchase is not yet qualified.
- Context7 resolved `/laravel/docs`, branch `13.x`, via https://context7.com/api/v2/libs/search?libraryName=laravel . Retrieved transaction/pessimistic-lock, afterCommit, HTTP timeout/fake and hidden/encrypted-attribute documentation via `/api/v2/context`.
- Laravel primary docs: https://laravel.com/docs/13.x/queries#pessimistic-locking ; https://laravel.com/docs/13.x/queues#jobs-and-database-transactions ; https://laravel.com/docs/13.x/eloquent-mutators#encrypted-casting ; https://laravel.com/docs/13.x/eloquent-serialization#hiding-attributes-from-json ; https://laravel.com/docs/13.x/http-client .

Documentation and fake-HTTP tests establish integration behavior, not supplier warranty, product legitimacy, live price locking or production purchase qualification. No real order will be submitted for development smoke. Already-shared supplier keys must be rotated before funding production accounts.

## Development verification evidence

- Full application regression run: 2,882 tests, 87 skipped. PostgreSQL concurrency gate: authoritative JUnit report records 114 tests, no skips, failures or errors.
- Integrated supplier regression run: 142 tests, 789 assertions, no failures or skips.
- Final Admin/fulfillment regression run after adding unresolved-liability monitoring: 29 tests, 258 assertions. The same manual-replacement economic recovery scenario passes PostgreSQL: 1 test, 22 assertions.
- Final supplier race lane explicitly sets `RUN_CONCURRENCY_TESTS=1`: 5 tests, 47 assertions, no skips. A separate PostgreSQL fulfillment run passes all 12 fulfillment tests; its 5 concurrency cases were skipped without that flag and are covered by the explicit race lane, not counted as proof from the skipped run.
- Disposable runtime smoke exercises actual checkout, verified manual payment, durable supplier recovery command, encrypted customer-owned delivery, repeated wakeup deduplication, actual debit settlement and refund credential retirement, with fake provider HTTP and no live purchases.
- Real Admin browser smoke exercises dashboard credential entry without echoing the saved key, GET-only catalog sync, inactive draft import, equivalent source activation and automatic fixed-profit retail of Rp16,000. At 390px viewport, pricing and purchase monitoring have no horizontal overflow.
- Final browser scenario starts with completed manual customer delivery and an ambiguous original purchase. The monitor retains the unresolved liability, offers recovery only, accepts the verified original order through the real Admin form, and removes its action after the purchase becomes `settled`. Manual customer credentials are absent from the rendered page.
- These are development proofs, not live supplier debit, fulfillment validity, warranty, resale-rights or production deployment proofs. Follow the operator runbook and explicitly authorize controlled external qualification before enabling production sources.
