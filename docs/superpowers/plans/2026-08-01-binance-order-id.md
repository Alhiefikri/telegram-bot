# Binance Exact-Amount Order ID Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace Binance unique-amount checkout with exact pricing and make a persisted Binance Order ID the only automatic verification key.

**Architecture:** Keep the existing payment/order lifecycle and sanitized Binance transaction model. Persist the buyer's latest Order ID and attempt metadata on `payments`, verify only transactions whose provider Order ID matches that value, and use a delayed queue job for 30-second retries. Preserve the original deadline, allow one fixed three-minute technical grace window, and route post-expiry screenshots to admin evidence without reviving stock.

**Tech Stack:** Laravel/PHP, Eloquent migrations and model casts, database queue jobs, Telegram webhook intents, PHPUnit feature tests, PostgreSQL production database.

## Global Constraints

- New Binance payments must use the exact stored USDT total with no unique-amount surcharge.
- Automatic delivery requires a buyer-submitted Binance Order ID and full receiver, currency, amount, time, and transaction-reuse validation.
- Valid Order ID submissions are capped at three; invalid-format input does not consume an attempt.
- The original 20-minute payment deadline is not extended; only a fixed three-minute technical grace window is allowed after an eligible pre-deadline submission.
- Mismatch, reused transaction, API exhaustion, and late evidence must never auto-deliver or restore released stock.
- Existing credential-less Binance screenshot checkout remains available.
- The persisted `binance_auto_verification_enabled` snapshot controls whether an order may use automatic verification after checkout.
- Do not revert or modify unrelated existing worktree changes.

---

### Task 1: Remove Unique Amount Allocation

**Files:**
- Modify: `src/app/Services/OrderCheckoutService.php`
- Modify: `src/config/services.php`
- Modify: `docker-compose.yml`
- Delete: `src/app/Services/Binance/BinanceUniquePaymentAmount.php`
- Modify: `src/tests/Feature/Telegram/BinanceAutoVerifyTest.php`
- Modify: `src/tests/Feature/StorefrontShopCheckoutTest.php`
- Modify: `src/tests/Feature/ProductionConcurrencyTest.php`

**Interfaces:**
- `OrderCheckoutService::checkout()` continues returning an order with a Binance payment.
- `BinancePayClient::configured()` remains the snapshot source for `binance_auto_verification_enabled`.

- [ ] **Step 1: Change failing checkout assertions first.**

  Replace tests that expect a random or unique offset with assertions that two Binance payments for the same product price keep the same integer micro-USDT amount. Change storefront text assertions from `3.001 USDT` to `3 USDT` while retaining the exact-amount warning.

- [ ] **Step 2: Run the focused pricing tests and verify they fail.**

  Run: `cd src && php artisan test tests/Feature/Telegram/BinanceAutoVerifyTest.php tests/Feature/StorefrontShopCheckoutTest.php tests/Feature/ProductionConcurrencyTest.php --filter='unique|three_decimal|exact_unique|binance_auto_verification_checkout'`

  Expected: FAIL because `OrderCheckoutService` still calls the unique allocator.

- [ ] **Step 3: Make checkout store the exact amount.**

  Replace the `BinanceUniquePaymentAmount` dependency with `BinancePayClient`, use `configured()` only for the boolean snapshot, and make `paymentAmountFor()` return `paymentIntent->paymentAmount(...)` unchanged for Binance.

- [ ] **Step 4: Remove obsolete unique-amount configuration and service.**

  Delete the unique amount configuration entries from `src/config/services.php` and `docker-compose.yml`, then delete the allocator class. Do not change persisted payment amounts.

- [ ] **Step 5: Run the focused pricing tests again.**

  Run: `cd src && php artisan test tests/Feature/Telegram/BinanceAutoVerifyTest.php tests/Feature/StorefrontShopCheckoutTest.php tests/Feature/ProductionConcurrencyTest.php --filter='unique|three_decimal|exact_unique|binance_auto_verification_checkout'`

  Expected: PASS for exact-amount behavior and the updated concurrency expectation.

---

### Task 2: Persist Order ID Verification State

**Files:**
- Create: `src/database/migrations/2026_08_01_000001_add_binance_order_id_verification_fields.php`
- Modify: `src/app/Models/Payment.php`
- Modify: `src/tests/Feature/Telegram/BinanceAutoVerifyTest.php`

**Interfaces:**
- `Payment` exposes `binance_order_id`, `binance_order_id_attempts`, `binance_order_id_submitted_at`, and `binance_verification_grace_until` with correct casts.
- The existing partial unique index `payments_pending_binance_auto_amount_unique` is removed by the migration.

