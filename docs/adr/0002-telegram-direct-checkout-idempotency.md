# Telegram direct checkout idempotency

## Status

Accepted — 2026-07-21

## Decision

Direct purchase callbacks in the Store Bot reuse the existing `orders.checkout_key` mechanism and now derive that key from the customer, chat, prompt message ID, and normalized checkout intent.

For direct quantity prompts, the normalized intent includes the product and payment rail but not the selected quantity, so multiple quantity buttons from the same prompt are treated as one checkout attempt. For checkout payment-choice prompts, the normalized intent does not include the selected payment rail, so the first choice from that prompt wins.

The Telegram callback router also acquires a short cache lock for the checkout intent before it mutates cart state or creates an order. If the same prompt is tapped again while the first attempt is still running, the second callback waits briefly and then reuses the existing order instead of creating another cart, order, payment, stock reservation, or notification burst.

## Consequences

- Repeated taps on the same quantity or checkout prompt are idempotent.
- A new checkout prompt still creates a fresh intent, so users can order again after a previous transaction.
- Old buttons from a previous prompt no longer create a new order after the user has moved on, which is deliberate.
- The Web Storefront flow is unchanged.
