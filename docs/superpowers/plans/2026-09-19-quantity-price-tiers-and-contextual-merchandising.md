# Implementation Plan: Quantity Price Tiers and Contextual Merchandising

This plan operationalizes [`docs/superpowers/specs/2026-09-19-quantity-price-tiers-and-contextual-merchandising.md`](../specs/2026-09-19-quantity-price-tiers-and-contextual-merchandising.md).

## Branch and baseline

- Work branch: `feat/quantity-price-tiers` or a descendant ticket branch.
- Never implement or commit this work directly on `main`.
- Parent issue: [#492](https://github.com/Alhiefikri/telegram-bot/issues/492).
- Child issues: #493 (T1), #494 (T2), #495 (T3), #496 (T4), #497 (T5), #498 (T6), and #499 (V1). Each child body is authoritative for its session scope.
- Preserve ADR-0002 direct-checkout idempotency, ADR-0005 customer voice, ADR-0006 provider compatibility, ADR-0007 Telegram Outbound Effects, ADR-0008 Store Balance ledger ownership, and the accepted checkout-payment assembly architecture.
- Existing Products with no tiers must remain behaviorally identical.
- Current Web Storefront quantity cap remains 20; tier thresholds are therefore limited to 2–20.

## Shared contracts every agent must consume

1. **Quantity Price Tier** is Product-owned and applies to one Cart Item. Quantities never aggregate across Products.
2. The greatest configured minimum not greater than quantity wins.
3. Base Product price remains the quantity-one price and SEO/catalog default.
4. Tier schedules contain zero to five rows; thresholds and IDR/USDT unit amounts strictly descend in price as quantity increases.
5. `ProductQuantityPricing` (final repository-equivalent name) is the only tier-selection and catalog-subtotal module.
6. Checkout quotes after Product locks, once, and shares the immutable quote with all payment adapters and Order Item persistence.
7. Order Items snapshot the applied threshold. Historical presentation never joins mutable tiers to infer bulk pricing.
8. Admin tier replacement locks Product and commits Product fields, tiers, and audit data atomically.
9. Store Bot/Web calculations are previews; checkout always re-quotes under lock.
10. Do not add pricing or presentation ENV values.
11. Do not auto-broadcast tier edits.
12. Do not convert Sales Proof into generic advertising.
13. Each implementation ticket runs only focused validation. V1 owns the broad matrix.

## Ownership and dependency graph

```text
T1 pricing foundation ─────┬── T2 Admin management ───────────────┐
                           └── T3 checkout snapshots ─┬── T4 Bot UX ───────┤
                                                     ├── T5 Storefront ───┤── V1 verification
                                                     └── T6 messaging ────┘
```

- T1 has no implementation blocker.
- T2 and T3 may run in parallel after T1.
- T4, T5, and T6 require T3; they may run in parallel with distinct file ownership.
- V1 waits for T1–T6.

## Tiny-commit sequence

Every implementation child should use small commits in this order:

1. Add focused behavior tests for that ticket's contract.
2. Implement the smallest complete module/surface change.
3. Migrate every caller owned by the ticket; remove obsolete arithmetic/copy/callback paths.
4. Run only the listed focused verification.
5. Attach command results and any browser/Telegram evidence to the issue.

No ticket may hide an incomplete path behind a fallback, deprecated alias, feature flag, TODO, or “temporary” base-price branch.

## T1 — Persist Quantity Price Tiers and build canonical pricing

### Goal

Create the Product-owned tier schedule, immutable quote, and one canonical selection/arithmetic module without changing checkout or customer surfaces yet.

### Primary files

- `src/database/migrations/*create_product_quantity_price_tiers*.php`
- `src/database/migrations/*add_quantity_price_tier_minimum_to_order_items*.php`
- `src/app/Models/Product.php`
- new `src/app/Models/ProductQuantityPriceTier.php`
- new pricing module/value objects under `src/app/Services/Pricing/` or the nearest existing Product domain namespace
- `src/database/factories/ProductFactory.php`
- new tier factory only if tests genuinely need one
- focused model/pricing tests under `src/tests/Feature/` or `src/tests/Unit/`

### Implementation steps

1. Add `product_quantity_price_tiers` with Product FK, unique Product/minimum threshold, ordered index, positive row constraints, and timestamps.
2. Add nullable `order_items.quantity_price_tier_minimum`; do not backfill old Orders.
3. Add the Product relation and deterministic minimum-quantity ordering.
4. Implement an immutable `ProductPriceQuote` and canonical `ProductQuantityPricing::quote(Product, quantity)` equivalent.
5. Resolve base vs greatest reached threshold for IDR and USDT together.
6. Validate quantity, schedule completeness, monotonic price descent, and integer multiplication overflow.
7. Fail closed on an invalid persisted schedule; do not silently use base pricing.
8. Keep reads bounded to five rows and avoid a pricing cache.
9. Add focused boundary tests for no tiers, threshold minus one, exact threshold, between tiers, highest tier, absent/present USDT, invalid schedules, and overflow.

### Acceptance

- Existing Product with no tiers quotes existing IDR/USDT amounts exactly.
- Exact threshold activates the correct tier; the greatest reached threshold wins.
- IDR and USDT use the same applied threshold.
- Quote exposes base/effective amounts, subtotals, applied minimum, next tier, and ordered schedule.
- Invalid persisted schedules fail closed.
- Old Order Items remain readable with a null applied-tier snapshot.

### Verification

Run the new focused pricing/model/schema tests and the existing core schema/model cast test from `src/`. Do not run formatter, linter, browser tests, or the full suite.

### Non-goals

No Admin form, checkout adapter, Store Bot, Storefront, notification, Sales Proof, or documentation-map change.

## T2 — Manage Quantity Price Tiers in Admin Dashboard

### Goal

Let authorized Admin users create, edit, validate, remove, and audit the complete Product tier schedule from the existing Product modal.

### Primary files

- `src/app/Http/Requests/Admin/AdminProductFormRequest.php`
- `src/app/Http/Controllers/Admin/AdminProductCreateController.php`
- `src/app/Http/Controllers/Admin/AdminProductUpdateController.php`
- a focused Admin pricing mutation module if needed to keep controller transactions shallow
- `src/app/Services/Admin/AdminDashboardProductsData.php`
- `src/app/Services/Admin/AdminProductDashboardState.php`
- `src/resources/views/admin/dashboard/product-modal.blade.php`
- `src/resources/js/admin-dashboard.js` or the existing modal state owner
- `src/tests/Feature/AdminDashboardProductManagementTest.php` and/or existing Product form tests
- focused Admin browser assertions in `src/tests/Browser/admin-dashboard-responsive.spec.js`

### Implementation steps

1. Add normalized nested inputs for up to five tier rows: minimum quantity, IDR amount, conditional USDT amount.
2. Preserve submitted rows and reopen the same Product modal on validation failure.
3. Validate thresholds 2–20, uniqueness/order, positive prices, strict price descent from base, and complete USDT pairing.
4. Create/update Product fields and replace the entire tier schedule inside one transaction while holding the Product lock.
5. Prevent partial replacement if any row fails.
6. Include bounded before/after tier schedules in the existing Product audit entry.
7. Expose ordered rows through Admin dashboard state/edit payloads.
8. Add accessible dynamic rows with add/remove controls, explicit labels, field errors, mobile-safe layout, and no new frontend framework.
9. Removing every row disables tiers cleanly.
10. Do not emit Product price-change or Admin Broadcast events solely because tiers changed.

### Acceptance

- Product can be created/updated with zero to five valid tiers.
- Invalid duplicate, out-of-range, non-decreasing, or incomplete USDT schedule rejects the whole mutation.
- Validation preserves Product fields and all tier rows.
- Editing base USDT and tiers cannot leave an incomplete currency schedule.
- Audit records old/new schedules without secrets.
- Keyboard, touch, and narrow mobile Product modal behavior remain usable.

### Verification

Run focused Admin Product management feature tests and the specific Product-modal browser cases. Do not run formatter, linter, complete browser suites, or the full PHP suite.

### Non-goals

No checkout integration, customer presentation, campaign broadcast, separate tier CRUD page, ENV settings, or price-change notification redesign.

## T3 — Apply one locked pricing snapshot to every checkout rail

### Goal

Make tier pricing authoritative for Orders and all payment rails while preserving checkout idempotency, lock order, provider behavior, and immutable Order history.

### Primary files

- `src/app/Services/OrderCheckoutService.php`
- `src/app/Services/Checkout/CheckoutPaymentPreparationContext.php`
- new immutable checkout pricing snapshot/collection under `src/app/Services/Checkout/` or pricing namespace
- `src/app/Services/Checkout/ManualQrisCheckoutPaymentPreparation.php`
- `src/app/Services/Checkout/KlikQrisCheckoutPaymentPreparation.php`
- `src/app/Services/Checkout/SakurupiahCheckoutPaymentPreparation.php`
- `src/app/Services/Checkout/StoreBalanceCheckoutPaymentPreparation.php`
- `src/app/Services/Checkout/BinanceCheckoutPaymentPreparation.php`
- `src/app/Services/Checkout/BscCheckoutPaymentPreparation.php`
- `src/app/Models/OrderItem.php`
- checkout/payment/refund/receipt tests already covering every rail
- `src/tests/Feature/OrderCheckoutConcurrencyTest.php` or existing concurrency gate coverage

### Implementation steps

1. After current Product locks, load each Product's ordered tiers and quote each Cart Item exactly once.
2. Build an immutable snapshot with per-item quotes, IDR total, and optional complete USDT total.
3. Add that snapshot to `CheckoutPaymentPreparationContext` without weakening its transaction-local/no-external-I/O contract.
4. Replace direct Product multiplication in every payment adapter with snapshot totals.
5. Use the same per-item quote for `Order.total_amount`, Store Balance amount validation/debit, and `OrderItem` effective unit/subtotal fields.
6. Persist `quantity_price_tier_minimum` or null on each Order Item.
7. Preserve current Order/payment currency semantics, provider fees, KlikQRIS final totals, Binance unique-amount behavior, BSC token-unit snapshots, and Pakasir historical compatibility.
8. Preserve Cart → Store Balance account → Cart Item/Product lock order and current checkout retry/idempotency behavior.
9. Prove Product/tier edits cannot interleave a partial schedule with checkout.
10. Remove every checkout-owned base-price arithmetic path after cutover.

### Acceptance

- Every supported IDR rail uses the same tier IDR total.
- Binance Pay and BSC use the same tier USDT total.
- Mixed Cart applies each Product's tier independently.
- Order Item snapshots identify the applied threshold and retain immutable effective amounts.
- Editing/deleting tiers after checkout cannot change an existing Order, Payment, receipt, refund, or invoice.
- Store Balance insufficient-funds and refund behavior use the tier-priced Order amount.
- Duplicate direct checkout still creates one Order/Payment/reservation/economic effect.

### Verification

Run focused checkout tests for Manual QRIS, KlikQRIS, Sakurupiah, Store Balance, Binance Pay, BSC, mixed Cart, duplicate direct taps, Order snapshots, receipts, and refunds. Run `composer test:concurrency-gate`. Do not run formatter, linter, browser suites, or the complete test suite.

### Non-goals

No customer UI/copy, Admin form, notification copy, provider selection, provider fallback, verification redesign, or Order state-machine change.

## T4 — Add Store Bot tier UX and compact payment copy

### Goal

Make Store Bot Product Detail, Cart previews, and payment labels show the same tier quote while keeping the flow compact, localized, stale-safe, and direct.

### Primary files

- `src/app/Services/Telegram/TelegramCatalogIntents.php`
- `src/app/Services/Telegram/TelegramCartIntents.php`
- `src/app/Services/Telegram/Callbacks/TelegramCartCallbackHandler.php`
- `src/app/Services/Telegram/Callbacks/TelegramCheckoutCallbackHandler.php`
- `src/app/Services/Telegram/TelegramIntentSession.php` only if state shape genuinely changes
- `src/app/Services/Telegram/TelegramLocale.php`
- `src/lang/id.json`
- `src/lang/en.json`
- `src/lang/ms.json`
- focused catalog/cart/direct-checkout tests under `src/tests/Feature/Telegram/`

### Implementation steps

1. Inject/use canonical pricing quotes for Product Detail, Cart-add confirmation, Cart summary, and payment-choice amount previews.
2. Render base unit price, currently reachable tier schedule, selected effective unit amount/subtotal, applied-tier state, next-tier quantity, projected total, and base-relative savings.
3. Add one next-reachable-tier action using Product ID/current opaque token; never trust callback amounts.
4. Revalidate current session, live Product, live stock, current tiers, and Checkout Maintenance before changing quantity or paying.
5. Keep custom quantity and plus/minus boundaries; rotate/re-render stale controls through existing token protocol.
6. Use effective IDR total for QRIS and Store Balance labels and effective USDT total for Binance labels.
7. Shorten Store Balance labels to localized `Saldo/Balance/Baki · Rp…` and remove the Product Detail Store Balance snapshot read.
8. Compact QRIS Cross-Border copy to four localized lines while preserving provider/application/merchant availability qualification.
9. Keep Product photography/caption limits and canonical navigation-message behavior intact.
10. Update callback and localization tests in all supported locales; remove obsolete balance placeholder expectations and any retired tier callback grammar.

### Acceptance

- Product Detail shows only tiers currently reachable within live stock.
- At threshold boundaries, displayed unit price/subtotal/payment labels match checkout.
- Next-tier prompt and jump disappear when unreachable or no later tier exists.
- Stale/tampered callbacks create no Cart, Order, Payment, reservation, or outbound effect.
- Mixed Telegram Cart preview applies tiers per Product.
- Store Balance button is compact and contains no Customer balance value.
- QRIS Cross-Border copy remains accurate and materially shorter in Indonesian, English, and Malay.
- Existing direct checkout remains category → Product → payment instruction with no new generic confirmation screen.

### Verification

Run focused Store Bot catalog, quantity, Cart, checkout callback, Store Balance, localization, caption-limit, and stale-control tests. Smoke the actual Telegram rendering path through the repository's safe fake transport and inspect narrow button labels; do not contact production Telegram. Do not run formatter, linter, browser suites, or the full suite.

### Non-goals

No Admin UI, Web Storefront, notification/Sales Proof copy, new payment rail, current-balance display relocation, or Telegram theme/color guarantee.

## T5 — Present tier pricing consistently in Web Storefront

### Goal

Show honest reachable tier offers and effective Cart/checkout totals on the Web Storefront without trusting client-side amounts or changing purchase ownership.

### Primary files

- `src/app/Storefront/StorefrontProductPresentation.php`
- `src/app/Storefront/StorefrontCatalogProductPresentation.php` only if compact surfaces need a safe tier teaser
- `src/app/Storefront/StorefrontProductPresenter.php`
- `src/app/Storefront/StorefrontCartItemPresentation.php`
- `src/app/Storefront/StorefrontCartPageBuilder.php`
- `src/app/Storefront/StorefrontGuestCart.php` cache/fingerprint inputs
- `src/resources/views/storefront/product.blade.php`
- `src/resources/views/storefront/family.blade.php`
- `src/resources/views/storefront/shop-cart.blade.php`
- `src/resources/views/storefront/shop-checkout.blade.php`
- `src/resources/js/storefront.js` for estimate-only progressive enhancement
- focused Storefront Product/Cart/checkout tests and customer browser cases

### Implementation steps

1. Extend safe Product presentation with bounded reachable tiers; never expose private inventory/credential data.
2. Show the lowest reachable “Harga grosir mulai …” teaser and a bounded schedule on Product detail.
3. Use canonical quote effective unit/subtotal in Cart and checkout-review builders.
4. Compute mixed-Cart IDR/USDT estimates from per-item quotes; USDT remains unavailable unless every item has a complete USDT quote.
5. Include tier schedule identity/updated state in guest-Cart/cache fingerprints or invalidate through the existing Product cache protocol.
6. Add safe tier data attributes only for instant quantity estimate updates. Server submission remains authoritative and ignores client prices.
7. Preserve quantity cap 20, stock conflicts, unavailable/waitlist behavior, direct Product and Cart checkout, no-JavaScript forms, safe return URLs, and sticky-action geometry.
8. Keep Product cards/SEO offer price at the honest base single-unit amount; do not add fake crossed-out prices or percentages.

### Acceptance

- Product detail advertises only a tier reachable by current stock.
- Cart quantity changes return effective unit/subtotal values from the server.
- direct and Cart checkout review totals match final locked checkout for unchanged stock/pricing.
- stale tier edits invalidate/recompute presentation rather than serving old totals.
- client price/tier manipulation cannot affect Order or Payment amounts.
- no-JavaScript checkout remains usable; sticky controls do not overlap tier content.
- current max quantity 20 and all existing payment availability rules remain.

### Verification

Run focused Storefront Product presentation, Cart, direct checkout, Cart checkout, response-cache/fingerprint, and payment-option tests. Run the targeted customer browser cases at mobile and desktop widths plus no-JavaScript. Do not run formatter, linter, complete browser suites, or the full PHP suite.

### Non-goals

No quantity-cap increase, checkout architecture change, new SEO aggregate-offer model, catalog-wide discount badge, new frontend framework, or provider behavior change.

## T6 — Add contextual tier merchandising to notifications and Sales Proof

### Goal

Promote tier pricing only when the message has a truthful event-specific reason: a tier becomes purchasable or a real paid Order used it.

### Primary files

- `src/app/Services/Telegram/TelegramProductNotificationService.php`
- `src/app/Services/Telegram/StockNotificationDeliveryPipeline.php` only where materialization contract coverage is needed
- `src/app/Services/PaidInvoice/PaidInvoiceOrderPresentationAdapter.php`
- `src/app/Services/PaidInvoice/PaidInvoicePresentation.php`
- `src/app/Services/Telegram/SalesProofPostFormatter.php`
- `src/app/Services/Telegram/FakeSalesProofPostPublisher.php` or synthetic presentation tests only to prove no false claim
- `src/lang/id.json`
- `src/lang/en.json`
- `src/lang/ms.json`
- focused Product notification, stock outbound, paid-invoice, and Sales Proof tests

### Implementation steps

1. Render one tier line in Product-created/available customer messaging only when current stock reaches a configured threshold.
2. For restock, detect the lowest threshold satisfying `available_before < minimum <= available_after`; add one line only when such a threshold exists.
3. Keep Store Channel high-frequency feed and Customer DM eligibility unchanged.
4. Materialize recipient text once; retries/recovery reuse snapshotted copy after Product/tier changes.
5. Carry `quantity_price_tier_minimum` from immutable Order Items into public-safe paid-invoice presentation.
6. Add one factual English Sales Proof line only when a real Order Item snapshot used tier pricing.
7. Keep base-priced Orders, legacy Orders, top-up proof, and Fake Sales Proof free of bulk claims.
8. Do not calculate historical savings from current Product prices and do not add a generic CTA.
9. Preserve Sales Proof media-caption bounds, successful-order numbering, low-stock ownership, public masking, and outbound-effect delivery state.

### Acceptance

- Product/first-availability notification promotes only a currently purchasable tier.
- Restock crossing a tier adds one line; ordinary stock addition above an already reachable tier does not repeat it.
- crossing multiple tiers promotes only the lowest newly reachable threshold.
- persisted notification recipients retain original rendered text through retry/recovery.
- real tier-priced Sales Proof says bulk pricing was applied; base/legacy/fake/top-up proof does not.
- no historical claim depends on mutable Product/tier state.
- no tier edit triggers an automatic customer/channel campaign broadcast.

### Verification

Run focused Product notification, stock notification materialization/recovery, paid-invoice presentation, real/fake/top-up Sales Proof, caption-bound, and outbound-effect tests. Do not run formatter, linter, browser suites, or the full suite.

### Non-goals

No Admin Broadcast automation, price-change notification redesign, Product creation with initial stock, Sales Proof CTA, fake bulk Order, or outbound state-machine change.

## V1 — Verify the complete quantity-pricing specification

### Goal

Audit the integrated implementation against every mandatory criterion in the large specification. This ticket verifies only; it does not silently fix gaps.

### Target

- A concrete implementation commit/PR containing completed T1–T6 work.
- The large specification, this plan, parent issue, child evidence, `CONTEXT.md`, relevant ADRs, and `docs/agents/codebase.md`.

### Audit matrix

1. schema, relationship, constraints, and legacy compatibility;
2. Admin validation, atomic replacement, audit, responsive accessibility;
3. pricing-module thresholds, currencies, overflow, and fail-closed behavior;
4. Product-lock serialization and immutable Order Item snapshots;
5. every IDR/USDT payment rail, fees/final totals, Store Balance debit/refund, duplicate taps, and concurrency gate;
6. Store Bot Product Detail, next-tier control, Cart/payment labels, compact Store Balance/QRIS copy, localization, stale controls, and caption bounds;
7. Web Product/Cart/checkout, cache invalidation, client tampering, no-JavaScript, responsive geometry, and quantity cap;
8. Product/restock recipient snapshots and real/base/fake/top-up Sales Proof behavior;
9. no ENV pricing, no automatic tier campaign, no provider/Order/Inventory ownership drift;
10. documentation and release readiness.

### Protocol

1. Record target branch, commit, merge base, and dependency status.
2. Read actual changes rather than trusting ticket comments.
3. Map every acceptance criterion to source and executable evidence.
4. Run focused PHP suites, concurrency gate, production asset build, targeted Admin/Storefront browser flows, and safe fake-Telegram smoke flows.
5. Use PASS, PARTIAL, FAIL, or BLOCKED for every criterion.
6. Create a separate ready-for-agent follow-up issue for every implementation gap and mark the parent NO-GO while any release blocker remains.
7. Update `docs/agents/codebase.md` only for behavior actually observed.
8. Do not deploy or mutate production.

### Acceptance

- Every specification criterion has reproducible evidence.
- Threshold boundaries and mixed Cart totals match across preview, locked checkout, Order Item, Payment, receipt, invoice, refund, and Sales Proof.
- All supported rails and locales are covered.
- Admin and Storefront browser evidence covers narrow mobile and desktop plus keyboard/no-JavaScript where relevant.
- Native dependency graph is complete.
- Verdict is explicit GO or NO-GO.

### Verification

This ticket owns the broad final matrix. It may run formatter/linter/build/full focused suites only after all implementation tickets are complete. It must not dispatch production deployment.

### Non-goals

No implementation fixes inside the audit ticket, no production mutation, no deployment, and no undocumented acceptance of gaps.

## Final release gate

- [ ] T1–T6 are closed with focused evidence.
- [ ] V1 reports GO.
- [ ] `CONTEXT.md` and `docs/agents/codebase.md` match verified behavior.
- [ ] No Product/tier presentation ENV was introduced.
- [ ] No unresolved pricing divergence, stale cache, concurrency, provider, refund, or public-claim gap remains.
- [ ] Release occurs only from an approved green `main` commit through `.github/workflows/deploy-production.yml`; this planning pass does not deploy.