- [ ] **Step 1: Add schema assertions to the feature test.**

  Assert a newly created auto-verification payment keeps the exact amount, starts with a null Order ID, zero attempts, and null grace metadata. Add an assertion that two pending auto-verification payments can share an amount.

- [ ] **Step 2: Run the schema assertions and verify they fail.**

  Run: `cd src && php artisan test tests/Feature/Telegram/BinanceAutoVerifyTest.php --filter='exact_amount|duplicate_pending_binance_amount'`

  Expected: FAIL because the old unique index still rejects duplicate pending amounts and the fields do not exist.

- [ ] **Step 3: Write the migration.**

  Add the four nullable/count fields, add an index covering `method`, `status`, and `binance_order_id`, and drop the old partial unique index by its existing name. The down migration must restore the old index and remove the new fields.

- [ ] **Step 4: Update `Payment` fillable fields and casts.**

  Cast the timestamps as `datetime` and the attempt count as `integer`; keep `provider_reference` and `provider_payload` semantics unchanged.

- [ ] **Step 5: Run the schema assertions again.**

  Run: `cd src && php artisan test tests/Feature/Telegram/BinanceAutoVerifyTest.php --filter='exact_amount|duplicate_pending_binance_amount'`

  Expected: PASS.

---

### Task 3: Make Order ID the Canonical Binance Match

**Files:**
- Modify: `src/app/Services/Binance/BinanceAutoVerification.php`
- Modify: `src/app/Console/Commands/ReconcileBinancePayments.php`
- Modify: `src/tests/Feature/Telegram/BinanceAutoVerifyTest.php`

**Interfaces:**
- `BinanceAutoVerification::verifyPaymentByProviderOrderId(Payment $payment, string $binanceOrderId)` remains the direct verification entry point.
- `BinanceAutoVerification::matchAndConfirm(Payment $payment, array $rawTransactions)` matches only the payment's stored `binance_order_id`; it never scans by amount alone.
- `ReconcileBinancePayments` selects pending Binance payments with a non-null stored Order ID.

- [ ] **Step 1: Add tests proving amount-only history cannot pay an order.**

  Create a pending auto-verification order without a stored Order ID, return an exact incoming transaction from the API, run `binance:reconcile`, and assert the order remains pending and no provider reference is stored. Add a test where the stored Order ID identifies a wrong-amount transaction and assert sanitized mismatch evidence is retained.

- [ ] **Step 2: Run the new tests and verify they fail.**

  Run: `cd src && php artisan test tests/Feature/Telegram/BinanceAutoVerifyTest.php --filter='without_buyer_action|wrong_amount|reconcile_command'`

  Expected: FAIL because the current reconciler matches exact amounts without requiring an Order ID.

- [ ] **Step 3: Change `matchAndConfirm()` to locate by stored Order ID.**

  Parse transactions once, find the transaction whose provider `orderId()` equals the stored ID, return `NotFound` when absent, record sanitized mismatch evidence for found-but-invalid transactions, and call the existing locked `confirm()` only after the full match predicate passes. Clear the grace marker when a found mismatch or reused transaction requires admin attention.

- [ ] **Step 4: Restrict the reconciliation query.**

  Add `whereNotNull('binance_order_id')` and leave API history caching and the existing unique transaction claim intact. Do not add an amount fallback.

- [ ] **Step 5: Run all direct and command verification tests.**

  Run: `cd src && php artisan test tests/Feature/Telegram/BinanceAutoVerifyTest.php --filter='ive_paid|positive_amount|wrong_currency|before_checkout|one_transaction|order_id_fallback|reconcile'`

  Expected: PASS after updating old callback-driven test setup to submit an Order ID.

---

### Task 4: Implement Prompt, Persistence, and Delayed Retry

**Files:**
- Create: `src/app/Jobs/VerifyBinanceOrderId.php`
- Modify: `src/app/Services/Telegram/TelegramBinanceVerifyIntents.php`
- Modify: `src/app/Services/Telegram/TelegramPaymentInstructionRenderer.php`
- Modify: `src/app/Services/Telegram/TelegramCheckoutIntents.php`
- Modify: `src/tests/Feature/Telegram/BinanceAutoVerifyTest.php`
- Modify: `src/tests/Unit/Services/Telegram/TelegramPaymentInstructionRendererTest.php`

**Interfaces:**
- `VerifyBinanceOrderId` is a queue job carrying payment ID, attempt number, and stored Order ID; it re-checks eligibility before every provider call and dispatches the next 30-second retry only within grace.
- `TelegramBinanceVerifyIntents::handleVerifyTap()` opens the Order ID prompt and does not call Binance.
- `TelegramBinanceVerifyIntents::handleOrderIdInput()` validates, atomically persists, verifies immediately, and schedules a retry on `NotFound` or `ProviderError`.

