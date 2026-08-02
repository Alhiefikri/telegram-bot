# Payment Mode Dashboard and Checkout Remediation Design

## Goal

Make the admin dashboard the only operational control plane for Binance checkout verification mode, preserve a guarded Order ID rollout, and correct the checkout defects found during production dogfooding.

## Scope

This change covers:

- dashboard control of Binance `amount_unique` versus `order_id` mode;
- removal of the non-secret `BINANCE_VERIFICATION_MODE` runtime toggle;
- an additive schema repair for databases that ran an earlier version of the Binance migration;
- canonical-host handling that preserves checkout session and CSRF state;
- payment-method-aware terminal copy;
- explicit cancelled payment status presentation;
- regression verification for Telegram, storefront, schema upgrades, and deployment configuration.

It does not rotate provider credentials, make a real payment, activate Order ID without canary evidence, or deploy production automatically.

## Current Behavior and Root Causes

`BinancePayCheckoutIntent::verificationMode()` reads `manual_payment_settings.binance_verification_mode`, but the dashboard does not expose that field. If the setting is absent or invalid, the service falls back to `services.binance.verification_mode`, which is populated by `BINANCE_VERIFICATION_MODE` from the environment.

The compatibility readiness timestamp creates another hidden condition. Before readiness, only the configured Telegram admin canary receives `order_id`; every other buyer remains on `amount_unique`. The production deploy workflow exposes a separate command-based activation step, so the operational mode has two potential control planes and neither is visible from the dashboard.

The first Binance migration was modified after some databases had already recorded it as complete. Those databases do not receive the later columns and indexes because Laravel does not rerun an applied migration.

The public `www` host serves the storefront while generated checkout URLs use the non-`www` application host. A browser session created on `www` therefore submits CSRF-protected forms to a different host and receives HTTP 419.

Terminal invoice copy is QRIS-specific even when the payment is Binance, and cancelled invoice summaries can render an empty payment status.

## Design Decisions

### Database Is the Mode Authority

`ManualPaymentSetting.binance_verification_mode` is the sole source for new Binance checkout mode selection. Allowed values remain `amount_unique` and `order_id`.

The application will not read `services.binance.verification_mode` to select a checkout mode. `BINANCE_VERIFICATION_MODE` is removed from application config, Compose environment wiring, environment examples, deployment configuration, and documentation. For Binance mode selection, environment variables are limited to provider secrets and receiver identity:

- `BINANCE_API_KEY`;
- `BINANCE_API_SECRET`;
- `BINANCE_PAY_ID` or `BINANCE_PAY_UID`.

Existing non-secret deployment tuning is not expanded or used as a checkout-mode control by this change.

Existing payments retain their snapshotted `binance_verification_mode`. A dashboard change affects only payments created afterward.

If no active setting exists, the persisted default is `amount_unique`. The application must fail closed to that database default rather than consult an environment toggle.

### Dashboard Control

The payment settings card gains a Binance verification section with two explicit choices:

- **Amount unik (legacy)**;
- **Order ID**.

The card displays the effective mode and readiness state. It must distinguish:

- Order ID active;
- amount-unique active;
- Order ID unavailable because credentials or receiver are missing;
- Order ID awaiting successful canary evidence.

Changing back to `amount_unique` is always permitted. Activating `order_id` requires all of the following in the same server-side transaction:

1. Binance API key and secret are configured;
2. a Pay ID or UID receiver is configured;
3. `BinanceOrderIdCanaryEvidence` finds a recent successful admin-canary payment under the existing maximum-age policy;
4. pending legacy unique-amount payments do not violate the existing safe-cutover invariant.

If any guard fails, the setting remains unchanged and the dashboard returns a field-specific explanation. Authorization remains restricted to admins who can manage payment settings. Every successful or rejected activation attempt is auditable without persisting secrets.

The existing activation service logic is reused rather than duplicated in the controller. The command and deploy input that mutate readiness are removed after the dashboard calls the shared guarded activation path, leaving one control plane. Read-only deployment health and smoke gates remain unchanged.

### Additive Schema Repair

A new migration, with a new timestamp and name, repairs installations that already ran the earlier Binance migration. It must inspect schema and index state and add only missing elements. It must never edit or rely on rerunning the old migration.

The repair covers the Binance fields and indexes currently required by `Payment` and `ManualPaymentSetting`, including:

- payment verification mode;
- receiver type and value;
- Order ID submission version and verification status;
- compatibility readiness timestamp;
- pending-verification lookup indexes.

The migration must work for both PostgreSQL and the SQLite test database. It must preserve existing payment rows, default legacy rows to `amount_unique`, and avoid rewriting already-correct installations.

A migration test must reproduce the real upgrade sequence: install the original schema shape, mark the historical migration as applied, run the new migration, and assert the final columns, defaults, and indexes.

