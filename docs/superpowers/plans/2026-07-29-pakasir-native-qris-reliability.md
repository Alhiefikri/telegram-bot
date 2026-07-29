# Pakasir Native QRIS Reliability Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep Pakasir native QRIS as the primary checkout path, provide a safe **Lanjutkan Pembayaran** fallback only for confirmed provider failures, and stop retry/polling storms without reviving expired orders.

**Architecture:** Introduce a deep `PakasirCheckoutOrchestrator` that owns one-create idempotency, typed provider failure handling, shared traffic gating, and recovery decisions. Telegram only renders typed decisions, all fallback links use a temporary signed internal route, and every provider-completed entry point delegates to one row-locked completion processor that preserves released order state. Scheduled work becomes due-row based and performs at most one provider request per attempt.

**Tech Stack:** PHP 8.3, Laravel 13, Eloquent/PostgreSQL, Laravel queues and unique jobs, shared Laravel cache, Laravel HTTP client, Telegram Bot client, PHPUnit 12.

## Global Constraints

- Work in an isolated branch or worktree created at execution time. Do not implement directly in the active production checkout workspace.
- Native Pakasir QRIS remains the primary flow.
- Pakasir native create performs at most one `transactioncreate/qris` request per payment.
- Provider fallback is allowed only for typed provider failures, including a shared cooldown whose origin is exactly `provider_429`.
- Local budget exhaustion, cache failure, database failure, queue failure, readiness failure, Telegram failure, HTTP `400`, `401`, `403`, and `422` must not trigger hosted fallback.
- Fallback reuses the same order, payment, `merchant_ref`, amount, and stock reservation.
- Buyer-facing fallback labels are **Lanjutkan Pembayaran** and **Continue Payment**. Telegram copy must not mention Pakasir, hosted payment, rate limits, timeouts, or provider internals.
- Telegram reply markup must contain only a temporary signed internal URL, never a provider host, `/pay/` path, project slug, or raw signed URL in logs.
- Normal native success must not display the web fallback button.
- Pakasir deadline uses the persisted earliest deadline with a production default of 10 minutes. Binance Pay remains 20 minutes. KlikQRIS 15 minutes remains outside `main` scope.
- Recovery uses Transaction Detail and never repeats native create.
- Transaction Detail performs one HTTP request per attempt. Retry and backoff belong to orchestration or job scheduling.
- Global defaults are 10 Pakasir requests per minute, 4 background requests per minute, 60-second default `429` cooldown, and 300-second maximum cooldown.
- Released payment detection runs for up to 72 hours without restoring stock, fulfilling credentials, sending receipts, or changing an expired/failed order to paid.
- No SSH mutation, production `.env` change, Docker Compose command, push, or deployment workflow trigger is permitted. Stop after local verification and implementation handoff.

## File Structure

### New production files

- `src/app/Services/Pakasir/PakasirCheckPhase.php`: identifies active recovery, pending reconciliation, and late reconciliation phases.
- `src/app/Services/Pakasir/PakasirCheckSchedule.php`: computes bounded phase-specific backoff times.
- `src/app/Services/Pakasir/PakasirRequestPriority.php`: identifies foreground and background provider request classes.
- `src/app/Services/Pakasir/PakasirProviderException.php`: typed, sanitized provider transport failure.
- `src/app/Services/Pakasir/PakasirProviderFailure.php`: normalized failure classification and fallback eligibility.
- `src/app/Services/Pakasir/PakasirProviderFailureClassifier.php`: maps HTTP/transport/payload failures without message substring matching.
- `src/app/Services/Pakasir/PakasirTrafficGateDecision.php`: allowed/deferred decision with the next safe request time.
- `src/app/Services/Pakasir/PakasirProviderTrafficGate.php`: shared smoothed rate limits and `provider_429` cooldown.
- `src/app/Services/Pakasir/PakasirCheckoutDecisionType.php`: `native_ready`, `fallback_ready`, `deferred`, `terminal`, and `internal_failure`.
- `src/app/Services/Pakasir/PakasirCheckoutDecision.php`: immutable decision data for jobs and Telegram.
- `src/app/Services/Pakasir/PakasirCheckoutOrchestrator.php`: one-create checkout and native recovery state machine.
- `src/app/Storefront/PakasirContinuePaymentUrl.php`: temporary signed internal fallback URL generator.
- `src/app/Http/Controllers/ContinuePakasirPaymentController.php`: validates active payment then redirects to the provider URL.
- `src/app/Services/Telegram/TelegramPakasirFallbackInstruction.php`: text and reply markup for fallback delivery.
- `src/app/Jobs/RecoverPakasirNativeQris.php`: unique active QR recovery job.
- `src/app/Jobs/VerifyPakasirPayment.php`: unique provider verification job used by webhook and queued checks.
- `src/app/Services/Pakasir/PakasirVerificationResult.php`: typed result from one Transaction Detail attempt.
- `src/app/Services/Pakasir/PakasirPaymentVerifier.php`: traffic-gated, single-request verification entry point.
- `src/app/Services/Pakasir/PakasirCompletionResult.php`: paid, released-review, already-processed, or not-completed outcome.
- `src/app/Services/Pakasir/PakasirVerifiedCompletionProcessor.php`: row-locked common completed-payment decision.
- `src/app/Services/Telegram/TelegramPakasirReleasedPaymentNotifier.php`: send-once admin and buyer review notices.
- `src/app/Jobs/SendPakasirReleasedPaymentNotifications.php`: queued released-payment notifications.
- `src/app/Services/Pakasir/PakasirDuePaymentQuery.php`: indexed, bounded due candidate selection.
- `src/app/Services/Pakasir/PakasirReliabilityTelemetry.php`: sanitized structured events and counters.
- `src/database/migrations/2026_07_29_000001_add_pakasir_reliability_fields_to_payments.php`: additive reliability fields and due index.