- [ ] **Step 1: Add failing Telegram flow tests.**

  Cover: the **I've Paid** callback only sends an Order ID prompt; invalid input does not increment attempts; a valid ID is stored; a not-found result queues the retry job; a matching ID confirms the order; a fourth valid ID is rejected; replacing an ID makes a queued job no-op; and background retries do not send a message for each not-found result.

- [ ] **Step 2: Run the new flow tests and verify they fail.**

  Run: `cd src && php artisan test tests/Feature/Telegram/BinanceAutoVerifyTest.php tests/Unit/Services/Telegram/TelegramPaymentInstructionRendererTest.php --filter='order_id|ive_paid|retry|attempt|prompt'`

  Expected: FAIL because the callback currently calls amount-based verification and no retry job exists.

- [ ] **Step 3: Add atomic Order ID submission.**

  In a database transaction, lock the order and pending Binance payment, reject submissions after the original expiry for automatic processing, enforce the three-valid-ID limit, increment attempts, preserve the first `expired_at + 3 minutes` grace deadline, update the latest ID and submission timestamp, and return fresh models for immediate verification.

- [ ] **Step 4: Change buyer-facing intent behavior.**

  Route both the initial and explicit Order ID callbacks to the prompt. Keep the Telegram session tied to the order. After a valid ID, report confirmation, mismatch, or a single waiting message; do not tell the buyer to manually retry while the background job is active. Keep a controlled “change Order ID” action available while attempts remain.

- [ ] **Step 5: Add the delayed queue job.**

  The job must no-op when the payment is no longer pending, the stored ID differs, the attempt number differs, or grace has ended. Otherwise call `verifyPaymentByProviderOrderId()`. On `NotFound` or `ProviderError`, dispatch itself with a 30-second delay while grace is active. On `Confirmed` or admin-attention outcomes, send only the terminal buyer/admin response through the existing intent/escalation services.

- [ ] **Step 6: Update Telegram instruction copy.**

  Replace wording that implies amount-only automatic confirmation with instructions to tap **I've Paid**, copy the Binance Order ID from Order Details, and send it to the bot. Preserve exact amount copy and the credential-less screenshot mode.

- [ ] **Step 7: Run focused flow tests again.**

  Run: `cd src && php artisan test tests/Feature/Telegram/BinanceAutoVerifyTest.php tests/Unit/Services/Telegram/TelegramPaymentInstructionRendererTest.php --filter='order_id|ive_paid|retry|attempt|prompt'`

  Expected: PASS.

---

### Task 5: Preserve Grace Before Expiry

**Files:**
- Modify: `src/app/Services/OrderStateMachine.php`
- Modify: `src/app/Console/Commands/ExpirePendingOrders.php`
- Modify: `src/app/Services/Telegram/TelegramOrderExpiredNotifier.php`
- Modify: `src/tests/Feature/OrderCartDomainTest.php`
- Modify: `src/tests/Feature/Telegram/BinanceAutoVerifyTest.php`

**Interfaces:**
- Pending expiry does not release an order while a pending Binance payment has `binance_verification_grace_until` in the future.
- Found mismatches clear grace and remain subject to the original order deadline.
- Expiry notices for exhausted Binance verification tell the buyer to send `/payment INVOICE` with a screenshot.

- [ ] **Step 1: Add expiry tests.**

  Create an expired-due order with an active Binance grace timestamp and assert `orders:expire-pending` leaves it pending. Add a test where grace is over and assert stock is released. Add a mismatch test proving the original deadline is used instead of grace.

- [ ] **Step 2: Run the expiry tests and verify they fail.**

  Run: `cd src && php artisan test tests/Feature/OrderCartDomainTest.php tests/Feature/Telegram/BinanceAutoVerifyTest.php --filter='grace|expire|mismatch'`

  Expected: FAIL because expiry currently checks only `orders.expires_at`.

- [ ] **Step 3: Enforce the grace guard in the lifecycle.**

  Before applying due expiry, load payments and return the existing no-op lifecycle result when a pending Binance payment has a future grace timestamp. Keep the lock and release transition unchanged for eligible orders.

- [ ] **Step 4: Update the scheduled expiry query and notifier.**

  Avoid selecting orders with an active grace window, and add a Binance-specific expired message with the invoice-based screenshot fallback. Do not restore reservations or change the original timestamps.

- [ ] **Step 5: Run the expiry tests again.**

  Run: `cd src && php artisan test tests/Feature/OrderCartDomainTest.php tests/Feature/Telegram/BinanceAutoVerifyTest.php --filter='grace|expire|mismatch'`

  Expected: PASS.

---

### Task 6: Accept Late Binance Evidence Without Reviving Orders

