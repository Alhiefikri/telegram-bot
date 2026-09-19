# Quantity Price Tiers and Contextual Merchandising

## Status

- **Status:** Approved for implementation planning.
- **Planning date:** 2026-09-19.
- **Implementation branch:** `feat/quantity-price-tiers` or a descendant ticket branch.
- **Baseline:** current Product, Cart, Order, checkout-payment assembly, Store Bot direct-purchase, Web Storefront, Store Channel, and Sales Proof Post contracts.
- **Planning-pass output:** this large specification, its implementation plan, and a ready-for-agent GitHub ticket set. No product-code implementation is part of this pass.
- **Parent issue:** [#492 — Product Quantity Price Tiers and contextual merchandising](https://github.com/Alhiefikri/telegram-bot/issues/492).
- **Ready-for-agent children:** #493 (T1), #494 (T2), #495 (T3), #496 (T4), #497 (T5), #498 (T6), and #499 (V1).

## 1. Executive decision

Add Product-owned **Quantity Price Tiers** and apply them consistently to every future checkout surface and payment rail.

A Quantity Price Tier replaces the Product's base per-unit price when one Cart Item reaches the configured minimum quantity. The highest configured minimum not greater than the requested quantity wins. The rule is per Product and per Cart Item; quantities from different Products never combine.

The implementation must make pricing server-authoritative and immutable once an Order is created:

1. Product retains its current base IDR price and optional base USDT price.
2. Admin may configure zero to five ordered Quantity Price Tiers for one Product.
3. Each tier has one minimum quantity, one IDR unit amount, and—when the Product supports USDT—one required USDT unit amount.
4. One deep pricing module resolves the effective unit amounts, subtotals, applied tier, and next reachable tier. Callers must not repeat threshold-selection or subtotal arithmetic.
5. Checkout computes one locked pricing snapshot and gives that same snapshot to Order creation and every payment preparation adapter.
6. Order Item snapshots retain the effective amounts and the applied tier minimum. Editing Product prices or tiers later never rewrites an existing Order.
7. Store Bot and Web Storefront present the same effective pricing, while provider fees and final provider totals retain their existing semantics.
8. Customer messaging promotes bulk pricing only in context: Product detail, quantity selection, first/relevant stock availability, and real bulk Sales Proof Posts.
9. Generic Sales Proof Posts remain proof-first. They do not become advertisements.
10. Store Balance payment labels become compact and QRIS Cross-Border copy becomes shorter without weakening its provider/merchant availability disclaimer.

Public Bahasa Indonesia uses **Harga grosir**. Internal/domain documentation uses **Quantity Price Tier**. This is pricing, not a coupon, voucher, percentage discount, customer segment, or Product variant.

## 2. Problem and evidence

Pricing is currently duplicated across the repository:

- `OrderCheckoutService` calculates the IDR Order total from `Product::price_amount`.
- Manual QRIS, KlikQRIS, Sakurupiah, Store Balance, Binance Pay, and BSC preparation code independently multiply current Product amounts by Cart Item quantity.
- `OrderItem` snapshots base Product amounts, not an explicitly resolved pricing rule.
- `TelegramCatalogIntents`, `TelegramCartIntents`, Telegram checkout keyboards, and `StorefrontCartPageBuilder` independently calculate presentation subtotals.
- Product-created/restock notifications show only base unit prices.
- Sales Proof Post formatting can show immutable Order Item amounts but currently has no immutable fact indicating that a Quantity Price Tier was applied.

Adding tier logic independently to those callers would create divergent totals. A customer could see one amount in Product Detail, another in Cart, and a third in the provider instruction. That is unacceptable for QRIS, Store Balance, Binance Pay, BSC verification, refunds, receipts, and public proof.

The current code already has useful seams:

- Product locks and Cart Item validation occur inside `OrderCheckoutService`.
- `CheckoutPaymentPreparationContext` feeds all payment adapters.
- `OrderItem` stores immutable IDR and optional USDT unit/subtotal snapshots.
- `TelegramIntentSession` owns tokenized quantity state.
- `StorefrontCartPageBuilder` owns Web Cart/checkout presentation.
- stock notification recipients persist rendered text before delivery.
- `PaidInvoicePresentation` is the public-safe immutable input to invoice and Sales Proof rendering.

The design deepens those seams rather than adding presentation-specific price rules.

## 3. Scope

### 3.1 Goals

- Persist zero to five Quantity Price Tiers per Product in the database.
- Manage tiers through the existing Admin Dashboard Product create/edit form.
- Validate monotonic quantity and price schedules atomically.
- Resolve IDR and USDT unit prices through one canonical pricing module.
- Apply tier pricing to Store Balance, QRIS, Sakurupiah, Binance Pay, and BSC checkout.
- Preserve immutable Order, Payment, refund, receipt, invoice, and Sales Proof economics.
- Show reachable tier offers, the applied tier, and the next reachable tier in Store Bot Product Detail.
- Add one safe “jump to next tier” quantity action in Store Bot.
- Show correct effective prices in Telegram Cart/payment choices and Web Storefront Cart/checkout review.
- Promote tiers in new-Product/restock messages only when the offer can currently be purchased.
- Mark a real Sales Proof Post when its immutable Order Item snapshot used tier pricing.
- Compact Store Balance payment buttons and QRIS Cross-Border copy.
- Keep all customer-visible Store Bot copy localized in Indonesian, English, and Malay.

### 3.2 Non-goals

- No cart-wide, family-wide, customer-segment, loyalty, voucher, coupon, percentage, flash-sale, or campaign pricing.
- No stacking quantities across different Products.
- No tier-specific stock pool or credential pool.
- No change to Product identity, Product Family identity, Cart identity, provider selection, payment verification, fulfillment, or refund ownership.
- No automatic currency conversion. IDR and USDT tier amounts are Admin-authored catalog values.
- No new ENV/config values for pricing or presentation.
- No automatic price-tier change broadcast. Admin Broadcast remains the deliberate tool for a standalone campaign announcement.
- No generic bulk advertisement on every Sales Proof Post.
- No fake bulk claim in Fake Sales Proof.
- No new payment rail or provider fallback.
- No change to provider fees, provider-authoritative final totals, payment deadlines, or late-payment behavior.
- No increase to the Web Storefront's existing maximum purchase quantity of 20 in this feature. Tier minimums are therefore bounded to 2–20.

## 4. Domain language and invariants

### 4.1 Quantity Price Tier

A **Quantity Price Tier** is a Product-owned pricing rule that becomes applicable when one Cart Item reaches a configured minimum quantity. The applicable tier with the greatest minimum quantity determines the per-unit catalog amount for that Cart Item.

Avoid these meanings:

- “discount” when the implementation means the authoritative unit price;
- “wholesale Product” or “bulk Product” as a new Product identity;
- “tier” as a customer membership level;
- “bulk” as the existing stock-input format.

### 4.2 Selection rule

For Product base price `P0` and tiers `(q1, P1) ... (qn, Pn)` ordered by minimum quantity:

```text
applicable tier = max(qi where requested quantity >= qi)
effective unit price = applicable tier price, otherwise Product base price
subtotal = effective unit price × requested quantity
```

Examples:

```text
Base: Rp25.000
10+: Rp23.000
20+: Rp21.000

quantity 1–9   → Rp25.000/unit
quantity 10–19 → Rp23.000/unit
quantity 20+   → Rp21.000/unit
```

The threshold comparison uses the quantity of one Cart Item. Two Cart Items for different Products never combine. Existing Cart ownership already normalizes one row per Product.

### 4.3 Schedule invariants

For one Product:

- zero to five tiers;
- minimum quantity is a whole number from 2 through 20;
- minimum quantity is unique and strictly increasing after normalization;
- IDR tier amount is a positive whole-IDR integer;
- each IDR tier amount is strictly lower than the preceding applicable amount, beginning with the Product base price;
- if the Product has a base USDT amount, every tier has a positive USDT micro-unit amount and the sequence is strictly decreasing;
- if the Product has no base USDT amount, every tier USDT amount is null;
- a Product with a non-positive base amount cannot receive tiers for that currency;
- gaps are valid; 5+ and 20+ do not require a 10+ tier;
- the highest configured threshold continues to apply above that threshold, subject to live stock and existing purchase limits.

The Admin request submits the complete schedule. Create/update validates and replaces it atomically while holding the Product lock. Partial schedules are never persisted.

### 4.4 Mutability and snapshots

Product tiers are mutable catalog policy. Cart and Product Detail views always use current tiers. An Order is immutable commercial history:

- checkout snapshots the effective IDR unit amount and subtotal into `OrderItem`;
- USDT checkout snapshots the effective USDT unit amount and subtotal;
- checkout snapshots the applied tier minimum, or null when base pricing applies;
- later Product or tier edits do not alter pending, paid, expired, cancelled, refunded, or historical Orders;
- no historical presentation joins back to a mutable tier row to decide whether tier pricing was used.

## 5. Persistence contract

Add a table with a clear Product-owned relationship. Recommended shape:

```text
product_quantity_price_tiers
- id
- product_id                 FK products, cascade on hard delete
- minimum_quantity          unsigned integer
- price_amount              unsigned bigint, IDR whole units
- usdt_price_amount         unsigned bigint nullable, USDT micro-units
- created_at
- updated_at

unique(product_id, minimum_quantity)
index(product_id, minimum_quantity)
```

Add an immutable snapshot column to Order Items:

```text
order_items.quantity_price_tier_minimum nullable unsigned integer
```

The snapshot is deliberately not a foreign key. Deleting or editing a tier must not invalidate historical Orders.

Database constraints should enforce facts that are local to one row where PostgreSQL and SQLite support them consistently:

- `minimum_quantity >= 2`;
- amounts are positive;
- unique Product/minimum quantity.

Cross-row monotonic ordering belongs to the pricing module/Admin mutation transaction because a row-level check cannot compare the preceding tier safely.

Use integer arithmetic only. Reject subtotal overflow before persistence; never cast through float.

## 6. Canonical pricing module

Create one deep module at the Product pricing seam. Suggested names:

- `ProductQuantityPricing` — module;
- `ProductPriceQuote` — immutable quote;
- `ProductQuantityPriceTier` — persistence model.

The exact class names may follow repository naming, but the interface must remain small:

```php
final class ProductQuantityPricing
{
    public function quote(Product $product, int $quantity): ProductPriceQuote;
}
```

`ProductPriceQuote` carries enough immutable data for checkout and presentation without exposing selection logic:

```text
product ID
quantity
base IDR unit amount
effective IDR unit amount
IDR subtotal
base USDT unit amount or null
effective USDT unit amount or null
USDT subtotal or null
applied tier minimum or null
next configured tier or null
ordered configured tiers
```

Presentation callers may filter `ordered configured tiers` against live available stock. The module determines tier order, application, next-tier identity, currency completeness, and overflow. Callers format text only.

No checkout adapter, Telegram adapter, Storefront builder, Blade template, JavaScript file, notification formatter, or Sales Proof formatter may independently choose a tier or multiply catalog unit amounts.

The module must fail closed on an invalid persisted schedule. It must not silently fall back to base pricing if duplicate thresholds, non-monotonic amounts, or incomplete USDT tiers are observed. Admin validation prevents new invalid schedules; fail-closed behavior protects checkout from manual/corrupt data.

Do not add a pricing cache in the first implementation. Product/tier reads are bounded to at most five rows, and avoiding stale pricing is more valuable than a second invalidation protocol.

## 7. Authoritative checkout integration

### 7.1 One locked pricing snapshot

`OrderCheckoutService` remains the canonical checkout module. After existing Cart, Cart Item, Product, and provider-setting locks are established, it must:

1. load each locked Product's tiers in deterministic threshold order;
2. quote each Cart Item exactly once through `ProductQuantityPricing`;
3. build an immutable checkout pricing snapshot keyed by Product/Cart Item;
4. pass that snapshot through `CheckoutPaymentPreparationContext`;
5. use the same snapshot for `Order.total_amount`, Payment plan validation, Store Balance debit, and `OrderItem` persistence.

The checkout pricing snapshot should expose aggregated IDR total and optional aggregated USDT total plus per-item quotes. All items must have a USDT quote before a USDT rail can be selected.

### 7.2 Payment adapters

Remove direct `Product::price_amount * quantity` and `Product::usdt_price_amount * quantity` arithmetic from payment preparation adapters. Each adapter consumes the authoritative pricing snapshot:

- Manual QRIS, KlikQRIS, Sakurupiah, and Store Balance use the IDR total;
- Binance Pay and BSC use the USDT total;
- existing provider fee/final-total behavior remains downstream;
- Pakasir remains historical compatibility only according to ADR-0006 and is not reintroduced as a selectable rail.

`CheckoutPaymentPlan` and the accepted checkout-payment assembly architecture remain authoritative. Pricing does not become a provider concern.

### 7.3 Order Item snapshots

For each Cart Item, persist:

- effective IDR unit amount;
- effective IDR subtotal;
- effective USDT unit/subtotal when the Payment uses a USDT rail, following the existing snapshot convention;
- applied tier minimum or null.

Do not persist a mutable tier foreign key as the source of historical truth.

### 7.4 Concurrency and idempotency

Admin tier replacement must lock the Product before replacing rows. Checkout already locks Products; pricing reads occur while that lock is held. Therefore an Admin edit and checkout serialize on the same Product lock: checkout sees either the complete old schedule or complete new schedule, never a partial mix.

Preserve:

- ADR-0002 direct-checkout idempotency;
- one Order/Payment/reservation for duplicate taps;
- checkout-key reuse;
- provider preflight/prepare ordering;
- Inventory Ledger ownership;
- Store Balance account lock order and atomic debit;
- OrderLifecycle paid/cancel/expiry/refund ownership;
- Telegram Outbound Effect ownership.

## 8. Admin Dashboard contract

Add a “Harga grosir” section to the existing Product create/edit modal. It is optional and database-backed.

Each row contains:

```text
Minimum jumlah | Harga/unit IDR | Harga/unit USDT (conditional) | Remove
```

Behavior:

- start with no rows;
- “Tambah tier” adds a row up to five;
- edit loads existing rows ordered by minimum quantity;
- removing all rows disables Quantity Price Tiers for the Product;
- USDT tier inputs are shown/required when the Product base USDT price is present;
- changing base USDT from present to absent clears/rejects submitted tier USDT values consistently;
- validation errors preserve all submitted tier rows and return to the same Product modal;
- helper copy explains that the highest reached minimum wins and prices are per unit;
- preview text uses the existing Admin money formatting;
- touch targets, keyboard operation, error association, and narrow mobile modal behavior match current Admin conventions.

Create/update persists Product fields and the complete tier schedule in one transaction and one Product lock. Audit data records before/after schedules in bounded structured form without secrets.

Tier edits do not automatically broadcast to customers or the Store Channel. Admin may intentionally use Admin Broadcast for a campaign announcement.

## 9. Store Bot experience

### 9.1 Product Detail hierarchy

Product Detail keeps the existing tokenized quantity state and direct-payment path. Render, in order:

```text
Product identity
Base unit price
Reachable Quantity Price Tiers
Live stock
Selected quantity
Effective unit price when a tier applies
Subtotal
Next-tier prompt when reachable
Description
Compact QRIS Cross-Border block
```

Only tiers whose minimum is not greater than current available stock are promoted as currently purchasable. Configured but unreachable tiers remain an Admin/catalog fact and must not be advertised as immediately available.

For a Product with base Rp25.000, 10+ Rp23.000, stock 24, and selected quantity 7:

```text
Harga: Rp25.000/unit
🏷 Grosir 10+: Rp23.000/unit
Stok tersedia: 24
Jumlah: 7
Subtotal: Rp175.000

Tambah 3 unit lagi untuk harga grosir.
Total 10 unit: Rp230.000
Hemat Rp20.000 dari harga normal.
```

When quantity 10 is selected:

```text
🏷 Harga grosir 10+ aktif
Harga/unit: Rp23.000
Subtotal: Rp230.000
Hemat Rp20.000 dari harga normal.
```

Savings in Product Detail are computed against the same quantity at the Product's base unit amount. They are presentational current-catalog facts, not historical Order facts.

### 9.2 Quantity keyboard

Keep the existing row:

```text
[ − ] [ current ] [ + ]
```

When a next configured tier is reachable within live stock, add one row:

```text
[ 🏷 Pilih 10 ] [ ✏️ Jumlah lain ]
```

If two labels cannot remain readable in a supported locale/client width, use separate full-width rows. Telegram semantics and readability take priority over a fixed two-column shape.

The tier callback carries Product ID and the current opaque interaction token. It must not trust a client-supplied price. Prefer resolving “next reachable tier” from current session quantity, live Product tiers, and live stock. Every successful quantity change rotates/revalidates the prompt according to the existing stale-control contract.

Boundary behavior:

- no decrement below one;
- no jump above live available stock;
- no tier button when every later tier is unreachable;
- stale/tampered tier callbacks create no Cart, Order, Payment, or reservation;
- custom quantity continues to accept valid whole quantities within live stock;
- Checkout Maintenance continues to block new checkout while allowing catalog navigation.

### 9.3 Payment buttons

Every payment label uses the authoritative current quote:

```text
💳 QRIS · Rp230.000
💰 Saldo · Rp230.000
💎 USDT · 14,20 USDT
```

The Store Balance button no longer embeds the Customer's available balance. Remove the presentation-only Store Balance snapshot read from Product Detail. Store Balance checkout still checks and debits the ledger atomically; insufficient funds still produce the existing actionable failure.

### 9.4 Compact QRIS Cross-Border copy

Keep the feature and disclaimer, but remove repeated prose. Indonesian target:

```text
🌏 QRIS Antarnegara
🇮🇩 Indonesia · 🇹🇭 Thailand · 🇲🇾 Malaysia
🇸🇬 Singapura · 🇯🇵 Jepang · 🇰🇷 Korea Selatan · 🇨🇳 Tiongkok
📱 Tergantung dukungan aplikasi/PJP dan merchant.
```

English and Malay receive equivalent compact localized copy. The wording must continue to state that availability depends on the payment provider/application and participating merchant. It must never claim universal country coverage.

### 9.5 Telegram Cart and payment choice

Cart-add confirmation, Cart summary, and payment-choice buttons must quote each current Cart Item through the pricing module. A mixed Cart applies each Product's tier independently. The final checkout still recalculates under locks, so presentation is informative rather than authoritative.

## 10. Web Storefront experience

The Web Storefront must not advertise one price and charge another.

Required behavior:

- Product detail and family-option presentation may show the lowest currently reachable tier as “Harga grosir mulai …”.
- Product detail may show the complete reachable schedule, bounded to the maximum five rows.
- Cart Item unit price and subtotal use the effective quote for that Product quantity.
- Cart update reloads the authoritative presentation after quantity changes.
- checkout review shows effective unit prices, subtotals, and Order total for the selected quantity;
- USDT estimates use effective USDT tier amounts only when every item supports USDT;
- direct Product checkout quantity changes update the visible estimate from safe tier data, but server checkout always recomputes it;
- client-submitted price/tier fields are never trusted;
- unavailable/private Product and stock-conflict behavior remains unchanged;
- current maximum Web purchase quantity remains 20;
- no-JavaScript form submission remains usable;
- structured-data/SEO base offer price remains the Product's base single-unit price unless a separate verified aggregate-offer design is approved.

Product cards and compact catalog rails may continue to show base single-unit price. They must not show a fake crossed-out price or percentage discount.

## 11. Contextual merchandising

### 11.1 Product-created and first availability messages

A tier line is eligible only when:

- the Product is active;
- the Product has available stock;
- at least one tier minimum can be purchased with that stock.

Example:

```text
🏷 Harga grosir mulai 10 unit: Rp23.000/unit
```

If USDT is available and the existing surface already displays both currencies, use one bounded line:

```text
🏷 10+ units: Rp23.000 / 1.42 USDT each
```

The standard Admin Product creation flow currently creates stock zero, so `notifyProductCreated()` normally skips. This feature must not announce an unavailable Product. The first Stock Intake that makes it available remains the effective launch/restock moment and may carry the tier line.

### 11.2 Restock messages

Avoid repeating bulk promotion on every stock adjustment. Add one tier line only when the stock movement makes a configured tier newly reachable:

```text
available_before < tier.minimum_quantity <= available_after
```

If multiple tiers are crossed in one movement, promote the lowest newly reachable threshold; Product Detail owns the full schedule. If no threshold is crossed, retain the existing compact restock message.

Customer DMs continue to follow the existing policy: high-frequency restocks belong to the Store Channel, while DMs occur when availability is restored or the Store Channel is unavailable.

Rendered recipient text remains snapshotted before delivery. Retry/recovery must resend the same text rather than recalculating against later Product changes.

### 11.3 Sales Proof Post

Sales Proof remains proof-first:

- no bulk CTA on a base-priced Order;
- no generic “buy more” advertisement;
- if at least one immutable Order Item snapshot has `quantity_price_tier_minimum`, add one factual English line;
- for one qualifying item, the line may include the threshold: `🏷 Bulk pricing applied · 10+ units`;
- for a multi-item Order, use `🏷 Bulk pricing applied` unless the caption can state thresholds without ambiguity;
- do not calculate historical savings from the current mutable Product price;
- Fake Sales Proof remains quantity one and never claims bulk pricing;
- caption length remains bounded to Telegram's media-caption limit.

## 12. Localization, accessibility, and copy

- Store Bot customer copy follows ADR-0005.
- Store Channel and Sales Proof retain their existing English/public tone.
- Add/update keys in every supported locale (`id`, `en`, `ms`).
- Callback data stays locale-neutral and contains no amount or translated label as authority.
- Money formatting retains whole-IDR and USDT micro-unit precision conventions.
- Admin tier rows have explicit labels, associated validation errors, keyboard-reachable add/remove controls, and touch targets consistent with existing Admin Dashboard behavior.
- Web presentation remains no-JavaScript-capable and does not rely on color alone to communicate the applied tier.

## 13. Failure behavior

- Invalid Admin schedule: reject the entire Product mutation and preserve submitted rows for correction.
- Invalid persisted schedule detected during checkout: fail closed with a generic customer-safe checkout error and report the operational defect; do not silently charge base price.
- Tier disappears or changes while a Product Detail prompt is open: the next interaction reloads current Product/tier data and renders a fresh quote or stale-action response.
- Stock falls below a promoted threshold: payment callback still revalidates live stock and cannot buy unavailable quantity.
- Provider becomes unavailable: preserve existing rail-unavailable behavior; pricing does not choose another provider.
- Duplicate tap/retry: preserve one Order and one economic effect.
- Existing Orders missing the new nullable tier snapshot are interpreted as base/legacy pricing and remain valid.

## 14. Migration and compatibility

- Existing Products begin with zero tiers; behavior and prices are unchanged.
- New tables/columns are additive and nullable where needed.
- No backfill invents tiers or marks old Orders as bulk-priced.
- Existing Order Item unit/subtotal snapshots remain authoritative.
- Existing serialized Telegram callback contracts remain valid until the Store Bot ticket performs a clean callback cutover; do not keep two active tier callback grammars afterward.
- Storefront response-cache fingerprints/invalidation must include tier changes where cached Product or Cart presentation could otherwise become stale.
- The implementation must update `CONTEXT.md` and `docs/agents/codebase.md` only with behavior that is actually implemented and verified.

## 15. Acceptance matrix

### Pricing and persistence

1. A Product without tiers prices exactly as before.
2. At threshold minus one, base price applies; at the threshold, the tier applies.
3. Between thresholds, the greatest reached threshold applies.
4. IDR and USDT resolve from the same threshold schedule.
5. Duplicate/non-monotonic/incomplete schedules cannot be saved.
6. Admin update and checkout cannot observe a partially replaced schedule.
7. Existing Orders remain unchanged after any Product/tier edit.

### Checkout

8. Manual QRIS, KlikQRIS, Sakurupiah, Store Balance, Binance Pay, and BSC consume one locked pricing snapshot.
9. Order, Payment, Order Item, provider instruction, receipt, and refund amounts remain internally consistent.
10. Mixed Cart quantities apply tiers per Product only.
11. Duplicate direct-payment taps create no duplicate Cart, Order, Payment, reservation, ledger entry, or outbound effect.
12. Provider fee/final-total and verification behavior is unchanged.

### Customer surfaces

13. Store Bot shows only currently reachable tiers and the correct next-tier prompt.
14. Tier jump/custom quantity/payment buttons survive stale stock and stale token cases safely.
15. Store Balance and QRIS/USDT buttons show the effective total.
16. Store Balance button is compact and does not expose current balance.
17. QRIS Cross-Border copy is compact but retains the non-universal availability disclaimer.
18. Web Cart and checkout review show the same effective catalog totals as checkout.
19. Web no-JavaScript flow remains valid and quantity remains bounded to 20.

### Merchandising

20. Product/first-availability messaging promotes only a tier reachable by current stock.
21. Restock promotion appears only when the movement crosses a tier threshold.
22. Retry/recovery sends snapshotted notification text.
23. Real tier-priced Orders may add one factual Sales Proof line.
24. Base-priced and Fake Sales Proof Posts never claim bulk pricing.
25. No tier edit sends an automatic campaign broadcast.

## 16. Delivery slices

The implementation plan splits this specification into:

- **T1:** persistence, domain model, and canonical pricing module;
- **T2:** Admin Dashboard tier management;
- **T3:** authoritative checkout pricing and immutable Order snapshots;
- **T4:** Store Bot tier UX plus compact Store Balance/QRIS copy;
- **T5:** Web Storefront tier presentation and quote consistency;
- **T6:** contextual Product/restock/Sales Proof merchandising;
- **V1:** cross-surface verification and documentation gate.

Each child issue is independently actionable after its blockers close. V1 audits the full specification and does not silently fix implementation gaps.