### New test files

- `src/tests/Feature/PakasirReliabilitySchemaTest.php`
- `src/tests/Unit/Services/Pakasir/PakasirCheckScheduleTest.php`
- `src/tests/Unit/Services/Pakasir/PakasirProviderFailureClassifierTest.php`
- `src/tests/Unit/Services/Pakasir/PakasirProviderTrafficGateTest.php`
- `src/tests/Feature/PakasirCheckoutOrchestratorTest.php`
- `src/tests/Feature/PakasirContinuePaymentTest.php`
- `src/tests/Feature/Telegram/PakasirCheckoutDeliveryTest.php`
- `src/tests/Feature/PakasirRecoveryTest.php`
- `src/tests/Feature/PakasirReleasedPaymentTest.php`
- `src/tests/Feature/PakasirReconciliationPerformanceTest.php`
- `src/tests/Feature/PakasirReliabilityTelemetryTest.php`

---

### Task 1: Add durable state, scheduling phases, and configuration

**Files:**
- Create: `src/database/migrations/2026_07_29_000001_add_pakasir_reliability_fields_to_payments.php`
- Create: `src/app/Services/Pakasir/PakasirCheckPhase.php`
- Create: `src/app/Services/Pakasir/PakasirCheckSchedule.php`
- Modify: `src/app/Models/Payment.php:13-62`
- Modify: `src/database/factories/PaymentFactory.php`
- Modify: `src/config/services.php:56-72`
- Modify: `src/.env.example`
- Test: `src/tests/Feature/PakasirReliabilitySchemaTest.php`
- Test: `src/tests/Unit/Services/Pakasir/PakasirCheckScheduleTest.php`

**Interfaces:**
- Produces: `PakasirCheckPhase::{ActiveRecovery, PendingReconciliation, LateReconciliation}`.
- Produces: `PakasirCheckSchedule::next(PakasirCheckPhase $phase, int $attempt, CarbonInterface $from): CarbonImmutable`.
- Produces: payment fields `provider_create_attempts`, `provider_check_attempts`, `provider_next_check_at`, `provider_last_http_status`, `provider_last_error_code`, `provider_completed_at`, `provider_fallback_sent_at`, `provider_native_qris_sent_at`, `released_payment_detected_at`, `late_payment_detected_at`, `late_payment_admin_notified_at`, and `late_payment_customer_notified_at`.

- [ ] **Step 1: Write failing schema and cast tests**

Assert every new column exists, timestamp columns cast to datetimes, counters cast to integers, defaults are zero, and the composite index begins with `provider`, `status`, and `provider_next_check_at`.

Run: `cd src && php artisan test tests/Feature/PakasirReliabilitySchemaTest.php`

Expected: FAIL because the columns and casts do not exist.

- [ ] **Step 2: Write failing schedule tests**

Cover exact schedules:

```php
self::assertSame('00:00:15', $schedule->next(PakasirCheckPhase::ActiveRecovery, 0, $now)->format('H:i:s'));
self::assertSame('00:01:00', $schedule->next(PakasirCheckPhase::PendingReconciliation, 0, $now)->format('H:i:s'));
self::assertSame('00:05:00', $schedule->next(PakasirCheckPhase::LateReconciliation, 0, $now)->format('H:i:s'));
```

Also assert active recovery sequence `15s, 30s, 60s, 2m, 5m`, pending sequence `1m, 2m, 4m`, and late sequence `5m, 15m, 30m, 1h, 2h, 4h, 8h` with 8-hour repetition.

Run: `cd src && php artisan test tests/Unit/Services/Pakasir/PakasirCheckScheduleTest.php`

Expected: FAIL because the phase and schedule classes do not exist.

- [ ] **Step 3: Add the additive migration and model support**

Use `unsignedSmallInteger` for counters, nullable timestamps for state markers, nullable unsigned small integer for the HTTP status, and a bounded string for the sanitized error code. The migration must perform no provider request, job dispatch, Telegram send, or historical row rewrite.

Add every new field to `#[Fillable]` and add integer/datetime casts in `Payment::casts()`.

- [ ] **Step 4: Add scheduling and configuration defaults**

Add the phase enum and pure schedule service. Add the following `services.pakasir` keys and matching `.env.example` variables:

```php
'api_rate_limit_per_minute' => (int) env('PAKASIR_API_RATE_LIMIT_PER_MINUTE', 10),
'background_rate_limit_per_minute' => (int) env('PAKASIR_BACKGROUND_RATE_LIMIT_PER_MINUTE', 4),
'rate_limit_default_cooldown_seconds' => (int) env('PAKASIR_RATE_LIMIT_DEFAULT_COOLDOWN_SECONDS', 60),
'rate_limit_max_cooldown_seconds' => (int) env('PAKASIR_RATE_LIMIT_MAX_COOLDOWN_SECONDS', 300),
'create_claim_lease_seconds' => (int) env('PAKASIR_CREATE_CLAIM_LEASE_SECONDS', 30),
'reconcile_released_lookback_hours' => (int) env('PAKASIR_RECONCILE_RELEASED_LOOKBACK_HOURS', 72),
```

Remove the obsolete multi-attempt Transaction Detail configuration only in Task 2 when the client stops reading it.

- [ ] **Step 5: Verify migration and focused tests**

Run:

```bash
cd src
php artisan migrate:fresh --env=testing
php artisan test tests/Feature/PakasirReliabilitySchemaTest.php tests/Unit/Services/Pakasir/PakasirCheckScheduleTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/database/migrations/2026_07_29_000001_add_pakasir_reliability_fields_to_payments.php src/app/Models/Payment.php src/database/factories/PaymentFactory.php src/app/Services/Pakasir/PakasirCheckPhase.php src/app/Services/Pakasir/PakasirCheckSchedule.php src/config/services.php src/.env.example src/tests/Feature/PakasirReliabilitySchemaTest.php src/tests/Unit/Services/Pakasir/PakasirCheckScheduleTest.php
git commit -m "feat: add Pakasir reliability state"
```

