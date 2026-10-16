# Reseller price overrides (Product-owned)

Status: implemented and development-verified for GitHub issue #619 (work package S02 of the #617 map). No production activation, deployment, or CI operation is part of this record.

## Outcome

Owner/Admin can set one optional reseller price per sellable Product and currency without changing retail pricing and without a second pricing calculator. The override is Product-owned data (`products.reseller_price_amount`, `products.reseller_usdt_price_amount`), stored in the database and edited through the existing Admin Dashboard surfaces; no new ENV or configuration key exists.

## Canonical pricing contract

`App\Services\Pricing\ProductQuantityPricing` remains the single quote owner.

- `quote(Product $product, int $quantity, ?ResellerPricingContext $context = null)`.
- With `$context === null` the quote is exactly the existing quantity-tier quote: base unit amount, greatest reached `Quantity Price Tier`, `appliedTierMinimum`, `nextTier`, plus the paired IDR/USDT subtotals. Bot, Web Storefront, Admin previews, paid-invoice presentation, and supplier capacity readers keep calling it without a context.
- With a trusted `$context` the Product's override for a currency, when present, becomes the final per-unit amount for that currency, multiplied by quantity:
  - IDR: `reseller_price_amount`.
  - USDT (micro-USDT backing the dollar-denominated balance): `reseller_usdt_price_amount`.
- Overrides are independent per currency. One currency may use an override while the other still uses its normal tier schedule.
- An applied override never stacks with a quantity tier. `appliedTierMinimum` (IDR) and `appliedUsdtTierMinimum` (USDT) are null for the overridden currency, and `ProductPriceQuote` rejects a quote that tries to hold both.
- `null` is "no override": the currency falls back to the normal quantity quote. Clearing an override in Admin deletes the value and restores normal behavior on the next quote.
- A USDT override requires the Product's base `usdt_price_amount`. Without it the override is not persisted (Admin validation, PostgreSQL CHECK `products_reseller_usdt_price_amount_requires_base_price`) and never applies at quote time, so an override cannot manufacture dollar eligibility.
- Persisted overrides must be positive. A non-positive stored value (data that bypassed validation) fails closed with an `InvalidArgumentException` instead of quoting `0` or a negative amount; subtotal overflow is rejected by the same multiply guard as base and tier prices.
- Every amount stays an integer: whole IDR and integer micro-USDT. No floating point and no FX conversion is introduced.
- The overrides live on the `products` row, so a caller that selects a partial Product column set without them quotes retail pricing. API catalog and quote readers must select `reseller_price_amount` and `reseller_usdt_price_amount` together with the `quantityPriceTiers` relation.

`ResellerPricingContext::forAuthenticatedApiRequest(int $customerId)` is the trusted value object that proves the request belongs to the reseller/API channel. It can only be built by the authenticated API boundary (`#621`/`#623`); it carries the authenticated actor and origin for snapshots and audit, never price terms. Client input cannot construct it, and passing nothing keeps retail pricing.

## Shared snapshot and checkout

- `CheckoutPricingSnapshot::fromCartItems($items, $products, $pricing, ?ResellerPricingContext $resellerContext = null)` quotes every Cart Item once with the same channel and stores the context (`isResellerChannel()`, `resellerOrigin()`).
- `OrderCheckoutService::checkout(..., ?ResellerPricingContext $resellerContext = null)` passes the trusted context into that snapshot after its existing lock order (`cart → balance account when applicable → maintenance → cart items/products → payment setting`). Order, `OrderItem.unit_price`/`subtotal`/`usdt_*`, the balance debit amount and every payment preparation consume the identical snapshot, so the previewed quote and the charged total cannot diverge.
- Because both currencies are quoted in the same pass and `OrderItem.quantity_price_tier_minimum` comes from that quote, a reseller order records `null` there when the override replaced the tier: no incidental discount stacking.
- Supplier economics are preserved: `SupplierFulfillmentService::reserve()` and `SupplierPricing::isEligible()`/`maximumCostIdr()` keep using the charged IDR subtotal and the Product's `SupplierPricePolicy` minimum profit, so a reduced reseller price makes eligibility stricter and can fail closed with `InsufficientStock` instead of buying external units below the guard. Ineligible products simply stay unbuyable through the reseller channel.
- Locking is unchanged: Product rows are locked before the snapshot is built, and Admin price/tier replacement runs under the same Product row lock, so a checkout observes one complete old or one complete new schedule.

## Admin surfaces

Both existing Admin paths carry the overrides, with the existing role, validation, audit, and version patterns:

- Workspace price section (`PATCH /admin/products/{product}` with `workspace_section=price`): `reseller_price_amount` and `reseller_usdt_price_amount` joined `AdminProductWorkspaceData::SECTIONS['price']`, so they participate in the per-section SHA-256 `workspace_version` token and a stale save still fails with 409 before any write.
- Dashboard product create/edit modal: the same fields are validated by `AdminProductFormRequest::productRules()` (`nullable|integer|min:1` after the decimal-to-micro-USDT normalization shared with `usdt_price_amount`) and written by the create/update controllers.
- Cross-field rule: an effective USDT override without an effective base USDT price is a 422 on `reseller_usdt_price_amount` (also enforced under the Product lock and by the PostgreSQL CHECK). `0`, negatives, malformed decimals, and absent-base `usdt_price_amount` are rejected without mutating the Product or writing audit rows.
- Identity, role, audit, and lock handling are inherited: Owner/Admin only (`ProductPolicy`), `product.created`/`product.updated` audit includes the override columns in `old_values`/`new_values`, and rejected saves write no audit.
- A reseller-only price change does not create a customer `ProductPriceChangeEvent` (that pipeline reads base retail prices), so retail customers are not notified about a channel they cannot buy from.

## Bounded query shape

Measured in `ProductResellerPricingTest::test_batch_quote_reads_stay_constant_for_one_twenty_and_hundred_products`, with the query log as evidence:

| Page size | Statements to load the page with `with('quantityPriceTiers')` | Statements to quote the page via `quoteMany()` |
| --- | --- | --- |
| 1 Product | 2 (products + tiers) | 0 |
| 20 Products | 2 | 0 |
| 100 Products | 2 | 0 |

Overrides live on the same `products` row, so they add no relationship query; no credential rows are read. `quoteMany()` refuses a page without the `quantityPriceTiers` relation instead of falling back to one tier query per Product, so a missing eager load is a loud programming error rather than a linear N+1.

## Verification evidence

Executed locally against the repository test lanes and disposable runtimes:

- Full application regression (`php artisan test`): 4,677 tests, 4,449 passed, 228 pre-existing PostgreSQL/opt-in skips, 0 failures, 43,073 assertions.
- `php artisan test --filter='ProductResellerPricingTest'` (12 tests, 453 assertions) and the pricing/admin/checkout/catalog/storefront/Telegram/supplier regression batch `--filter='ProductQuantityPricingTest|...|SalesProofPostFormatterTest'` (480 tests, 11,442 assertions) pass. `ProductResellerPricingTest` covers retail invariance, independent per-currency overrides, null fallback and clearing, no tier stacking, absent-base USDT ineligibility, corrupt/overflow rejection, the SQLite-lane model guard, the batch query shape, charged-total equality through `OrderCheckoutService`, and the supplier profit guard.
- `npm run test:js` (181 tests) passes, including the workspace draft seed, the posted reseller values, and the empty-value clear path.
- Real browser surface: `npx playwright test tests/Browser/admin-dashboard-responsive.spec.js -g "reseller prices"` passes the scenario *workspace creates edits and clears reseller prices without touching retail prices* against a disposable SQLite database and a locally served app at 390×900 — it saves `40000` IDR / `1.5` USDT, reloads and re-reads the persisted values, edits them to `41000` / `1.6`, then clears both and asserts the retail `45000` IDR and `2` USDT inputs never changed and the document has no horizontal overflow.
- Disposable runtime quote smoke: `php artisan migrate --force` plus `php artisan tinker` against a temporary SQLite database printed, for quantity 2, the retail quote (IDR 24,000/unit and USDT 480,000/unit, both with tier minimum 2) next to the reseller quote (IDR 20,000/unit and USDT 400,000/unit, both tier markers `null`), and the real `OrderCheckoutService` charged `total_amount = 40000`, `unit_price = 20000`, `quantity_price_tier_minimum = null` with `quote_equals_charged_total = true`.
- PostgreSQL 16 in a disposable local container against an isolated `*_test` database: `ProductResellerPricePostgresConcurrencyTest` (2 tests, 44 assertions) passes. The deterministic scenario proves a checkout that starts while an Admin price-section save holds the Product row lock waits for the commit and then charges the complete new schedule; deleting the checkout's `lockForUpdate()` made it charge the stale old price, so the proof is not vacuous. Three additional Admin-save/checkout rounds charge either the complete old or the complete new schedule, never a mix.
- No production data, production access, deployment, or CI run was used.

## Boundaries and non-goals

- Per-reseller negotiated pricing, membership tiers, discount campaigns, a Variant model, FX conversion, and retail repricing are out of scope.
- The authenticated API surface that constructs `ResellerPricingContext` (credential lifecycle #621, catalog/quote reads #622, API orders #623) is a later work package; this slice provides the trusted seam and the shared snapshot that those surfaces must call.
- Capacity projections (`supplier_available_stock`) still describe the retail price basis; reseller-channel eligibility is enforced fail-closed at checkout by the existing supplier guard.
