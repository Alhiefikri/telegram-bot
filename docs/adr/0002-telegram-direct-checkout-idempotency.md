# Telegram direct checkout idempotency

## Status

Accepted — 2026-07-21

## Decision

Direct purchase callbacks in the Store Bot reuse the existing `orders.checkout_key` mechanism and now derive that key from the customer, chat, prompt message ID, and normalized checkout intent.

For direct quantity prompts, the normalized intent includes the product and payment rail but not the selected quantity, so multiple quantity buttons from the same prompt are treated as one checkout attempt. For checkout payment-choice prompts, the normalized intent does not include the selected payment rail, so the first choice from that prompt wins.

The Telegram callback router also acquires a short cache lock for the checkout intent before it mutates cart state or creates an order. If the same prompt is tapped again while the first attempt is still running, the second callback waits briefly and then reuses the existing order instead of creating another cart, order, payment, stock reservation, or notification burst.

Product Detail separates the stable `quantityToken` for `−`/`+` from the rotating `interactionToken` used by payment and other revision-bound controls. Distinct quantity-tap updates accumulate within one browsing session; duplicate delivery of an update remains deduplicated by the ingress/replay guard. Every quantity transition rotates the payment revision, so an earlier payment button cannot buy the revised quantity. Entering custom input, returning from it, reopening the Product, or clearing/expiring the selection invalidates earlier quantity controls. Existing checkout-key and per-chat serialization rules remain unchanged.

## Consequences

- Repeated delivery of one quantity-tap update is idempotent; distinct taps accumulate. Repeated checkout callbacks still reuse the same checkout attempt.
- A new checkout prompt still creates a fresh intent, so users can order again after a previous transaction.
- Old buttons from a previous prompt no longer create a new order after the user has moved on, which is deliberate.
- The Web Storefront flow is unchanged.
- The Customer API cannot use a Telegram prompt identity, so `POST /api/v1/orders` derives its namespaced `orders.checkout_key` from a durable claim instead; see [ADR-0025](0025-api-order-idempotency-claims.md). Both channels share the same canonical `OrderCheckoutService` and compete safely for money and stock, but their keys never collide (the bot key is a 64-character HMAC hex, the API key starts with `api-`).