### Task 2: Add typed provider failures, one-request transport, and shared traffic gate

**Files:**
- Create: `src/app/Services/Pakasir/PakasirRequestPriority.php`
- Create: `src/app/Services/Pakasir/PakasirProviderException.php`
- Create: `src/app/Services/Pakasir/PakasirProviderFailure.php`
- Create: `src/app/Services/Pakasir/PakasirProviderFailureClassifier.php`
- Create: `src/app/Services/Pakasir/PakasirTrafficGateDecision.php`
- Create: `src/app/Services/Pakasir/PakasirProviderTrafficGate.php`
- Modify: `src/app/Services/Pakasir/PakasirClient.php:37-87`
- Modify: `src/config/services.php:56-72`
- Modify: `src/.env.example`
- Test: `src/tests/Unit/Services/Pakasir/PakasirProviderFailureClassifierTest.php`
- Test: `src/tests/Unit/Services/Pakasir/PakasirProviderTrafficGateTest.php`
- Modify test: `src/tests/Feature/PakasirGatewayTest.php:70-250`

**Interfaces:**
- Produces: `PakasirProviderFailureClassifier::fromThrowable(Throwable $error, string $operation): PakasirProviderFailure`.
- Produces: `PakasirProviderFailureClassifier::fromInvalidResponse(string $operation, string $errorCode): PakasirProviderFailure`.
- Produces: `PakasirProviderTrafficGate::acquire(PakasirRequestPriority $priority): PakasirTrafficGateDecision`.
- Produces: `PakasirProviderTrafficGate::recordProviderRateLimit(?int $retryAfterSeconds): CarbonImmutable`.
- `PakasirTrafficGateDecision` exposes `allowed`, `nextAllowedAt`, and `cooldownOrigin`; only origin `provider_429` is fallback evidence.

- [ ] **Step 1: Write classifier tests**

Assert fallback eligibility for `429`, `408`, `500..599`, `520`, connection timeout, DNS/TLS/reset failures, malformed JSON, missing QR, missing final total, and ambiguous-detail `404`. Assert no fallback for `400`, `401`, `403`, `422`, readiness/configuration errors, database errors, cache errors, queue errors, and Telegram errors.

Run: `cd src && php artisan test tests/Unit/Services/Pakasir/PakasirProviderFailureClassifierTest.php`

Expected: FAIL because typed failures do not exist.

- [ ] **Step 2: Write traffic gate tests using a shared test cache**

Prove:

- total bucket capacity is 10 and refills at one token per 6 seconds;
- background capacity is 4 and refills at one token per 15 seconds;
- a background request consumes both buckets;
- adjacent minute boundaries cannot create a 20-request burst;
- valid, missing, invalid, and oversized `Retry-After` values produce bounded cooldowns;
- `provider_429` cooldown blocks every Pakasir HTTP operation;
- local budget exhaustion returns deferred without a provider cooldown origin;
- cache read/write failure throws an internal failure instead of allowing fallback.

Run: `cd src && php artisan test tests/Unit/Services/Pakasir/PakasirProviderTrafficGateTest.php`

Expected: FAIL.

- [ ] **Step 3: Implement typed exceptions and classification**

`PakasirProviderException` must retain only operation, nullable HTTP status, sanitized error code, nullable retry-after seconds, and the original exception as `previous`. Do not retain raw response bodies, API keys, authorization headers, QR strings, or signed URLs.

- [ ] **Step 4: Implement shared smoothed gate**

Store token bucket and cooldown state in the configured shared cache under a short cache lock. Cooldown state contains `origin=provider_429`, `created_at`, and `cooldown_until`. Administrative deferrals and local token exhaustion must not write that origin.

- [ ] **Step 5: Remove the Transaction Detail retry burst**

Change `PakasirClient::transactionDetail()` to one Laravel HTTP request. Convert `ConnectionException`, `RequestException`, malformed JSON, and sanitized status metadata to `PakasirProviderException`. Keep default timeout 10 seconds and connect timeout 3 seconds. Remove reads of `transaction_detail_max_attempts`, `transaction_detail_retry_delay_ms`, and `transaction_detail_retry_max_delay_ms` from production code and `.env.example`.

- [ ] **Step 6: Verify one HTTP request per operation**

Add `Http::fakeSequence()` assertions proving one call for one Transaction Detail invocation, including `429`, `520`, timeout, and malformed JSON paths.

Run:

```bash
cd src
php artisan test tests/Unit/Services/Pakasir/PakasirProviderFailureClassifierTest.php tests/Unit/Services/Pakasir/PakasirProviderTrafficGateTest.php tests/Feature/PakasirGatewayTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/app/Services/Pakasir src/config/services.php src/.env.example src/tests/Unit/Services/Pakasir src/tests/Feature/PakasirGatewayTest.php
git commit -m "feat: gate Pakasir provider traffic"
```

### Task 3: Implement the one-create checkout orchestrator

**Files:**
- Create: `src/app/Services/Pakasir/PakasirCheckoutDecisionType.php`
- Create: `src/app/Services/Pakasir/PakasirCheckoutDecision.php`
- Create: `src/app/Services/Pakasir/PakasirCheckoutOrchestrator.php`
- Modify: `src/app/Services/Pakasir/PakasirNativeQrisService.php:19-186`
- Modify: `src/app/Models/Payment.php:75-94`
- Test: `src/tests/Feature/PakasirCheckoutOrchestratorTest.php`
- Modify support: `src/tests/Support/PakasirNativeQrisScenarios.php`

