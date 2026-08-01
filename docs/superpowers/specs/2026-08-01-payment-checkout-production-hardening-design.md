# Payment Checkout Production Hardening Design

## Goal

Prevent buyer-visible QRIS amounts that contradict the product total and
Pakasir fee contract, then provide a safe, evidence-backed path from Binance
legacy unique amounts to exact-amount Order ID verification.

## Scope

This change hardens native Pakasir QRIS creation, persisted QRIS readiness,
Telegram/storefront presentation, Binance pre-activation canaries, and the
guarded production activation command. It does not deploy production, mutate
existing production orders, or bypass the protected deployment workflow.

## Authoritative Pakasir Contract

The Pakasir pricing page updated 21 July 2026 states:

- QRIS amounts up to and including Rp105.000 cost 0.7% plus Rp310.
- QRIS amounts above Rp105.000 cost 1% with no fixed fee.
- The project can choose whether the merchant or customer bears the fee.

The Pakasir API documentation defines `amount` as the transaction amount,
`fee` as the transaction fee, and `total_payment` as the total including fee.
Its published Rp99.000 example returns a Rp1.003 fee and Rp100.003 total.

Sources:

- https://pakasir.com/p/pricing
- https://pakasir.com/p/docs

Because an integer-rupiah API cannot represent a fractional rupiah, fee
validation permits only the floor or ceiling of the percentage component.
That is at most a one-rupiah rounding difference and is not a general-purpose
tolerance.

The fee schedule and fee payer are explicit application configuration. The
production defaults mirror the official schedule above, while environment
overrides allow an audited tariff update without a code rewrite. Configuration
must reject unsupported fee-payer values and nonsensical negative/rate values.

## QRIS Financial Integrity Boundary

A focused `PakasirQrisFeePolicy` owns the official fee calculation and exposes
the allowed integer fee values for a product amount. It supports exactly two
fee-payer modes:

- `customer`: `total_payment` must equal `amount + fee`.
- `merchant`: `total_payment` must equal `amount`; the fee is deducted from
  merchant proceeds and must not be presented as an extra buyer charge.

For both modes, the provider fee must match one of the policy's allowed
integer values. A response missing `fee` or `total_payment`, reporting a fee
outside the policy, or violating the payer-specific total equation is invalid.

`PakasirQrisTransaction` validates project, order ID, base amount, method, fee,
total, and QR payload before the native creation service persists a payable
transaction. The provider's QR payload must be a valid top-level EMV TLV with:

- currency tag `53` equal to `360` (IDR);
- amount tag `54` numerically equal to `total_payment` in rupiah;
- a valid CRC tag `63` using CRC16/CCITT-FALSE.

Malformed or inconsistent responses fail closed. The service stores only
sanitized diagnostic state, never exposes the QR, and leaves the payment in a
non-payable recovery/failure state. Telegram and storefront must show a safe
"QRIS unavailable; do not pay" message rather than a QR or website payment
action.

Persisted legacy QRIS rows are also re-evaluated through the same pure fee and
total policy before `Payment::isPayableNativePakasirQris()` returns true. This
prevents already-stored bad totals from being redisplayed after deployment.

The buyer-facing caption derives its fee wording from the configured payer:

- customer payer: show product total, customer QRIS fee, and their sum;
- merchant payer: show product total as the buyer total and state that the
  merchant bears the provider fee.

## Binance Order ID Canary and Activation

The current readiness marker creates a circular rollout: Order ID checkout is
hidden until readiness, while readiness requires a buyer-visible Order ID
canary. The safe bridge is a narrowly scoped admin canary.

Before global activation, a checkout owned by the configured
`TELEGRAM_ADMIN_USER_ID` uses `order_id` mode. Every other buyer remains on
`amount_unique`. The canary uses the real exact catalog amount, receiver
snapshot, **I've Paid** button, Order ID prompt, Binance history verification,
queue, stock delivery, and receipt path. Storefront-only buyers cannot enter
canary mode because they have no matching Telegram identity.

The guarded `binance:mark-order-id-ready --confirm-canary` command must also
find recent persisted evidence of a successful admin canary:

- payment mode is `order_id`;
- payment and order are paid/fulfilled through the normal state machine;
- a submitted Binance Order ID and unique provider reference exist;
- receiver type/value were snapshotted;
- verification status records a successful match;
- the payment belongs to the configured Telegram admin;
- completion is recent enough for the deployment window.

If any evidence is absent, the command fails without changing the readiness
marker or global mode. After evidence passes, the existing transactional index
switch and mode activation run unchanged. Existing legacy orders retain their
snapshotted unique amount and manual flow; only new orders use the newly active
global mode.

## Error Handling and Operations

Invalid QRIS provider responses are provider-contract failures, not buyer
payment failures. They must be sanitized, observable to administrators, and
must not release a usable QR. Recovery may query transaction detail, but the
same full validation applies before recovery becomes payable.

Production activation remains manual from `main` through the `Deploy
production` GitHub Actions workflow. The workflow may request Binance
activation only after CI, public smoke checks, worker health, and the persisted
canary evidence pass. No `.env`-only switch or ad-hoc production SSH activation
is supported.

Pakasir's 21 July 2026 service notice says Telegram bots and premium-account
sales are among categories no longer supported during its August transition.
This is an external production/compliance blocker independent of code quality.
Before re-enabling or deploying Pakasir QRIS for this store, the operator must
obtain written confirmation from Pakasir that the merchant account and product
catalog remain supported, or select another compliant provider.

## Testing and Release Gates

Tests use complete provider-shaped fixtures and real financial validation.
They cover:

- official fee boundaries at Rp105.000 and Rp105.001;
- exact and one-rupiah floor/ceiling rounding cases;
- customer-payer and merchant-payer equations;
- the reported Rp20.000 / Rp2.660 / Rp268.660 regression;
- missing, negative, excessive, and inconsistent fee/total fields;
- EMV QR currency, amount, structure, and CRC mismatch;
- create, recovery, persisted readiness, Telegram, and storefront fail-closed
  behavior;
- no QR image/payment link exposure for invalid financials;
- admin-only Binance canary selection before readiness;
- exact canary amount and **I've Paid**/Order ID UX;
- refusal to activate without successful recent canary evidence;
- successful global activation after evidence;
- unchanged legacy order snapshots;
- SQLite unit/feature tests, PostgreSQL migration/concurrency tests, production
  configuration validation, Docker build, and relevant release harnesses.

No live payment is generated by automated tests. A real QRIS and Binance canary
remain explicit protected pre-production/production operations.
