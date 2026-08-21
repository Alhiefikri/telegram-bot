# Telegram Store Bot Direct Purchase Design

- **Date:** 2026-08-21
- **Status:** Ready for agent
- **Scope:** Simplify Store Bot catalog selection, quantity selection, and direct QRIS/Binance payment without changing checkout, stock, or provider ownership.

## Goal

Make the Store Bot purchase path compact and button-driven:

```text
/start → Product Category → Product List → Product Detail → QRIS/Binance instruction
```

A buyer starts with quantity `1`, changes it with `−`/`+` or one manual input, then taps the intended payment rail directly from the product detail. There is no generic intermediate checkout screen.

The keyboard must be readable and tappable on narrow Telegram mobile clients. QRIS is the primary rail. Binance is present only when it is available for the selected Available Product.

## Non-goals

- Add a buyer note, stored balance, voucher, Refresh action, or generic cart redesign.
- Change Web Storefront behavior.
- Change `PaymentProviderSwitch`, historic Payment interpretation, KlikQRIS recovery, Binance verification, or Order lifecycle semantics.
- Guarantee an RGB color or pixel-perfect rendering across Telegram themes. Telegram clients own final theme rendering. The implementation guarantees clear labels, isolated rows, semantic styles, and manual visual validation in supported clients.

## Current State

`TelegramCatalogIntents` currently:

- renders product summaries followed by one full-width `Choose #n` button per product;
- shows QRIS/Binance buttons on product detail;
- opens a separate quantity prompt after a payment rail is selected;
- offers quantities `1` through `min(stock, 5)` and relies on the selected-product session for numeric text input.

`TelegramCartCallbackHandler` and `TelegramCheckoutCallbackHandler` then mutate the cart and invoke checkout. `OrderCheckoutService` owns Order/Payment creation, reservation, credential allocation, and checkout-key reuse.

This feature changes presentation and interaction state only. It must not move commercial rules into Telegram handlers.

## Target Buyer Experience

### Product list

The message remains the authority for product name, price, availability, and optional USDT price. Buttons select only the corresponding item number.

```text
🛍 Product List

1. Netflix Indonesia Private
   Rp22.000 · 1,5 USDT

2. Netflix Premium
   Rp15.000 · 1 USDT

3. Spotify Premium 2 Bulan
   Rp10.000 · 0,8 USDT

Choose a product:

[ 1 ] [ 2 ] [ 3 ]
[ Back to categories ]
```

Rules:

- Render at most **three numeric buttons per row**.
- Keep category navigation in its own full-width row.
- Do not repeat `Choose #n`, product names, or decorative emojis inside numeric buttons.
- An Unavailable Product remains selectable only if current catalog policy permits it; its button must retain the unavailable semantic style and its detail must not offer payment.

### Product detail

```text
🛍 Netflix Indonesia Private

Price: Rp22.000
Binance: 1,5 USDT
Available stock: 23
Quantity: 1
Subtotal: Rp22.000

[ − ] [ 1 ] [ + ]
[ Enter custom quantity ]
[ Pay with QRIS · Rp22.000 ]
[ Pay with Binance · 1,5 USDT ]
[ Back to products ]
```

Keyboard geometry is part of the interface contract:

1. The quantity-control row has exactly three short buttons: decrement, informational current quantity, increment.
2. The current quantity is non-purchasing. Its callback only acknowledges the press or is otherwise deliberately inert; it must never create a Cart, Order, or Payment.
3. `Enter custom quantity` occupies a full row.
4. QRIS and conditional Binance each occupy separate full rows. Do not place payment rails beside quantity or navigation actions.
5. Back navigation occupies its own final row.
6. QRIS carries the Telegram semantic primary/success style; Binance is neutral/accent; back and informational controls are neutral. Every button remains distinguishable by label and position when color is absent.
7. No empty/whitespace-only label, hidden callback-only action, or adjacent overloaded button is permitted.

The QRIS/Binance label shows the selected **product subtotal**, not a claimed final provider total. The generated Native QRIS instruction remains authoritative for any provider-supplied final total and deadline.

### Custom quantity prompt

The old misleading `Note` naming is not adopted. The explicit action is localized as `Enter custom quantity`.

```text
Enter purchase quantity

Product: Netflix Indonesia Private
Current quantity: 1
Available stock: 23

Send a whole number, for example: 5.

[ Cancel ]
```

- A valid positive whole number within live available stock returns to a newly rendered product detail with recalculated totals.
- Non-numeric, fractional, zero, negative, or over-stock input stays in input mode and receives a localized retry message.
- Cancel returns to product detail and preserves the prior quantity.
- `start`, category/product navigation, timeout, successful direct checkout, product unavailability, and Checkout Maintenance clear or intentionally transition this input state. A later bare number must never apply to a stale product.

## Interaction and Callback Contract

Replace the scalar `TelegramIntentSession` value with an explicit short-lived selection state:

```text
productId
quantity
interactionToken
locale
mode: browsing | awaiting_custom_quantity
previousQuantity (only while awaiting custom input)
```

The state retains the existing 15-minute policy unless a repository session policy requires a different duration.