**Interfaces:**
- Produces: `PakasirCheckoutOrchestrator::prepare(Payment $payment): PakasirCheckoutDecision`.
- Produces: `PakasirCheckoutOrchestrator::recover(Payment $payment): PakasirCheckoutDecision`.
- `PakasirCheckoutDecision` exposes `type`, nullable `transaction`, nullable `retryAt`, and nullable sanitized `failure`.
- `PakasirNativeQrisService` becomes a persistence/hydration collaborator. It must not decide buyer fallback copy.

- [ ] **Step 1: Write failing native success and one-create tests**

Assert a valid `200` response persists encrypted QR, QR hash, final total, fee, earliest expiry, sanitized payload, `provider_create_state=created`, and `provider_create_attempts=1`. A second sequential or concurrent `prepare()` returns the persisted transaction with no second provider call.

- [ ] **Step 2: Write failing decision tests**

Cover:

- missing QR or missing final total on `2xx` gives `fallback_ready` and schedules active recovery;
- `429`, `520`, `5xx`, timeout, and connection failure give `fallback_ready`;
- `400`, `401`, `403`, `422`, readiness failure, database failure, and cache failure give `internal_failure`;
- local token exhaustion without provider cooldown gives `deferred`;
- active `provider_429` cooldown gives `fallback_ready` without an HTTP call;
- native payable data already stored gives `native_ready` without an HTTP call;
- paid, expired, failed, refunded, or cancelled state gives `terminal`.

- [ ] **Step 3: Write failing fresh/stale `creating` tests**

Fresh `creating` within the claim lease returns `deferred`. Stale `creating` becomes `outcome_unknown`, never resets to `null`, never performs create again, returns `fallback_ready`, and schedules Transaction Detail recovery.

Existing rows with `provider_create_attempted_at` or non-null `provider_create_state` count as already attempted even when the new counter is zero.

Run: `cd src && php artisan test tests/Feature/PakasirCheckoutOrchestratorTest.php`

Expected: FAIL.

- [ ] **Step 4: Implement lock and state transitions**

Use a cache single-flight lock per payment with a lease longer than the 10-second HTTP timeout. Use short database transactions with row locks before and after HTTP. Never keep a database transaction open during provider HTTP or Telegram delivery.

The pre-HTTP transaction must atomically set:

```php
[
    'provider_create_state' => 'creating',
    'provider_create_attempted_at' => now(),
    'provider_create_attempts' => 1,
    'provider_next_check_at' => now()->addSeconds($claimLease),
]
```

The post-HTTP transaction must re-read terminal state before storing provider data.

- [ ] **Step 5: Implement deterministic decisions and lazy normalization**

Do not catch all `Throwable` as provider failure. Classify only typed provider transport or response failures as fallback-ready. Record `provider_last_http_status`, `provider_last_error_code`, `provider_check_attempts`, and `provider_next_check_at`. Apply deterministic jitter to pre-existing rows whose next check is null.

- [ ] **Step 6: Verify orchestration tests**

Run:

```bash
cd src
php artisan test tests/Feature/PakasirCheckoutOrchestratorTest.php tests/Feature/PakasirGatewayTest.php
```

Expected: PASS and all duplicate paths show exactly one create request.

- [ ] **Step 7: Commit**

```bash
git add src/app/Services/Pakasir/PakasirCheckoutDecisionType.php src/app/Services/Pakasir/PakasirCheckoutDecision.php src/app/Services/Pakasir/PakasirCheckoutOrchestrator.php src/app/Services/Pakasir/PakasirNativeQrisService.php src/app/Models/Payment.php src/tests/Feature/PakasirCheckoutOrchestratorTest.php src/tests/Support/PakasirNativeQrisScenarios.php
git commit -m "feat: orchestrate one-create Pakasir checkout"
```

### Task 4: Add the signed internal continue-payment route

**Files:**
- Create: `src/app/Storefront/PakasirContinuePaymentUrl.php`
- Create: `src/app/Http/Controllers/ContinuePakasirPaymentController.php`
- Modify: `src/routes/web.php:52-63`
- Modify: `src/app/Providers/AppServiceProvider.php:72-120`
- Test: `src/tests/Feature/PakasirContinuePaymentTest.php`

**Interfaces:**
- Produces: `PakasirContinuePaymentUrl::for(Order $order, Payment $payment): string`.
- Controller consumes route-bound `Order`, validates its latest matching Pakasir payment, and returns either a safe invoice redirect or `302` to `PakasirProviderAdapter::hostedPaymentUrl($payment)`.

- [ ] **Step 1: Write failing URL tests**

Assert the generated URL:

- uses route `storefront.shop.orders.continue-payment`;
- is temporary signed only until the earliest persisted order/payment/provider deadline;
- contains the internal application host;
- does not contain provider host, `/pay/`, provider project slug, amount, or `merchant_ref` in buyer-controlled query values.

- [ ] **Step 2: Write failing controller tests**

Cover valid redirect, invalid signature, expired signature, expired deadline, paid/failed/expired/refunded/cancelled payment, payment belonging to another order, non-Pakasir payment, and a repeated valid click. Prove the route creates no order, payment, reservation, or native provider request.

Run: `cd src && php artisan test tests/Feature/PakasirContinuePaymentTest.php`

Expected: FAIL.

- [ ] **Step 3: Implement route and rate limiter**

Add:

```php
Route::get('orders/{order}/continue-payment', ContinuePakasirPaymentController::class)
    ->middleware(['signed', 'throttle:pakasir-continue-payment'])
    ->name('orders.continue-payment');
```

Register a conservative named limiter in `AppServiceProvider`. The controller must read amount and `merchant_ref` only from the locked payment row. For terminal state, redirect to `StorefrontInvoiceUrl::show($order)` instead of the provider.

