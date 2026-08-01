# Binance Exact-Amount Order ID Design

## Goal

Make Binance Pay checkout charge exactly the catalog price and require the buyer's Binance Order ID for automatic verification before any product is delivered.

## Decisions

- New Binance payments use the exact stored USDT price, including supported decimal precision. The system does not add a unique-amount surcharge.
- When Binance API credentials are configured, the **I've Paid** action always leads to an Order ID prompt. A payment cannot be auto-confirmed from amount alone.
- The buyer may submit at most three syntactically valid Order IDs while the order is active. Invalid-format messages do not consume an attempt.
- The submitted Order ID is persisted with the payment. A replacement ID supersedes older retry work without extending the verification window.
- The original Binance payment deadline remains 20 minutes by default. A valid Order ID submitted before that deadline receives one technical grace window ending three minutes after the original deadline.
- The grace window is only for a not-found or provider-error result. A found mismatch, wrong receiver, wrong currency, stale transaction, or already-used transaction does not extend the original stock deadline.
- The initial verification runs immediately. Not-found and provider-error results are retried through a delayed queue job every 30 seconds until the grace window ends.
- Buyer-facing retry messages are sent only when verification starts and when a final result is available. Background retries do not create chat noise.
- Exact matches must still satisfy incoming receiver, currency, amount, transaction time, and unique transaction-claim checks.
- A mismatch or reused transaction is recorded as sanitized evidence and escalated to the admin. It never auto-delivers.
- If grace ends without confirmation, normal expiry releases stock. The expiry notice asks the buyer to send a screenshot with the invoice for admin review.
- A screenshot submitted after expiry is evidence only. It does not restore the deadline, reserve stock, or auto-deliver. Admin decides the late-payment resolution.
- Buyer expiry notices use persisted claim/recovery state with at-least-once delivery semantics. If Telegram accepts a message but the process dies before the sent timestamp is written, a recovery run may send it again; payment or stock state is unaffected.
- Binance checkout without API credentials retains the existing manual screenshot path because automatic Order ID verification is unavailable.

## Data Model

The `payments` table gains:

- `binance_order_id`: the latest accepted buyer-submitted Order ID.
- `binance_order_id_attempts`: count of accepted syntactically valid IDs, capped at three by the application.
- `binance_order_id_submitted_at`: time of the latest accepted ID.
- `binance_verification_grace_until`: fixed original deadline plus three minutes, set only by the first accepted ID before expiry.

The `orders` table also tracks buyer expiry-notification claim and sent timestamps so a Telegram failure can be retried without duplicate messages.

`provider_reference` remains the provider transaction ID and remains globally unique. `provider_payload` continues to contain only sanitized Binance transaction evidence, never raw API credentials or unsanitized provider data.

The old pending-amount uniqueness index is removed. Existing pending payments retain their already-persisted amount and can be verified against that amount when their buyer submits an Order ID; new payments no longer receive an offset.

The migration refuses to roll back when duplicate pending exact-amount payments exist, because restoring the old amount-only verifier would be unsafe.

## Flow

1. Checkout creates a Binance payment with the exact USDT total.
2. The instruction displays the exact amount, Pay ID, and tells the buyer to tap **I've Paid** after copying the Order ID from Binance.
3. The callback opens an Order ID prompt and does not call the provider.
4. A valid ID is saved atomically while the payment is pending and before the original deadline. The system performs an immediate provider lookup.
5. An exact transaction completes the existing manual order lifecycle, records the provider transaction, and sends the product.
6. A missing transaction schedules delayed retries. A replacement ID increments the attempt count and invalidates jobs carrying the previous attempt number.
7. A found mismatch records evidence and notifies the admin. The order remains pending only until the original deadline.
8. At grace expiry, the pending order expires through the normal lifecycle. The buyer receives late-payment instructions and may submit `/payment INVOICE` with a screenshot.
9. Late screenshots are forwarded to the admin without approval buttons that could invoke a pending-order transition on an already expired order.

## Components

- `OrderCheckoutService` stores exact Binance amounts and snapshots whether API verification is enabled.
- `BinanceAutoVerification` performs all provider matching and transaction claiming by submitted Order ID.
- `VerifyBinanceOrderId` retries a single stored ID through the queue and stops when the payment state, attempt number, or grace window is no longer eligible.
- `TelegramBinanceVerifyIntents` owns prompt, input validation, immediate verification, and buyer messages.
- `ExpirePendingOrders` and `OrderStateMachine` preserve an active Binance grace window before releasing reservations.
- `TelegramOrderExpiredNotifier` explains the screenshot fallback for expired Binance verification.
- `TelegramManualPaymentIntents` accepts late Binance screenshots as evidence without reviving expired orders.
- `ReconcileBinancePayments` is a one-minute safety net that only processes payments with a stored Order ID; it never performs amount-only confirmation.

## Failure And Safety Rules

- API failure is retryable and does not mark a payment failed during grace.
- Invalid ID format is rejected without a provider call or attempt increment.
- More than three valid IDs is rejected without replacing the stored ID.
- A stale delayed job must no-op when its attempt number or stored ID no longer matches.
- Concurrent confirmation is protected by payment/order locks and the unique `provider_reference` constraint.
- Late evidence never changes an expired order back to pending.

## Testing

Feature coverage must prove exact checkout amounts, no amount-only completion, prompt and ID submission, ID attempt limits, retry success, stale-job invalidation, grace-aware expiry, mismatch escalation, reused-transaction protection, API failure behavior, late screenshot forwarding, and concurrent verification safety. Existing manual screenshot and admin transition coverage must remain green.