### Canonical Host

One host is canonical for all storefront traffic. The canonical host is derived from the configured application URL. Requests arriving on the alternate `www` or non-`www` host are redirected before session and CSRF-sensitive application handling.

The redirect preserves path and query string and uses a permanent redirect for safe methods. Non-safe methods are not accepted cross-host; public GET navigation must canonicalize before a form can be submitted. Generated routes, form actions, signed URLs, and cookies consequently stay on one host.

The browser regression opens the alternate host, confirms canonicalization, performs a real session-backed checkout submission against a fake provider, and proves no HTTP 419 occurs.

### Payment-Aware Terminal Presentation

Terminal status messages are generated from both terminal cause and payment method/provider.

Examples:

- cancelled QRIS: do not pay the old QR code;
- cancelled Binance: do not send USDT for this invoice;
- expired QRIS: create a new QRIS checkout;
- expired Binance: create a new Binance checkout.

Shared lifecycle logic remains provider-neutral. Only presentation copy varies.

Cancelled invoice summaries display an explicit payment state. If the payment model remains `pending` while the order is cancelled, the UI presents `cancelled` as the effective terminal payment label without falsifying the stored financial status. Late-payment reconciliation still uses the stored provider/payment state and is not changed by presentation.

## Data Flow

### Selecting Order ID

1. Admin submits `binance_verification_mode=order_id` from the dashboard.
2. Request authorization and enum validation run.
3. A shared activation service locks the active settings row.
4. The service checks provider configuration, canary evidence, and legacy pending-payment safety.
5. On success it stores `order_id` and readiness metadata atomically and writes an audit event.
6. On failure it changes nothing and returns a dashboard validation error.
7. New Binance checkouts snapshot `order_id`; existing payments keep their original mode.

### Returning to Amount Unique

1. Admin selects `amount_unique`.
2. The service locks the active settings row.
3. It stores `amount_unique` atomically and audits the change.
4. New payments receive unique Binance amounts; existing Order ID payments continue their snapshotted verification flow.

### Checkout Host Canonicalization

1. Buyer opens either public hostname.
2. Alternate-host GET is redirected to the configured canonical host with path and query intact.
3. Session and CSRF token are created on the canonical host.
4. Checkout form action and subsequent signed invoice URL remain on that host.

## Error Handling and Safety

- Invalid dashboard mode values fail validation.
- Missing Binance credentials or receiver block Order ID activation.
- Missing or stale canary evidence blocks activation.
- Activation failure is atomic and leaves the current mode unchanged.
- Downgrade to amount unique does not mutate pending Order ID payments.
- Schema repair is additive and idempotent with respect to already-present columns and indexes.
- Canonicalization never replays a POST to another host.
- Terminal copy never instructs a Binance buyer about QRIS or vice versa.
- No secret value is displayed, audited, or persisted outside existing encrypted/configured locations.

## Verification

Automated verification must include:

- request validation for both dashboard modes;
- dashboard visibility, authorization, current-mode state, and guard messages;
- successful activation with valid canary evidence;
- rejected activation for missing credentials, missing receiver, stale/missing canary, and unsafe pending legacy state;
- immediate downgrade to amount unique;
- new-checkout-only mode changes and preservation of existing payment snapshots;
- absence of environment mode fallback;
- schema upgrade from the historical partial shape;
- alternate-host canonicalization and successful CSRF checkout;
- QRIS and Binance terminal copy for cancelled and expired states;
- explicit cancelled status presentation;
- existing Telegram Binance Order ID, legacy amount-unique, Pakasir financial-integrity, webhook, inventory, and expiry suites.

Operational verification after implementation:

1. rebuild the existing `telegram-bot-prod` Compose project without creating a duplicate stack;
2. verify Compose service health, queue schema/depth, Supervisor, scheduler heartbeat, Pakasir health, and failed jobs;
3. dogfood Telegram first: legacy selection, guarded Order ID activation state, canary flow without claiming payment unless a real transaction is supplied, and QRIS invoice arithmetic;
4. dogfood storefront second from both hostnames, check pending status, cancel each unpaid invoice, and verify stock release;
5. run public smoke checks;
6. report production deployment as a separate explicit operation.

## Acceptance Criteria

- Admin can see and control Binance verification mode from the dashboard.
- No runtime checkout-mode toggle is read from `.env`.
- Order ID cannot become globally active without successful recent admin-canary evidence.
- Amount unique remains an immediate rollback choice.
- Upgraded databases receive every required Binance column and index through a new migration.
- Checkout initiated from either public hostname does not produce HTTP 419.
- Binance terminal pages never mention paying QRIS.
- Cancelled invoices show an explicit terminal payment label.
- QRIS base amount, fee, and total remain financially validated and unaffected by Binance unique-amount allocation.
- Focused and existing regression suites pass, and production-like health checks remain green.