- [ ] **Step 4: Verify route tests**

Run: `cd src && php artisan test tests/Feature/PakasirContinuePaymentTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/app/Storefront/PakasirContinuePaymentUrl.php src/app/Http/Controllers/ContinuePakasirPaymentController.php src/routes/web.php src/app/Providers/AppServiceProvider.php src/tests/Feature/PakasirContinuePaymentTest.php
git commit -m "feat: add signed Pakasir payment continuation"
```

### Task 5: Render and deliver native, fallback, and deferred Telegram decisions

**Files:**
- Create: `src/app/Services/Telegram/TelegramPakasirFallbackInstruction.php`
- Modify: `src/app/Services/Telegram/TelegramPaymentInstructionRenderer.php:83-178`
- Modify: `src/app/Services/Telegram/TelegramCheckoutIntents.php:48-69,140-207`
- Modify: `src/app/Jobs/SendPakasirCheckoutInstructions.php:36-75`
- Modify test: `src/tests/Unit/Services/Telegram/TelegramPaymentInstructionRendererTest.php`
- Test: `src/tests/Feature/Telegram/PakasirCheckoutDeliveryTest.php`
- Modify test: `src/tests/Feature/Telegram/CartCheckoutTest.php`

**Interfaces:**
- Produces: `TelegramPaymentInstructionRenderer::pakasirFallback(Order $order, Payment $payment, string $continueUrl, string $locale): TelegramPakasirFallbackInstruction`.
- Produces: `TelegramCheckoutIntents::deliverPakasirDecision(string $chatId, Order $order, Payment $payment, PakasirCheckoutDecision $decision): void`.
- `SendPakasirCheckoutInstructions` consumes `PakasirCheckoutOrchestrator::prepare()` and delegates rendering only after receiving the typed decision.

- [ ] **Step 1: Write failing renderer tests**

Assert Indonesian and English fallback copy exactly matches the approved UX. Reply markup must contain **Lanjutkan Pembayaran** or **Continue Payment**, Check Payment, and Cancel Order. Assert no occurrence of `Pakasir`, `hosted`, provider host, `/pay/`, or project slug. Assert normal native rendering contains no continue-payment button.

- [ ] **Step 2: Write failing delivery idempotency tests**

Cover:

- native `sendPhoto` success fills `provider_native_qris_sent_at` only after Telegram success;
- native `sendPhoto` failure leaves the timestamp null and retry uses persisted QR without create;
- fallback `sendMessage` success fills `provider_fallback_sent_at` only after Telegram success;
- fallback send failure leaves the timestamp null;
- duplicate initial job does not send native or fallback twice;
- fallback delivery does not block a later recovered QR delivery;
- `deferred` releases or re-dispatches the job at `retryAt`, does not mark customer notified, and does not send fallback;
- `internal_failure` throws for queue retry and reports a sanitized error;
- `terminal` exits without delivery.

Run: `cd src && php artisan test tests/Feature/Telegram/PakasirCheckoutDeliveryTest.php`

Expected: FAIL.

- [ ] **Step 3: Replace broad exception fallback with typed decision rendering**

Remove `catch (Throwable)` in `TelegramCheckoutIntents::sendPakasirPaymentInstructions()` as the provider-failure classifier. The Telegram layer must never infer fallback eligibility itself.

Fallback message must show invoice, item summary, and deadline. Do not label the product total as final QRIS total. State that the final payable amount is displayed on the payment page.

- [ ] **Step 4: Separate initial-notification guard from native/fallback delivery guards**

Keep `manual_checkout_customer_notified_at` only as the initial instruction marker. Use `provider_native_qris_sent_at` and `provider_fallback_sent_at` for channel-specific idempotency. Update timestamps in short row-locked transactions after successful Telegram calls.

- [ ] **Step 5: Verify Telegram tests**

Run:

```bash
cd src
php artisan test tests/Unit/Services/Telegram/TelegramPaymentInstructionRendererTest.php tests/Feature/Telegram/PakasirCheckoutDeliveryTest.php tests/Feature/Telegram/CartCheckoutTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/app/Services/Telegram/TelegramPakasirFallbackInstruction.php src/app/Services/Telegram/TelegramPaymentInstructionRenderer.php src/app/Services/Telegram/TelegramCheckoutIntents.php src/app/Jobs/SendPakasirCheckoutInstructions.php src/tests/Unit/Services/Telegram/TelegramPaymentInstructionRendererTest.php src/tests/Feature/Telegram/PakasirCheckoutDeliveryTest.php src/tests/Feature/Telegram/CartCheckoutTest.php
git commit -m "feat: deliver safe Pakasir fallback instructions"
```

### Task 6: Centralize verified completion and preserve released orders

**Files:**
- Create: `src/app/Services/Pakasir/PakasirCompletionResult.php`
- Create: `src/app/Services/Pakasir/PakasirVerifiedCompletionProcessor.php`
- Create: `src/app/Services/Telegram/TelegramPakasirReleasedPaymentNotifier.php`
- Create: `src/app/Jobs/SendPakasirReleasedPaymentNotifications.php`
- Modify: `src/app/Services/Pakasir/PakasirTransaction.php`
- Modify: `src/app/Services/Pakasir/PakasirCallbackProcessor.php:25-72,103-133`
- Modify: `src/app/Enums/PaymentEventType.php`
- Test: `src/tests/Feature/PakasirReleasedPaymentTest.php`
- Modify test: `src/tests/Feature/PakasirWebhookTest.php`

**Interfaces:**
- Produces: `PakasirTransaction::completedAt(): ?CarbonImmutable`.
- Produces: `PakasirVerifiedCompletionProcessor::apply(Payment $payment, PakasirTransaction $transaction, PaymentEvent $event): PakasirCompletionResult`.
- `PakasirCompletionResult` identifies normal paid transition, timely payment detected after release, late payment, invalid completed time requiring review, already processed, and not completed.

