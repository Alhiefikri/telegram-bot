# Binance Order ID Direct Dashboard Toggle Design

## Goal

Remove the admin-only Binance Order ID canary and allow the owner to select **Order ID** or **Amount unik (legacy)** directly from the dashboard.

The dashboard remains responsible for validating that Order ID has usable Binance credentials. A real self-payment or canary transaction is never required to select the mode.

## Product Rules

- Selecting `order_id` immediately makes new Binance checkout payments use Order ID verification.
- Selecting `amount_unique` immediately makes new Binance checkout payments use the sequential unique-amount allocator.
- Enabling Order ID requires Binance API key, API secret, and either Binance Pay ID or Binance UID.
- The dashboard must show a configuration error when those credentials are absent; it must not silently select a non-verifiable Order ID mode.
- Existing payments retain their snapshotted `binance_verification_mode`, amount, receiver, state, and lifecycle. A later toggle never rewrites historical or pending payments.
- Order ID payments retain the exact catalog USDT amount.
- Amount-unique payments retain the deterministic smallest-free sequence beginning at the catalog amount and advancing by `0.001` USDT only when occupied.

## Compatibility and Concurrency

The existing partial unique index continues to protect every pending automatic Binance payment by amount. It is deliberately not narrowed to legacy payments as part of this change.

The sequential allocator treats pending automatic Binance payments in both modes as occupied. This aligns selection with the live unique index before and after a dashboard toggle:

- A pending Order ID payment at `1.000` causes a new legacy payment to use `1.001`.
- A pending legacy payment remains individually reconcilable by its allocated amount.
- A toggle cannot make a new payment collide with an already pending payment of either mode.
- Concurrent legacy checkouts remain protected by the database index and existing checkout retry.

This conservative cross-mode occupancy may make a legacy checkout advance by `0.001` even when a mode-specific index could theoretically permit the exact amount. It prevents checkout failures and preserves the user's stated rule: a current order at that amount advances the next legacy amount.

## Removed Canary Flow

Remove all runtime dependencies on a successful admin payment:

- the admin-only pre-readiness checkout branch;
- the `binance_order_id_compatibility_ready_at` readiness check used for mode selection;
- `BinanceOrderIdCanaryEvidence` and its activation requirement;
- `BinanceOrderIdActivation` invocation from the dashboard toggle;
- canary health-check option/reporting;
- canary age configuration and production validation;
- dashboard readiness/guard copy and storefront readiness gating;
- canary command, environment, operational documentation, and tests.

The historical database column may remain unused in this release. Removing it would be destructive and is not needed to change the runtime contract.

## Dashboard and Checkout

`BinanceVerificationModeManager` becomes the sole mode-switch policy:

1. Reject invalid mode values.
2. For `amount_unique`, persist the setting directly.
3. For `order_id`, require configured API key, API secret, and Pay ID or UID, then persist the setting directly.

`BinancePayCheckoutIntent` reads the active setting and returns the configured valid mode without admin/customer identity logic or readiness marker checks. Invalid/missing settings still fall back to legacy amount mode as a defensive default.

The storefront and dashboard present the selected configured mode directly. They no longer mention canaries, compatibility readiness, or an admin-only exception.

## Tests

Tests must prove:

- an owner can select Order ID with valid credentials and no prior paid payment/canary;
- activation is rejected when required credentials are absent;
- a non-admin buyer receives Order ID checkout immediately after direct toggle;
- toggling back to Amount unik preserves pending Order ID payment records and creates sequential legacy payments;
- pending Order ID amounts block the sequential legacy allocator;
- canary-specific health, mode, and checkout behavior is absent;
- no configuration or UI copy exposes the removed canary contract.

## Non-Goals

- Changing Order ID submission, matching, retry, or delivery semantics.
- Changing unique-amount verification semantics other than preserving its current sequential allocator.
- Destructively dropping historical marker columns or migrations.
- Automatically selecting Order ID without owner action.