**Files:**
- Modify: `src/app/Services/Telegram/TelegramManualPaymentIntents.php`
- Modify: `src/app/Console/Commands/ForwardPendingManualPaymentProofs.php`
- Modify: `src/tests/Feature/Telegram/PaymentProofAdminCallbackTest.php`

**Interfaces:**
- `/payment INVOICE` accepts an expired Binance payment as evidence when no proof was previously submitted.
- `forwardSubmittedManualPaymentProof()` forwards expired Binance evidence without pending-order approval/rejection buttons.
- Existing pending manual QR/Binance proof behavior remains unchanged.

- [ ] **Step 1: Add a late-proof feature test.**

  Create an expired Binance payment, submit a photo with `/payment INVOICE`, assert the proof is stored, the order remains expired, the reservation is not restored, and the admin photo contains late-review instructions without `admin:approve-payment` or `admin:reject-payment` callbacks.

- [ ] **Step 2: Run the late-proof test and verify it fails.**

  Run: `cd src && php artisan test tests/Feature/Telegram/PaymentProofAdminCallbackTest.php --filter='late|expired'`

  Expected: FAIL because expired orders are currently rejected and forwarding only claims pending payments.

- [ ] **Step 3: Add a separate expired-Binance lookup and claim path.**

  Keep normal pending manual lookup intact. For an explicit invoice, allow only the customer's expired Binance payment with no existing proof. Store the photo without nulling `expired_at` or `order.expires_at`.

- [ ] **Step 4: Make forwarding status-aware.**

  Permit the recovery command to claim pending proofs plus expired/failed Binance proofs. Render approval buttons only for pending orders; render evidence-only late-review text for expired/failed Binance payments.

- [ ] **Step 5: Run all payment-proof tests.**

  Run: `cd src && php artisan test tests/Feature/Telegram/PaymentProofAdminCallbackTest.php`

  Expected: PASS for existing proof flows and the new late-evidence flow.

---

### Task 7: Update Reconciliation And Regression Coverage

**Files:**
- Modify: `src/tests/Feature/Telegram/BinanceAutoVerifyTest.php`
- Modify: `src/tests/Feature/StorefrontShopCheckoutTest.php`
- Modify: `src/tests/Feature/ProductionConcurrencyTest.php`
- Modify: `src/tests/Feature/AdminDashboardAccessTest.php` if protected route inventory or Binance review assertions require updated fields
- Modify: `src/tests/Feature/AdminDashboardSalesRenderingTest.php` if Binance review rendering needs submitted-ID coverage

**Interfaces:**
- The complete test suite sees exact Binance prices, Order ID-only reconciliation, grace-aware expiry, and safe late evidence.

- [ ] **Step 1: Update old expectations and add missing race cases.**

  Remove old random-offset assertions, update auto-verification fixtures to submit IDs before invoking reconciliation, and add concurrent verification coverage proving only one order claims a transaction.

- [ ] **Step 2: Run all affected feature tests.**

  Run: `cd src && php artisan test tests/Feature/Telegram/BinanceAutoVerifyTest.php tests/Feature/Telegram/PaymentProofAdminCallbackTest.php tests/Feature/StorefrontShopCheckoutTest.php tests/Feature/ProductionConcurrencyTest.php tests/Feature/OrderCartDomainTest.php`

  Expected: PASS.

- [ ] **Step 3: Run static/style checks used by the repository.**

  Run: `cd src && php artisan test tests/Feature/AdminDashboardAccessTest.php tests/Feature/AdminDashboardSalesRenderingTest.php tests/Feature/PaymentDocumentationTest.php`

  Expected: PASS, including payment documentation and dashboard rendering.

---

### Task 8: Final Verification And Operational Review

**Files:**
- Verify: all files changed by Tasks 1-7

- [ ] **Step 1: Inspect the worktree and diff.**

  Run: `git status --short` and `git diff --stat` followed by `git diff -- <changed application paths>`; confirm unrelated existing files are untouched.

- [ ] **Step 2: Run the full application test suite.**

  Run: `cd src && php artisan test`

  Expected: PASS. If unrelated existing tests fail, record their exact failures separately and do not revert unrelated work.

- [ ] **Step 3: Verify migration and queue behavior.**

  Run: `cd src && php artisan migrate:status` and `php artisan schedule:list`; confirm the new payment migration is pending/applied as appropriate and existing queue/scheduler entries remain present.

- [ ] **Step 4: Report Docker refresh requirements.**

  Because this changes PHP code, migrations, queue jobs, Telegram copy, and compose environment configuration, remind the user that the running stack must be refreshed with the repository's existing project name: `docker compose -p telegram-bot-prod up -d --build --remove-orphans`, followed by `docker compose -p telegram-bot-prod ps` and `docker compose -p telegram-bot-prod exec app php artisan ops:health` when they are ready to apply locally.
