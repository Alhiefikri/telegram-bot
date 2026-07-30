# Binance Manual Review Design

## Goal

Make Binance Pay checkout easier to enter manually while ensuring an amount mismatch has a safe, explicit admin resolution path rather than leaving reserved stock stuck.

## Scope

- Generate auto-verification amounts with exactly three decimal places.
- Preserve automatic delivery only for an exact, incoming USDT transfer to the configured Binance Pay ID after checkout.
- Capture the detected candidate transaction when verification finds a mismatch.
- Let an authorised dashboard user explicitly approve and deliver a pending manual payment, or reject it and release stock.

## Amount Allocation

When Binance API credentials enable auto-verification, checkout will allocate one of 999 available mill-USDT offsets: `0.001` through `0.999` USDT. The payable value is rounded up to a three-decimal amount before the offset is added, so the value shown to the buyer always has exactly three decimal places.

For example, a product price of `5.000000` USDT may produce `5.714 USDT`. The rounded base never falls below the configured product price. Pending Binance payments continue to have a unique payable amount enforced by the database index. If all 999 values are already allocated, checkout fails rather than assigning an ambiguous amount.

New and updated product USDT prices accept at most three decimal places. Existing six-decimal prices remain readable; the checkout allocation rounds their payable base up to three decimals.

Without Binance API credentials, the manual screenshot flow remains unchanged and uses the product's exact configured price.

## Mismatch Review

The verifier still treats differences in amount, currency, recipient, transaction time, and transaction reuse as non-automatic outcomes. For an incoming candidate that does not match the pending invoice, it stores the sanitized transaction data on the pending payment and notifies the admin.

The order detail page shows the expected USDT amount and, when present, the candidate transfer amount, currency, Binance order ID, transaction ID, and received time. A mismatch never changes the order to paid by itself.

## Admin Resolution

For an order with a pending manual payment, the dashboard exposes two separate, confirmed POST actions:

- **Setujui pembayaran & kirim stok:** calls the existing manual-completion lifecycle transition. The transition locks order/payment state, marks the payment paid, delivers stock, and writes an audit entry.
- **Tolak pembayaran & lepas stok:** calls the existing pending-cancellation transition. The transition releases reserved stock and writes an audit entry.

Both endpoints use the existing admin access middleware, order policy, CSRF protection, and state-machine guards. A repeated or concurrent action receives the existing invalid-transition response; it cannot deliver stock twice or release stock after completion.

## Testing

Feature coverage will prove three-decimal allocation, uniqueness, exhaustion handling, mismatch evidence capture, dashboard visibility, approval delivery, rejection stock release, and concurrent/terminal-state safety. Existing exact-match auto-verification coverage remains unchanged.