- [ ] **Step 1: Write failing completed-time parser tests**

Test valid timezone normalization, missing `completed_at`, malformed `completed_at`, and mismatch between callback time and Transaction Detail time. Only Transaction Detail may supply the authoritative persisted value.

- [ ] **Step 2: Write failing released-payment lifecycle tests**

For expired and failed local payments, assert all of the following after verified completed detail:

- order and payment remain in their released terminal states;
- stock is not re-reserved;
- receipt, credentials, and sales proof are not dispatched;
- `released_payment_detected_at` and `provider_completed_at` are stored;
- completion before/equal deadline records `timely_payment_detected_after_release`;
- completion after deadline fills `late_payment_detected_at`;
- missing/invalid completed time records review-required evidence;
- new checkout order remains untouched;
- duplicate processing is idempotent.

- [ ] **Step 3: Write failing race and notification tests**

Use two database connections or the repository concurrency test pattern to race expiry and completion verification. If expiry obtains the terminal transition first, completion must not revive it. Admin and buyer notifications must each be sent at most once and their timestamps are set only after successful Telegram delivery.

Run: `cd src && php artisan test tests/Feature/PakasirReleasedPaymentTest.php`

Expected: FAIL.

- [ ] **Step 4: Implement one row-locked completion decision**

Inside one short transaction, lock the payment and order, re-read state, persist authoritative completion metadata, and choose either normal `OrderLifecycle::applyPaymentPaid()` or released review. Never call normal paid transition for an already expired/failed order or payment.

All receipt dispatch remains limited to a successful normal paid transition. Released review dispatches only `SendPakasirReleasedPaymentNotifications` after commit.

- [ ] **Step 5: Route callback and reconciliation processing through the common processor**

Replace direct `applyPaid()` calls in `PakasirCallbackProcessor` with `PakasirVerifiedCompletionProcessor::apply()`. Keep provider payload logging sanitized. Mark the `PaymentEvent` processed after the common processor has made an idempotent decision.

- [ ] **Step 6: Verify lifecycle tests**

Run:

```bash
cd src
php artisan test tests/Feature/PakasirReleasedPaymentTest.php tests/Feature/PakasirWebhookTest.php
composer test:concurrency
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/app/Services/Pakasir/PakasirCompletionResult.php src/app/Services/Pakasir/PakasirVerifiedCompletionProcessor.php src/app/Services/Pakasir/PakasirTransaction.php src/app/Services/Pakasir/PakasirCallbackProcessor.php src/app/Services/Telegram/TelegramPakasirReleasedPaymentNotifier.php src/app/Jobs/SendPakasirReleasedPaymentNotifications.php src/app/Enums/PaymentEventType.php src/tests/Feature/PakasirReleasedPaymentTest.php src/tests/Feature/PakasirWebhookTest.php
git commit -m "fix: preserve released orders on Pakasir completion"
```

### Task 7: Add unique recovery and asynchronous verification jobs

**Files:**
- Create: `src/app/Services/Pakasir/PakasirVerificationResult.php`
- Create: `src/app/Services/Pakasir/PakasirPaymentVerifier.php`
- Create: `src/app/Jobs/RecoverPakasirNativeQris.php`
- Create: `src/app/Jobs/VerifyPakasirPayment.php`
- Modify: `src/app/Http/Controllers/PakasirWebhookController.php:15-45`
- Modify: `src/app/Services/Pakasir/PakasirPaymentEventLogger.php`
- Modify: `src/app/Services/Telegram/TelegramOrderIntents.php`
- Modify: `src/app/Storefront/StorefrontShopPaymentStatusRefresh.php`
- Test: `src/tests/Feature/PakasirRecoveryTest.php`
- Modify test: `src/tests/Feature/PakasirWebhookTest.php`
- Modify test: `src/tests/Feature/StorefrontShopCheckoutTest.php`

**Interfaces:**
- Produces: `PakasirPaymentVerifier::verify(Payment $payment, PakasirRequestPriority $priority): PakasirVerificationResult`.
- `RecoverPakasirNativeQris` is unique by payment ID and performs at most one Transaction Detail request per execution.
- `VerifyPakasirPayment` is unique by payment ID and event/verification phase, and delegates completed state to `PakasirVerifiedCompletionProcessor`.

- [ ] **Step 1: Write failing recovery tests**

Cover:

- fallback delivery dispatches one unique recovery job after commit;
- duplicate dispatches produce one recovery chain;
- `outcome_unknown` detail containing QR and valid total becomes `created` and sends one recovered QR;
- detail `404` backs off without create;
- detail without QR keeps fallback active and backs off;
- completed detail processes payment and sends no recovered QR;
- terminal or expired payment stops recovery;
- one job execution performs at most one outbound HTTP request;
- QR recovery stops at the persisted Pakasir deadline.

- [ ] **Step 2: Write failing webhook ingress tests**

Assert the controller validates minimum shape, stores a sanitized event, dispatches one unique verification job, and returns success without calling Transaction Detail in the HTTP request. Duplicate webhook delivery may store duplicate evidence but must not create parallel verification chains.

- [ ] **Step 3: Implement the traffic-gated verifier**

The verifier must acquire the correct total/background permit, make one Transaction Detail request, parse and match project/order/amount/method, pass completed results to the common completion processor, and update `provider_check_attempts` plus `provider_next_check_at` for incomplete or transient outcomes.

- [ ] **Step 4: Implement recovery scheduling**