Callback data must be language-neutral and callback-size-safe. It contains only action, stable product ID, and opaque token/revision, for example:

```text
detail:qty:-:<productId>:<token>
detail:qty:+:<productId>:<token>
detail:qty:manual:<productId>:<token>
detail:pay:qris:<productId>:<token>
detail:pay:binance:<productId>:<token>
detail:back:<productId>:<token>
```

Do not place prices, quantities, product names, or translated labels in callback data. Every callback must verify that the session’s current `productId` and token match before acting.

When the buyer changes quantity, the renderer creates a fresh detail prompt and rotates the interaction token. Old controls only receive localized stale/unavailable feedback; they cannot mutate a Cart, create an Order, reserve stock, create a provider instruction, or send another checkout effect.

The implementation may use Telegram message editing only if it also carries a fresh persisted/validated revision token. Otherwise it sends a new prompt and invalidates the prior token. The selected approach must retain ADR-0002’s prompt-bound checkout idempotency.

## Direct Checkout Rules

- The QRIS and Binance callbacks re-read the Available Product and live available stock before checkout.
- They validate current selection token, selected quantity, Checkout Maintenance, product visibility, and rail availability.
- Binance requires both a product USDT price and enabled Binance Pay.
- QRIS is displayed for an available product according to the active payment-provider configuration.
- After validation, handlers use the existing `CartService` and `OrderCheckoutService` direct-checkout seam. Telegram presentation code does not create Orders or write inventory.
- The existing checkout-intent cache lock and `orders.checkout_key` reuse remain in force. Duplicate taps from one rendered payment prompt reuse one checkout outcome; a deliberately refreshed selection prompt is a new intent.
- `KlikQrisNativeQrisService` remains the only owner of native QRIS create, recovery, persistence, payability, and safe outcomes. It creates no automatic fallback or replacement Payment.
- `BinancePaymentVerification` remains the only owner of Binance verification. No Telegram callback marks a Payment paid.

## Localization

All buyer-facing text is accessed through `TelegramLocale`. Add each new key to every supported locale file (`id`, `en`, and `ms`; include any newly discovered supported locale before merging):

- product list instruction and compact selector labels;
- quantity controls and summary;
- custom-quantity prompt, example, retry, over-stock, cancellation, and stale-control feedback;
- QRIS/Binance payment labels with subtotal;
- navigation labels.

Routing must never inspect a translated string. A locale changes labels only; callbacks, validation, checkout behavior, and idempotency stay identical.

## Ownership and Integration

- Keep callback registration in the existing centralized `TelegramCallbackRouter` pipeline, after privacy and Community Gate checks.
- Evolve `TelegramCatalogIntents` into the owner of catalog/detail copy and keyboard rendering. It must not own checkout decisions.
- Evolve `TelegramIntentSession` into the selection-state owner.
- Update `TelegramMessageRouter` to consume custom numeric input only while `mode = awaiting_custom_quantity`.
- Migrate every old `buy:*` and direct `qty:*` presentation callback/caller/test to the new contract in one cutover. Do not retain a parallel deprecated flow.
- Preserve `CartService`, `OrderCheckoutService`, `CheckoutMaintenance`, `InventoryLedger`, `TelegramCheckoutIntents`, KlikQRIS, Binance, and the Telegram outbound-effect protocol as the existing owners.

## Acceptance Criteria

1. A buyer can reach a QRIS instruction with default quantity 1 in category → product → QRIS, without a payment-selection or generic checkout page.
2. Numeric product buttons render in rows of one to three, have no blank/truncated labels, and never share a row with navigation.
3. Product detail renders isolated quantity, manual-input, payment, and navigation rows. QRIS appears before Binance and Binance is absent when unavailable.
4. The Store Bot does not expose Note, Saldo, Refresh, or an extra checkout CTA in this flow.
5. Plus/minus correctly enforce `1 ≤ quantity ≤ live available stock`; totals and payment-button labels update after every valid quantity change.
6. Manual quantity handles success, invalid retry, out-of-stock retry, cancel, timeout, navigation, and stale input safely.
7. All visible copy is localized, while callbacks work identically across locales.
8. Stale, tampered, or duplicate callbacks create no duplicate Cart, Order, Payment, stock reservation, provider instruction, or Telegram outbound effect.
9. Checkout Maintenance, provider availability, native QRIS safe outcomes, stock revalidation, and existing payment/order lifecycle behavior continue to hold.
10. Automated tests validate payload geometry/style/labels and behavior. Manual QA confirms the layout remains clear and non-overlapping in light/dark Telegram themes on narrow mobile and desktop clients for 1, 2, 3, 6, and 12 products; long localized labels; stock 1; large custom quantity; QRIS-only; and QRIS+Binance.

## Required Verification

- Focused `tests/Feature/Telegram/CartCheckoutTest.php` and related catalog/search/maintenance tests.
- Direct QRIS/Binance idempotency and outbound-effect tests.
- `composer test:concurrency-gate`, because the callback sequence reaches stock reservation and checkout.
- Manual Telegram client walkthrough with recorded screenshots/version details in the future implementation PR.