`RecoverPakasirNativeQris` calls `PakasirCheckoutOrchestrator::recover()`. On recovered native readiness, reuse Task 5 delivery and set `provider_native_qris_sent_at` only after Telegram success. On transient/deferred decisions, re-dispatch or release at the later of traffic slot, cooldown end, payment backoff, and deadline. Never call `createQrisTransaction()`.

- [ ] **Step 5: Move user-triggered checks onto shared verification**

Replace direct Transaction Detail calls in `TelegramOrderIntents` and `StorefrontShopPaymentStatusRefresh` with `PakasirPaymentVerifier`. Preserve existing single-flight and buyer throttle behavior, but also pass through the global traffic gate and common released-payment decision.

- [ ] **Step 6: Verify recovery and ingress tests**

Run:

```bash
cd src
php artisan test tests/Feature/PakasirRecoveryTest.php tests/Feature/PakasirWebhookTest.php tests/Feature/StorefrontShopCheckoutTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/app/Services/Pakasir/PakasirVerificationResult.php src/app/Services/Pakasir/PakasirPaymentVerifier.php src/app/Jobs/RecoverPakasirNativeQris.php src/app/Jobs/VerifyPakasirPayment.php src/app/Http/Controllers/PakasirWebhookController.php src/app/Services/Pakasir/PakasirPaymentEventLogger.php src/app/Services/Telegram/TelegramOrderIntents.php src/app/Storefront/StorefrontShopPaymentStatusRefresh.php src/tests/Feature/PakasirRecoveryTest.php src/tests/Feature/PakasirWebhookTest.php src/tests/Feature/StorefrontShopCheckoutTest.php
git commit -m "feat: recover Pakasir payments without create retry"
```

### Task 8: Replace full polling with due-row reconciliation and stale-claim dispatch

**Files:**
- Create: `src/app/Services/Pakasir/PakasirDuePaymentQuery.php`
- Modify: `src/app/Console/Commands/ReconcilePakasirPayments.php:14-101`
- Modify: `src/app/Console/Commands/ExpirePendingOrders.php:18-52`
- Modify: `src/routes/console.php`
- Test: `src/tests/Feature/PakasirReconciliationPerformanceTest.php`
- Modify test: `src/tests/Feature/PakasirWebhookTest.php:520-750`

**Interfaces:**
- Produces: `PakasirDuePaymentQuery::take(int $limit): Collection` ordered by customer-facing priority and `provider_next_check_at`.
- Reconciler consumes due rows only and stops when the background gate denies further work.

- [ ] **Step 1: Write failing due-query tests**

Seed pending native recovery, pending normal reconciliation, expired/failed late reconciliation, future rows, paid rows, stale `creating`, and legacy null-next-check rows. Assert only due Pakasir rows are selected, active recovery precedes normal pending, late checks come last, and selection is bounded by the command chunk.

- [ ] **Step 2: Write failing performance tests**

Prove:

- 100 released rows with only 4 due/background tokens produce no more than 4 outbound calls;
- one row produces at most one outbound request;
- scheduler does not load all eligible released payments every minute;
- active cooldown performs zero provider calls and does not enqueue an unbounded number of jobs;
- stale `creating` is normalized and recovered even if the original worker died before dispatch;
- 100 duplicate checkout callbacks still produce one order, one payment, one reservation, and one create.

Run: `cd src && php artisan test tests/Feature/PakasirReconciliationPerformanceTest.php`

Expected: FAIL.

- [ ] **Step 3: Implement indexed bounded selection**

Query `provider='pakasir'`, eligible statuses, and `provider_next_check_at <= now()` using the composite index. Legacy rows with null next-check enter gradually through deterministic jitter and a small per-run cap. Do not call `get()` for the entire released lookback set.

- [ ] **Step 4: Update expiry phase transition**

When Pakasir payment becomes expired/failed, reset `provider_check_attempts=0` and set `provider_next_check_at` to the first 5-minute late check. Do not change Binance Pay timing or lifecycle behavior.

- [ ] **Step 5: Update reconciliation command**

For each selected row, invoke the shared verifier once with the correct priority. Stop when background budget is exhausted. Persist the next check for incomplete, deferred, provider-failure, and terminal outcomes. Continue scheduling late checks only within the configured 72-hour lookback.

- [ ] **Step 6: Prove the due index is usable**

Add a PostgreSQL-aware test or documented local command that runs `EXPLAIN` for the due query and asserts the composite index name appears when the test database driver supports it. Skip only on SQLite with an explicit driver check.

- [ ] **Step 7: Verify reconciliation tests**

Run:

```bash
cd src
php artisan test tests/Feature/PakasirReconciliationPerformanceTest.php tests/Feature/PakasirWebhookTest.php
```

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add src/app/Services/Pakasir/PakasirDuePaymentQuery.php src/app/Console/Commands/ReconcilePakasirPayments.php src/app/Console/Commands/ExpirePendingOrders.php src/routes/console.php src/tests/Feature/PakasirReconciliationPerformanceTest.php src/tests/Feature/PakasirWebhookTest.php
git commit -m "perf: reconcile only due Pakasir payments"
```

### Task 9: Add sanitized observability and operational health signals

**Files:**
- Create: `src/app/Services/Pakasir/PakasirReliabilityTelemetry.php`
- Modify: `src/app/Services/Pakasir/PakasirClient.php`
- Modify: `src/app/Services/Pakasir/PakasirProviderTrafficGate.php`
- Modify: `src/app/Services/Pakasir/PakasirCheckoutOrchestrator.php`
- Modify: `src/app/Services/Pakasir/PakasirPaymentVerifier.php`
- Modify: `src/app/Console/Commands/HealthCheck.php`
- Modify: `src/config/services.php`
- Modify: `src/.env.example`
- Test: `src/tests/Feature/PakasirReliabilityTelemetryTest.php`
- Modify test: `src/tests/Feature/FoundationInfrastructureTest.php`

**Interfaces:**
- Produces: `PakasirReliabilityTelemetry::providerRequest(...)`, `checkoutDecision(...)`, `delivery(...)`, `recovery(...)`, and `releasedPayment(...)` methods with fixed sanitized context keys.
- Health check reads database/cache state only. It must not call Pakasir.

- [ ] **Step 1: Write failing redaction tests**

Capture logs and PaymentEvents for success, `429`, timeout, malformed payload, signed continue URL, and Telegram failure. Assert API key, QR string, raw provider payload, provider authorization data, full signed URL, product credentials, provider hosted URL, and project slug do not appear.

- [ ] **Step 2: Write failing counter and health tests**

Assert telemetry records native create success/failure, fallback sent, recovery success, request count by operation, deferred reason, duplicate suppression, late payment, and Telegram delivery failures. Add an `ops:health --pakasir` read-only output for cooldown state, due recovery count, oldest due age, and recent fallback/native counts without outbound requests.

- [ ] **Step 3: Implement structured telemetry**

Every provider event includes provider, operation, internal payment/correlation ID, request class, nullable HTTP status, sanitized error code, duration milliseconds, traffic-gate result, cooldown time, and recovery attempt. Use existing logging/health conventions instead of introducing a new external dependency.

- [ ] **Step 4: Add configurable alert thresholds**

Add config defaults for native success below 95% with minimum 20 samples, fallback above 10% with minimum 20 samples, five `429` events in five minutes, and oldest active recovery over five minutes. Health reporting may warn from stored counters but must never page or deploy automatically.

- [ ] **Step 5: Verify observability tests**

Run:

```bash
cd src
php artisan test tests/Feature/PakasirReliabilityTelemetryTest.php tests/Feature/FoundationInfrastructureTest.php
php artisan ops:health --db --queue-schema --queue-depth --pakasir
```

Expected: tests PASS and health command performs zero HTTP calls.

- [ ] **Step 6: Commit**

```bash
git add src/app/Services/Pakasir/PakasirReliabilityTelemetry.php src/app/Services/Pakasir/PakasirClient.php src/app/Services/Pakasir/PakasirProviderTrafficGate.php src/app/Services/Pakasir/PakasirCheckoutOrchestrator.php src/app/Services/Pakasir/PakasirPaymentVerifier.php src/app/Console/Commands/HealthCheck.php src/config/services.php src/.env.example src/tests/Feature/PakasirReliabilityTelemetryTest.php src/tests/Feature/FoundationInfrastructureTest.php
git commit -m "feat: observe Pakasir checkout reliability"
```

### Task 10: Run regression, security, and no-deploy handoff gates

**Files:**
- Modify only if a failing regression exposes a defect in Tasks 1-9.
- Review: `docs/superpowers/specs/2026-07-29-pakasir-native-qris-reliability-design.md`
- Review: `docs/ci-cd.md`
- Review: `.github/workflows/deploy-production.yml`

**Interfaces:**
- Consumes every interface produced by Tasks 1-9.
- Produces a local verification report and commit list. It does not produce a deployment.

- [ ] **Step 1: Run focused Pakasir and Telegram suites**

```bash
cd src
php artisan test tests/Feature/PakasirGatewayTest.php tests/Feature/PakasirWebhookTest.php tests/Feature/PakasirNativeQrisValidationCommandTest.php tests/Feature/PakasirReliabilitySchemaTest.php tests/Feature/PakasirCheckoutOrchestratorTest.php tests/Feature/PakasirContinuePaymentTest.php tests/Feature/PakasirRecoveryTest.php tests/Feature/PakasirReleasedPaymentTest.php tests/Feature/PakasirReconciliationPerformanceTest.php tests/Feature/PakasirReliabilityTelemetryTest.php tests/Feature/Telegram/PakasirCheckoutDeliveryTest.php tests/Feature/Telegram/CartCheckoutTest.php tests/Unit/Services/Pakasir tests/Unit/Services/Telegram/TelegramPaymentInstructionRendererTest.php
```

Expected: PASS.

- [ ] **Step 2: Run concurrency and full regression suites**

```bash
cd src
composer test:concurrency
composer test
```

Expected: PASS.

- [ ] **Step 3: Run formatting and infrastructure checks**

```bash
cd src
./vendor/bin/pint --test
php artisan ops:health --db --queue-schema --queue-depth --pakasir
```

Expected: PASS. If Pint reports differences, run `./vendor/bin/pint`, inspect the diff, then rerun `./vendor/bin/pint --test` and affected tests.

- [ ] **Step 4: Run explicit security assertions**

Search generated Telegram markup, application logs captured by tests, PaymentEvent payloads, and route tests for API keys, QR strings, provider hosted paths, project slug, and full signed URLs. Confirm fallback routes reject wrong order/payment ownership and terminal states.

- [ ] **Step 5: Confirm provider isolation and TTLs**

Run existing Binance tests and assert Binance default remains 20 minutes. Use `git diff --name-only` to confirm no KlikQRIS branch files or unrelated provider implementation files were changed. Confirm Pakasir default checkout deadline remains 10 minutes and a later provider expiry cannot extend it.

- [ ] **Step 6: Request code review**

Use `superpowers:requesting-code-review` with the design spec, this plan, commit range, and verification output. Resolve high and medium findings before handoff.

- [ ] **Step 7: Produce implementation handoff and stop**

Report:

- branch/worktree name;
- commits by task;
- schema additions;
- focused, concurrency, full-suite, formatting, security, and health results;
- known low-risk follow-ups;
- explicit statement that no SSH mutation, Docker production command, push, or deployment workflow was run.

Do not run `git push`, `docker compose`, SSH production commands, or `gh workflow run deploy-production.yml`. Wait for the owner's separate instruction.
