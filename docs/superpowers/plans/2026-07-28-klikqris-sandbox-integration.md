# KlikQRIS Sandbox Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a production-inert KlikQRIS sandbox payment path that creates native QRIS invoices for the LhieStore Testing Bot and local Web Storefront, verifies payment through authoritative status checks, and leaves Pakasir behavior unchanged.

**Architecture:** Build a self-contained `App\Services\KlikQris` module beside Pakasir. A non-production-only selector enables KlikQRIS sandbox for test checkout, while create, callback, status verification, reconciliation, Telegram delivery, and storefront presentation use provider-specific services behind narrow interfaces. Webhooks are treated as untrusted triggers; only a matching `SUCCESS` response from KlikQRIS status API may apply payment through the existing `OrderLifecycle`.

**Tech Stack:** PHP 8.3+, Laravel 13, Eloquent, Carbon, Laravel HTTP client/fakes, queues, scheduler, PHPUnit 12, SQLite fast tests, PostgreSQL concurrency/release gates, Telegram Bot API, KlikQRIS Sandbox API.

## Global Constraints

- Execute implementation in a worktree on branch `feature/klikqris-sandbox`; create it at execution time with the `using-git-worktrees` skill.
- Use LhieStore Testing Bot, a local/testing database, and KlikQRIS sandbox only.
- Never place real API keys, merchant credentials, signatures, raw QR base64, or secret headers in Git, logs, fixtures, payment events, admin output, or documentation. Tests may use clearly synthetic values such as `test-only-key` and `opaque-test-signature`.
- Do not add a KlikQRIS production base URL, production credential, production admin switch, production callback, or automatic cutover path.
- `main` and production continue to use their existing Pakasir/manual selection until the owner explicitly approves a separate production design.
- Pakasir classes, routes, callbacks, reconciliation, and observable behavior must remain supported and regression-tested.
- Telegram is the primary customer flow. It shows the QRIS image, exact provider `total_amount`, and a 15-minute deadline without redirecting the customer to a browser or Popup Snap Payment.
- Web Storefront remains a voluntary fallback and local test surface, not a mandatory redirect.
- Parse all provider monetary values without floating point. Accept only exact whole-rupiah values such as `1016`, `1016.0`, or `1016.00`; reject non-zero fractions.
- A webhook `PAID` value is only a trigger. Payment becomes paid only after status API returns matching `SUCCESS` data.
- Create is not automatically retried after an uncertain outcome. Check the original `order_id` first.
- Reconciliation runs every minute as webhook fallback.
- One QRIS lives for at most 15 minutes. After expiry, the old order/payment remains expired; ordering again revalidates stock and price and creates a new order, payment, `order_id`, signature, and QRIS.
- Use test-driven development. Every task must demonstrate RED, GREEN, focused regression tests, and a scoped commit.
- No new third-party PHP dependency is required.

---

## Execution Preflight

Before Task 1, invoke `using-git-worktrees` and create an isolated worktree for `feature/klikqris-sandbox`. In that worktree:

```bash
git status --short
git branch --show-current
cd src
php artisan test tests/Feature/PakasirGatewayTest.php tests/Feature/PakasirWebhookTest.php
```

Expected: clean worktree, branch `feature/klikqris-sandbox`, and the Pakasir baseline tests pass before KlikQRIS code is introduced. Do not copy the sandbox API key into the plan or shell history. Add it only to the worktree `.env` when real sandbox validation begins.

## File Structure

### New provider module

- Create `src/app/Services/KlikQris/KlikQrisProviderAdapter.php`: create/status contract.
- Create `src/app/Services/KlikQris/KlikQrisClient.php`: sandbox HTTP requests and retry policy.
- Create `src/app/Services/KlikQris/KlikQrisProviderReadiness.php`: non-production sandbox readiness gate.
- Create `src/app/Services/KlikQris/KlikQrisProviderReadinessReport.php`: immutable readiness result.
- Create `src/app/Services/KlikQris/KlikQrisSandboxCheckoutSelector.php`: explicit sandbox checkout selection.
- Create `src/app/Services/KlikQris/KlikQrisAmount.php`: exact whole-rupiah parsing and display preservation.
- Create `src/app/Services/KlikQris/KlikQrisTransaction.php`: normalized create/status transaction.
- Create `src/app/Services/KlikQris/KlikQrisCallback.php`: normalized callback trigger.
- Create `src/app/Services/KlikQris/KlikQrisPayloadSanitizer.php`: recursive secret and QR-base64 removal.
- Create `src/app/Services/KlikQris/KlikQrisNativeQrisService.php`: create idempotency, persistence, and outcome recovery.
- Create `src/app/Services/KlikQris/KlikQrisQrisImage.php`: QR image download and initial-response base64 fallback.
- Create `src/app/Services/KlikQris/KlikQrisPaymentEventLogger.php`: sanitized event logging and callback coalescing.
- Create `src/app/Services/KlikQris/KlikQrisCallbackProcessor.php`: callback-triggered authoritative verification.

### Checkout, Telegram, HTTP, and scheduler

- Create `src/app/Services/Checkout/KlikQrisCheckoutIntent.php`: IDR checkout intent with 15-minute expiry.
- Create `src/app/Jobs/SendKlikQrisCheckoutInstructions.php`: unique queued Telegram delivery.
- Create `src/app/Http/Controllers/KlikQrisSandboxWebhookController.php`: validated sandbox callback endpoint.
- Create `src/app/Console/Commands/ReconcileKlikQrisPayments.php`: one-minute reconciliation command.
- Create `src/app/Console/Commands/ValidateKlikQrisSandbox.php`: sanitized real-sandbox validation command.
- Modify `src/app/Services/Checkout/CheckoutPaymentIntents.php`: add `klikQris()` factory.
- Modify `src/app/Services/Checkout/CheckoutPostCreationBehavior.php`: add `KlikQrisInstructions`.
- Modify `src/app/Services/Telegram/Callbacks/TelegramCheckoutCallbackHandler.php`: select and dispatch KlikQRIS in sandbox.
- Modify `src/app/Services/Telegram/TelegramCheckoutIntents.php`: create and send KlikQRIS QR instructions.
- Modify `src/app/Services/Telegram/TelegramPaymentInstructionRenderer.php`: exact KlikQRIS caption and buttons.
- Modify `src/app/Services/Telegram/TelegramOrderIntents.php`: check KlikQRIS status on order detail.
- Modify `src/app/Services/Telegram/TelegramOrderExpiredNotifier.php`: provider-aware fresh-checkout button copy.
- Modify `src/app/Providers/AppServiceProvider.php`: bind `KlikQrisProviderAdapter` to `KlikQrisClient`.
- Modify `src/app/Bootstrap/ApplicationRateLimiterRegistrar.php`: add `klikqris-sandbox-webhook` limiter.
- Modify `src/routes/api.php`: add `/webhook/klikqris/sandbox`.
- Modify `src/routes/console.php`: schedule reconciliation every minute.

### Data and configuration

- Create `src/database/migrations/2026_07_28_000001_add_provider_transaction_token_to_payments.php`: additive encrypted token storage column.
- Modify `src/app/Models/Payment.php`: fillable/cast plus KlikQRIS payable helpers.
- Modify `src/config/services.php`: sandbox-only KlikQRIS config.
- Modify `src/.env.example`: variable names and safe defaults only.

### Web Storefront

- Modify `src/app/Storefront/StorefrontShopCheckout.php`: select KlikQRIS when sandbox selector is active.
- Modify `src/app/Storefront/StorefrontShopPaymentStatusRefresh.php`: provider-specific KlikQRIS create recovery and verification.
- Modify `src/app/Storefront/StorefrontShopSignedInvoice.php`: expose provider QR image source and exact total display.
- Modify `src/app/Storefront/StorefrontShopSignedInvoiceReadResult.php`: carry `qrisImageSource` and `payableAmountDisplay`.
- Modify `src/app/Storefront/StorefrontShopOrderPresenter.php`: pass new invoice fields to the view.
- Modify `src/resources/views/storefront/partials/shop/qris-payment.blade.php`: render URL/data URI safely and exact provider amount.
- Modify `src/app/Http/Controllers/Admin/AdminOrderDetailController.php`: build sanitized provider observability data.
- Modify `src/resources/views/admin/orders/show.blade.php`: render provider, amounts, create state, expiry, and event summaries without secrets.
- Modify `src/routes/web.php`: use a provider-neutral storefront status limiter name.

### Tests and runbook

- Create `src/tests/Support/KlikQrisSandboxScenarios.php`.
- Create `src/tests/Unit/Services/KlikQris/KlikQrisProviderReadinessTest.php`.
- Create `src/tests/Unit/Services/KlikQris/KlikQrisTransactionTest.php`.
- Create `src/tests/Feature/KlikQrisGatewayTest.php`.
- Create `src/tests/Feature/KlikQrisNativeQrisTest.php`.
- Create `src/tests/Feature/KlikQrisWebhookTest.php`.
- Create `src/tests/Feature/KlikQrisReconciliationTest.php`.
- Create `src/tests/Feature/Telegram/KlikQrisCheckoutTest.php`.
- Create `src/tests/Feature/Storefront/KlikQrisStorefrontTest.php`.
- Create `src/tests/Feature/KlikQrisAdminObservabilityTest.php`.
- Create `src/tests/Feature/KlikQrisSandboxValidationCommandTest.php`.
- Modify `src/tests/Feature/OrderCartDomainTest.php`: checkout intent regression.
- Modify `src/tests/Feature/PakasirGatewayTest.php`, `src/tests/Feature/PakasirWebhookTest.php`, and relevant storefront/Telegram Pakasir tests only to add explicit no-regression assertions where the new provider selector could affect them.
- Create `docs/klikqris-sandbox-validation.md`: secret-free setup and evidence checklist.

---

### Task 1: Add the sandbox-only configuration, readiness gate, and encrypted token column

**Files:**
- Create: `src/app/Services/KlikQris/KlikQrisProviderReadiness.php`
- Create: `src/app/Services/KlikQris/KlikQrisProviderReadinessReport.php`
- Create: `src/app/Services/KlikQris/KlikQrisSandboxCheckoutSelector.php`
- Create: `src/database/migrations/2026_07_28_000001_add_provider_transaction_token_to_payments.php`
- Create: `src/tests/Unit/Services/KlikQris/KlikQrisProviderReadinessTest.php`
- Modify: `src/config/services.php`
- Modify: `src/.env.example`
- Modify: `src/app/Models/Payment.php`

**Interfaces:**
- Produces `KlikQrisProviderReadiness::sandbox(): KlikQrisProviderReadinessReport`.
- Produces `KlikQrisProviderReadinessReport::isReady(): bool`, `problemLabels(): array`, `configurationMessage(): string`, and `assertReady(string $message): void`.
- Produces `KlikQrisSandboxCheckoutSelector::enabled(): bool`.
- Adds encrypted nullable `Payment::$provider_transaction_token`.
- Adds `Payment::isNativeKlikQris(): bool`, `isPayableNativeKlikQris(): bool`, `klikQrisQrisUrl(): ?string`, and `klikQrisTotalAmountDisplay(): ?string`.

- [ ] **Step 1: Write failing readiness and model tests**

Create tests covering missing credentials, disabled flag, production environment, wrong base URL, ready local sandbox, encrypted token storage, and payload-derived QR metadata:

```php
public function test_sandbox_is_ready_only_when_enabled_with_complete_sandbox_configuration_outside_production(): void
{
    config()->set('services.klikqris', [
        'sandbox_enabled' => true,
        'sandbox_base_url' => 'https://klikqris.com/api/sandbox',
        'sandbox_api_key' => 'test-only-key',
        'sandbox_merchant_id' => 'merchant-test',
        'qris_ttl_minutes' => 15,
    ]);

    $this->assertTrue(app(KlikQrisProviderReadiness::class)->sandbox()->isReady());
    $this->assertTrue(app(KlikQrisSandboxCheckoutSelector::class)->enabled());
}

public function test_production_environment_can_never_enable_sandbox_checkout(): void
{
    $this->app['env'] = 'production';
    config()->set('app.env', 'production');
    config()->set('services.klikqris.sandbox_enabled', true);
    config()->set('services.klikqris.sandbox_base_url', 'https://klikqris.com/api/sandbox');
    config()->set('services.klikqris.sandbox_api_key', 'test-only-key');
    config()->set('services.klikqris.sandbox_merchant_id', 'merchant-test');

    $report = app(KlikQrisProviderReadiness::class)->sandbox();

    $this->assertFalse($report->isReady());
    $this->assertContains('APP_ENV', $report->problemLabels());
    $this->assertFalse(app(KlikQrisSandboxCheckoutSelector::class)->enabled());
}
```

For the encrypted field, persist a `Payment`, assert the accessor returns the original test token, and use `DB::table('payments')->value('provider_transaction_token')` to assert plaintext is absent.

- [ ] **Step 2: Run the focused tests and verify RED**

```bash
cd src
php artisan test tests/Unit/Services/KlikQris/KlikQrisProviderReadinessTest.php
```

Expected: FAIL because readiness classes, config, migration, and model helpers do not exist.

- [ ] **Step 3: Add sandbox-only configuration**

Add this config block with no production URL:

```php
'klikqris' => [
    'sandbox_enabled' => (bool) env('KLIKQRIS_SANDBOX_ENABLED', false),
    'sandbox_base_url' => env('KLIKQRIS_SANDBOX_BASE_URL', 'https://klikqris.com/api/sandbox'),
    'sandbox_api_key' => env('KLIKQRIS_SANDBOX_API_KEY'),
    'sandbox_merchant_id' => env('KLIKQRIS_SANDBOX_MERCHANT_ID'),
    'qris_ttl_minutes' => (int) env('KLIKQRIS_QRIS_TTL_MINUTES', 15),
    'http_timeout_seconds' => (float) env('KLIKQRIS_HTTP_TIMEOUT_SECONDS', 10),
    'http_connect_timeout_seconds' => (float) env('KLIKQRIS_HTTP_CONNECT_TIMEOUT_SECONDS', 3),
    'status_max_attempts' => (int) env('KLIKQRIS_STATUS_MAX_ATTEMPTS', 3),
    'status_retry_delay_ms' => (int) env('KLIKQRIS_STATUS_RETRY_DELAY_MS', 250),
    'webhook_rate_limit_per_minute' => (int) env('KLIKQRIS_WEBHOOK_RATE_LIMIT_PER_MINUTE', 30),
    'status_refresh_rate_limit_per_minute' => (int) env('KLIKQRIS_STATUS_REFRESH_RATE_LIMIT_PER_MINUTE', 3),
],
```

Add only the corresponding variable names and safe defaults to `.env.example`.

- [ ] **Step 4: Implement the readiness report and selector**

`KlikQrisProviderReadiness::sandbox()` must reject:

- any environment outside `local` and `testing`;
- `sandbox_enabled !== true`;
- blank API key or merchant ID;
- non-HTTPS URLs;
- a host other than `klikqris.com`;
- a normalized path other than `/api/sandbox`;
- TTL outside 5 through 60 minutes.

`KlikQrisSandboxCheckoutSelector::enabled()` is exactly:

```php
public function enabled(): bool
{
    return $this->readiness->sandbox()->isReady();
}
```

- [ ] **Step 5: Add the additive migration and model helpers**

Migration:

```php
Schema::table('payments', function (Blueprint $table): void {
    $table->text('provider_transaction_token')->nullable()->after('provider_payment_number_hash');
});
```

Add `provider_transaction_token` to fillable fields and `'provider_transaction_token' => 'encrypted'` to casts. `isPayableNativeKlikQris()` must require pending status, provider `klikqris`, payment method `qris`, a nonblank sanitized QR URL, a final amount greater than or equal to the base amount, and an unexpired provider deadline.

- [ ] **Step 6: Run migration and focused tests**

```bash
cd src
php artisan migrate --env=testing --pretend
php artisan test tests/Unit/Services/KlikQris/KlikQrisProviderReadinessTest.php
```

Expected: migration SQL is generated without applying destructive schema resets, and the focused tests pass.

- [ ] **Step 7: Commit the readiness slice**

```bash
git add src/config/services.php src/.env.example src/app/Models/Payment.php \
  src/app/Services/KlikQris/KlikQrisProviderReadiness.php \
  src/app/Services/KlikQris/KlikQrisProviderReadinessReport.php \
  src/app/Services/KlikQris/KlikQrisSandboxCheckoutSelector.php \
  src/database/migrations/2026_07_28_000001_add_provider_transaction_token_to_payments.php \
  src/tests/Unit/Services/KlikQris/KlikQrisProviderReadinessTest.php
git commit -m "feat: gate KlikQRIS sandbox configuration"
```

---

### Task 2: Normalize KlikQRIS amounts, transactions, callbacks, and sanitized payloads

**Files:**
- Create: `src/app/Services/KlikQris/KlikQrisAmount.php`
- Create: `src/app/Services/KlikQris/KlikQrisTransaction.php`
- Create: `src/app/Services/KlikQris/KlikQrisCallback.php`
- Create: `src/app/Services/KlikQris/KlikQrisPayloadSanitizer.php`
- Create: `src/tests/Unit/Services/KlikQris/KlikQrisTransactionTest.php`

**Interfaces:**
- `KlikQrisAmount::from(mixed $value): self`; getters `rupiah(): int` and `display(): string`.
- `KlikQrisTransaction::fromPayload(array $payload): self` for create/status responses.
- `KlikQrisTransaction` getters: `orderId()`, `amount()`, `totalAmount()`, `totalAmountDisplay()`, `status()`, `qrisUrl()`, `qrisImageBase64()`, `signature()`, `expiresAt()`, `paidAt()`, `sanitizedPayload()`, `isPending()`, `isSuccess()`, and `assertMatches(Payment $payment): void`.
- `assertMatches()` always compares provider, `order_id`, and base amount. It compares `total_amount` only when the local payment already has `provider_total_payment`; this lets create responses validate identity before the final total is persisted while status responses validate the stored final total.
- `KlikQrisCallback::fromPayload(array $payload): self`; getters `orderId()`, `amount()`, `totalAmount()`, `status()`, `isPaidTrigger()`, and `sanitizedPayload()`.

- [ ] **Step 1: Write table-driven failing amount tests**

```php
public static function validWholeRupiahValues(): array
{
    return [
        'integer' => [1016, 1016, '1016'],
        'integer string' => ['1016', 1016, '1016'],
        'one decimal zero' => ['1016.0', 1016, '1016.0'],
        'two decimals zero' => ['1016.00', 1016, '1016.00'],
    ];
}
```

Assert rejection of `1016.01`, negative values, zero where a positive amount is required, exponent notation, commas, booleans, arrays, and floats that cannot preserve source precision.

- [ ] **Step 2: Write failing create/status parser tests**

Use a complete payload whose `data` contains `order_id`, `amount`, `total_amount`, `status`, `qris_url`, `qris_image`, `expired_at`, `paid_at`, and `signature`. Assert exact display preservation and that `sanitizedPayload()` removes `signature`, `qris_image`, keys containing `api_key`, `authorization`, `token`, and `secret` recursively while retaining `qris_url`, status, and amount strings.

Also test:

```php
$payment = new Payment([
    'provider' => 'klikqris',
    'merchant_ref' => 'STEST-123',
    'amount' => 1000,
    'provider_total_payment' => 1016,
]);

$transaction->assertMatches($payment);
```

Each mismatch for order ID, amount, total amount, and unsupported status must throw `UnexpectedValueException` with a field-specific message.

- [ ] **Step 3: Write failing callback parser tests**

Assert callback `PAID` parses as a trigger, while malformed `order_id`, invalid amounts, and unknown status fail closed. Sanitization must remove callback signature.

- [ ] **Step 4: Run tests and verify RED**

```bash
cd src
php artisan test tests/Unit/Services/KlikQris/KlikQrisTransactionTest.php
```

Expected: FAIL because the value objects and sanitizer do not exist.

- [ ] **Step 5: Implement exact parsing without floats**

Use a regular expression on integer/string input:

```php
if (! is_int($value) && ! is_string($value)) {
    throw new UnexpectedValueException('KlikQRIS amount must be an integer or decimal string.');
}

$raw = trim((string) $value);
if (preg_match('/^([1-9][0-9]*)(?:\.(0+))?$/', $raw, $matches) !== 1) {
    throw new UnexpectedValueException('KlikQRIS amount must be a positive whole-rupiah value.');
}
```

Reject overflow before casting. Preserve `$raw` as `display()` and cast the integer group only after checking it does not exceed `PHP_INT_MAX`.

- [ ] **Step 6: Implement transaction and callback normalization**

Normalize status to uppercase. Create/status transactions accept only `PENDING`, `SUCCESS`, or `EXPIRED`; callbacks accept `PENDING`, `PAID`, or `EXPIRED`. Parse timestamps with `CarbonImmutable` and normalize to application timezone only at persistence boundaries.

The transaction constructor must require a usable QR URL or base64 image only for `PENDING` create responses; `SUCCESS` status responses may return both as null.

- [ ] **Step 7: Run tests and commit**

```bash
cd src
php artisan test tests/Unit/Services/KlikQris/KlikQrisTransactionTest.php
git add app/Services/KlikQris/KlikQrisAmount.php \
  app/Services/KlikQris/KlikQrisTransaction.php \
  app/Services/KlikQris/KlikQrisCallback.php \
  app/Services/KlikQris/KlikQrisPayloadSanitizer.php \
  tests/Unit/Services/KlikQris/KlikQrisTransactionTest.php
git commit -m "feat: normalize KlikQRIS sandbox payloads"
```

---

### Task 3: Implement the sandbox HTTP client with asymmetric retry policy

**Files:**
- Create: `src/app/Services/KlikQris/KlikQrisProviderAdapter.php`
- Create: `src/app/Services/KlikQris/KlikQrisClient.php`
- Create: `src/tests/Feature/KlikQrisGatewayTest.php`
- Create: `src/tests/Support/KlikQrisSandboxScenarios.php`
- Modify: `src/app/Providers/AppServiceProvider.php`

**Interfaces:**

```php
interface KlikQrisProviderAdapter
{
    public function createTransaction(Payment $payment): KlikQrisTransaction;
    public function transactionStatus(Payment $payment): KlikQrisTransaction;
}
```

- [ ] **Step 1: Write failing request-contract tests**

For create, assert exactly one request to `/qris/create`, JSON content type, headers `x-api-key` and `id_merchant`, and body:

```php
[
    'order_id' => $payment->merchant_ref,
    'id_merchant' => 'merchant-test',
    'amount' => $payment->amount,
    'keterangan' => 'Payment '.$payment->merchant_ref,
]
```

For status, assert `GET /qris/status/{rawurlencoded-order-id}` with authentication headers and no credential in query parameters.

- [ ] **Step 2: Write failing retry-policy tests**

Use `Http::fakeSequence()` and assert:

- create connection/request failure results in one request only;
- status 500 then 200 results in two requests;
- status 429 then 200 retries;
- status 401 does not retry;
- invalid JSON and mismatched payload throw sanitized exceptions that contain HTTP status/class but not response secrets.

- [ ] **Step 3: Run tests and verify RED**

```bash
cd src
php artisan test tests/Feature/KlikQrisGatewayTest.php
```

Expected: FAIL because adapter/client and binding do not exist.

- [ ] **Step 4: Implement `KlikQrisClient`**

`createTransaction()` calls readiness, sends one `POST`, throws on HTTP errors, parses JSON into `KlikQrisTransaction`, and calls `assertMatches($payment)`. At this stage `assertMatches()` validates provider, order ID, and base amount; it does not require a stored final total because persistence happens in Task 5. Do not call Laravel `retry()` on create.

`transactionStatus()` calls readiness and uses `retry()` only when the exception is a connection error, HTTP 408, 429, or 5xx. Delay uses configured base delay with capped exponential growth.

- [ ] **Step 5: Bind the adapter and prove secrets are absent from logs/exceptions**

In `AppServiceProvider::register()`:

```php
$this->app->bind(KlikQrisProviderAdapter::class, KlikQrisClient::class);
```

Tests must assert thrown messages do not contain the configured API key, signature, response body, or merchant credential.

- [ ] **Step 6: Run focused tests and commit**

```bash
cd src
php artisan test tests/Feature/KlikQrisGatewayTest.php
git add app/Services/KlikQris/KlikQrisProviderAdapter.php \
  app/Services/KlikQris/KlikQrisClient.php \
  app/Providers/AppServiceProvider.php \
  tests/Feature/KlikQrisGatewayTest.php \
  tests/Support/KlikQrisSandboxScenarios.php
git commit -m "feat: add KlikQRIS sandbox client"
```

---

### Task 4: Add the KlikQRIS checkout intent without exposing an admin production switch

**Files:**
- Create: `src/app/Services/Checkout/KlikQrisCheckoutIntent.php`
- Modify: `src/app/Services/Checkout/CheckoutPaymentIntents.php`
- Modify: `src/app/Services/Checkout/CheckoutPostCreationBehavior.php`
- Modify: `src/tests/Feature/OrderCartDomainTest.php`

**Interfaces:**
- Produces `CheckoutPaymentIntents::klikQris(?CarbonInterface $deadline = null): KlikQrisCheckoutIntent`.
- `KlikQrisCheckoutIntent` returns provider `klikqris`, method `QRIS`, IDR currencies, sum-of-product-prices amount, readiness assertion, configured 15-minute expiry, and post-create behavior `KlikQrisInstructions`.

- [ ] **Step 1: Write failing intent tests**

```php
$intent = CheckoutPaymentIntents::klikQris($deadline);

$this->assertSame('klikqris', $intent->provider());
$this->assertSame('QRIS', $intent->method());
$this->assertSame('IDR', $intent->orderCurrency());
$this->assertSame('IDR', $intent->paymentCurrency());
$this->assertTrue($intent->expiresAt()->equalTo($deadline));
$this->assertSame(CheckoutPostCreationBehavior::KlikQrisInstructions, $intent->postCreationBehavior());
```

Add a checkout test that creates an order/payment with provider `klikqris`, amount equal to the current product price, and deadline 15 minutes ahead. Add a production-environment test that readiness rejects checkout.

- [ ] **Step 2: Run tests and verify RED**

```bash
cd src
php artisan test tests/Feature/OrderCartDomainTest.php --filter=klik_qris
```

Expected: FAIL because the factory, intent, and enum case do not exist.

- [ ] **Step 3: Implement the intent and factory**

`assertReady()` calls `KlikQrisProviderReadiness::sandbox()->assertReady()` and wraps failure in `InvalidCheckout` with a customer-safe message. `expiresAt()` uses explicit deadline first, then `now()->addMinutes(config('services.klikqris.qris_ttl_minutes', 15))`.

Do not add `klikqris` to `UpdatePaymentModeRequest`, `AdminPaymentModeController`, or the production admin dashboard.

- [ ] **Step 4: Run tests and Pakasir intent regression**

```bash
cd src
php artisan test tests/Feature/OrderCartDomainTest.php
```

Expected: all manual, Binance, Pakasir, and KlikQRIS intent tests pass.

- [ ] **Step 5: Commit**

```bash
git add src/app/Services/Checkout/KlikQrisCheckoutIntent.php \
  src/app/Services/Checkout/CheckoutPaymentIntents.php \
  src/app/Services/Checkout/CheckoutPostCreationBehavior.php \
  src/tests/Feature/OrderCartDomainTest.php
git commit -m "feat: add KlikQRIS sandbox checkout intent"
```

---

### Task 5: Make native QRIS creation idempotent and recover uncertain outcomes

**Files:**
- Create: `src/app/Services/KlikQris/KlikQrisNativeQrisService.php`
- Create: `src/app/Services/KlikQris/KlikQrisPaymentEventLogger.php`
- Create: `src/tests/Feature/KlikQrisNativeQrisTest.php`
- Modify: `src/tests/Support/KlikQrisSandboxScenarios.php`

**Interfaces:**
- Produces `KlikQrisNativeQrisService::ensureCreated(Payment $payment): KlikQrisTransaction`.
- Produces `KlikQrisPaymentEventLogger::logProviderCreate(Payment $payment, KlikQrisTransaction $transaction): PaymentEvent`.

- [ ] **Step 1: Write failing happy-path persistence test**

Create a KlikQRIS payment through `OrderCheckoutService`, fake create response, call `ensureCreated()`, then assert:

```php
$this->assertSame('created', $payment->fresh()->provider_create_state);
$this->assertSame(1016, $payment->fresh()->provider_total_payment);
$this->assertSame(16, $payment->fresh()->provider_fee);
$this->assertSame('qris', $payment->fresh()->provider_payment_method);
$this->assertSame('opaque-test-signature', $payment->fresh()->provider_transaction_token);
$this->assertNotNull($payment->fresh()->provider_expires_at);
$this->assertTrue($payment->fresh()->isPayableNativeKlikQris());
$this->assertArrayNotHasKey('signature', $payment->fresh()->provider_payload['data']);
$this->assertArrayNotHasKey('qris_image', $payment->fresh()->provider_payload['data']);
```

Assert the order deadline is shortened to provider expiry if provider expiry is earlier.

- [ ] **Step 2: Write failing idempotency and uncertain-outcome tests**

Cover:

- two sequential `ensureCreated()` calls send one create request;
- a simulated connection failure sets `outcome_unknown` and never sends a second create request;
- the next call uses status endpoint for the same `merchant_ref` and persists the recovered transaction;
- persisted payable QRIS returns without any provider request;
- invalid final amount remains non-payable;
- provider expiry later than local deadline does not extend the order.

Add a PostgreSQL-grouped concurrency test where two workers call `ensureCreated()` and only one provider create request/effect is observed.

- [ ] **Step 3: Run tests and verify RED**

```bash
cd src
php artisan test tests/Feature/KlikQrisNativeQrisTest.php
```

Expected: FAIL because native service and KlikQRIS event logger do not exist.

- [ ] **Step 4: Implement lock and persistence flow**

Use `Cache::lock('klikqris:native-qris:create:'.$payment->getKey(), 30)->block(10, ...)`. Inside the lock:

1. refresh payment;
2. return persisted payable transaction when available;
3. if state/payload exists, call status recovery before create;
4. set `creating` and `provider_create_attempted_at` inside a DB transaction;
5. call create once;
6. on any uncertain exception, set `outcome_unknown` and a sanitized exception class only;
7. persist transaction under `Order` and `Payment` row locks;
8. log one sanitized `ProviderCreate` event.

- [ ] **Step 5: Implement reconstruction from persisted payment**

Add `KlikQrisTransaction::fromPersistedPayment(Payment $payment): self` using the sanitized payload plus encrypted token. This method must not require base64 QR data and must refuse a payment whose provider is not `klikqris`.

- [ ] **Step 6: Run tests and commit**

```bash
cd src
php artisan test tests/Feature/KlikQrisNativeQrisTest.php
git add app/Services/KlikQris/KlikQrisNativeQrisService.php \
  app/Services/KlikQris/KlikQrisPaymentEventLogger.php \
  app/Services/KlikQris/KlikQrisTransaction.php \
  tests/Feature/KlikQrisNativeQrisTest.php \
  tests/Support/KlikQrisSandboxScenarios.php
git commit -m "feat: create KlikQRIS transactions idempotently"
```

---

### Task 6: Deliver native KlikQRIS directly in Telegram

**Files:**
- Create: `src/app/Services/KlikQris/KlikQrisQrisImage.php`
- Create: `src/app/Jobs/SendKlikQrisCheckoutInstructions.php`
- Create: `src/tests/Feature/Telegram/KlikQrisCheckoutTest.php`
- Modify: `src/app/Services/Telegram/TelegramPaymentInstructionRenderer.php`
- Modify: `src/app/Services/Telegram/TelegramCheckoutIntents.php`
- Modify: `src/app/Services/Telegram/Callbacks/TelegramCheckoutCallbackHandler.php`
- Modify: `src/app/Services/Telegram/TelegramOrderExpiredNotifier.php`

**Interfaces:**
- `KlikQrisQrisImage::contents(KlikQrisTransaction $transaction): string`.
- `TelegramCheckoutIntents::sendKlikQrisPaymentInstructions(string $chatId, Order $order, Payment $payment): void`.
- `TelegramPaymentInstructionRenderer::klikQris(Order $order, Payment $payment, string $photoContents, string $locale): TelegramPaymentInstruction`.

- [ ] **Step 1: Write failing Telegram checkout tests**

Activate sandbox selector, perform `checkout:current`, and assert:

- order payment provider is `klikqris`;
- `SendKlikQrisCheckoutInstructions` is dispatched on `telegram-outbound`;
- no Pakasir or manual instruction job is dispatched;
- sendPhoto contains the QR bytes;
- caption contains exact `IDR 1,016.00`, `Do not round or change any digit`, invoice, and 15-minute deadline;
- buttons are `order:{invoice}` for status and `buyer:cancel-pending:{order-id}` for cancellation;
- caption contains no hosted URL, redirect, Popup Snap, signature, API key, or raw base64.

- [ ] **Step 2: Write failing image-source tests**

Assert QR URL download is preferred. If download fails during the initial create response, decode a valid `data:image/png;base64,...` value. Reject non-PNG/JPEG bytes, oversized content, malformed base64, and URLs not on `klikqris.com` over HTTPS.

- [ ] **Step 3: Run tests and verify RED**

```bash
cd src
php artisan test tests/Feature/Telegram/KlikQrisCheckoutTest.php
```

Expected: FAIL because the selector is not wired into Telegram, job/image service/renderer do not exist, and enum dispatch has no KlikQRIS branch.

- [ ] **Step 4: Wire sandbox selection into Telegram checkout**

Inject `KlikQrisSandboxCheckoutSelector` into `TelegramCheckoutIntents`. `requestedPaymentMode()` returns `klikqris` only when selector is enabled; otherwise it returns the existing manual/Pakasir result unchanged. `paymentMode()` returns `klikqris` for that explicit selection and delegates existing Pakasir fallback logic for all other values.

In `TelegramCheckoutCallbackHandler::paymentIntent()` add:

```php
if ($paymentMode === 'klikqris') {
    return CheckoutPaymentIntents::klikQris();
}
```

Dispatch `SendKlikQrisCheckoutInstructions` for `CheckoutPostCreationBehavior::KlikQrisInstructions`.

- [ ] **Step 5: Implement the unique delivery job and renderer**

Mirror the claim/retry semantics of `SendPakasirCheckoutInstructions`, but query `provider = klikqris`. The renderer returns a `TelegramPaymentInstruction` with image filename `klikqris-{invoice}.png`, exact English warning copy, and status/cancel keyboard. It must use `$payment->klikQrisTotalAmountDisplay()` rather than reformatting the order total.

- [ ] **Step 6: Make expiry copy create a fresh checkout path**

For expired KlikQRIS orders, change the button label to `🔄 Buat QRIS Baru` / `🔄 Create New QRIS` while keeping callback `katalog`. The catalog route forces the customer to select available stock and checkout again, which creates a new order/payment/reference. Do not resurrect the expired order or cart.

- [ ] **Step 7: Run focused and Pakasir Telegram regressions**

```bash
cd src
php artisan test tests/Feature/Telegram/KlikQrisCheckoutTest.php \
  tests/Unit/Services/Telegram/TelegramPaymentInstructionRendererTest.php \
  tests/Feature/Telegram/WebhookIngressTest.php
```

Expected: PASS, including existing Pakasir/manual instruction assertions.

- [ ] **Step 8: Commit**

```bash
git add src/app/Services/KlikQris/KlikQrisQrisImage.php \
  src/app/Jobs/SendKlikQrisCheckoutInstructions.php \
  src/app/Services/Telegram/TelegramPaymentInstructionRenderer.php \
  src/app/Services/Telegram/TelegramCheckoutIntents.php \
  src/app/Services/Telegram/Callbacks/TelegramCheckoutCallbackHandler.php \
  src/app/Services/Telegram/TelegramOrderExpiredNotifier.php \
  src/tests/Feature/Telegram/KlikQrisCheckoutTest.php
git commit -m "feat: send KlikQRIS directly in Telegram"
```

---

### Task 7: Process sandbox callbacks as untrusted triggers with once-only fulfillment

**Files:**
- Create: `src/app/Services/KlikQris/KlikQrisCallbackProcessor.php`
- Create: `src/app/Http/Controllers/KlikQrisSandboxWebhookController.php`
- Create: `src/tests/Feature/KlikQrisWebhookTest.php`
- Modify: `src/app/Services/KlikQris/KlikQrisPaymentEventLogger.php`
- Modify: `src/app/Bootstrap/ApplicationRateLimiterRegistrar.php`
- Modify: `src/routes/api.php`

**Interfaces:**
- `KlikQrisCallbackProcessor::processCallback(array $payload): PaymentEvent`.
- `KlikQrisCallbackProcessor::processReconciliationForPayment(Payment $payment, KlikQrisTransaction $transaction): PaymentEvent`.
- Route: `POST /api/webhook/klikqris/sandbox` with `throttle:klikqris-sandbox-webhook`.

- [ ] **Step 1: Write failing callback verification tests**

Create a pending KlikQRIS payment. Post callback `PAID`, fake status `SUCCESS`, and assert payment/order become paid/completed and receipt/fulfillment jobs are queued once.

Then test:

- callback `PAID` plus status `PENDING` leaves payment pending;
- callback amount or total mismatch leaves payment pending;
- status response mismatch leaves payment pending;
- unknown `order_id` returns 200 and logs a sanitized unprocessed event;
- duplicate callbacks coalesce to one event with increased `received_count` and one fulfillment;
- callback signature is absent from persisted raw payload;
- malformed JSON/schema returns 400 or 422 and does not call status API;
- status API outage returns 200 after event logging and leaves reconciliation to recover later.

- [ ] **Step 2: Run tests and verify RED**

```bash
cd src
php artisan test tests/Feature/KlikQrisWebhookTest.php
```

Expected: FAIL because route, controller, processor, and callback coalescing do not exist.

- [ ] **Step 3: Implement event coalescing**

Use the existing PaymentEvent unique/idempotency pattern, scoped to provider `klikqris`. Hash the canonical sanitized callback JSON. Never hash or persist the signature because duplicate callbacks may use different opaque signature values while representing the same status event.

- [ ] **Step 4: Implement authoritative verification**

Processor flow:

```php
$callback = KlikQrisCallback::fromPayload($payload);
$payment = Payment::query()
    ->where('provider', 'klikqris')
    ->where('merchant_ref', $callback->orderId())
    ->first();
$event = $this->events->logCallback($callback, $payment);

if ($payment === null || ! $callback->isPaidTrigger()) {
    return $event;
}

$status = $this->client->transactionStatus($payment);
$status->assertMatches($payment);
if (! $status->isSuccess()) {
    return $event;
}

$this->orders->applyPaymentPaid($payment);
$event->update(['processed_at' => now()]);
```

Before the status call, require callback amount and total to match the stored payment. Catch transient provider failures at the controller boundary, report a sanitized exception, and still return `200` after the callback event is safely stored.

- [ ] **Step 5: Add route and rate limiter**

Limit by source IP using `services.klikqris.webhook_rate_limit_per_minute`. The controller validates content type/shape, invokes processor, and returns only:

```json
{"success":true}
```

It must not reveal whether an order exists.

- [ ] **Step 6: Run concurrency/idempotency tests and commit**

```bash
cd src
php artisan test tests/Feature/KlikQrisWebhookTest.php
git add app/Services/KlikQris/KlikQrisCallbackProcessor.php \
  app/Services/KlikQris/KlikQrisPaymentEventLogger.php \
  app/Http/Controllers/KlikQrisSandboxWebhookController.php \
  app/Bootstrap/ApplicationRateLimiterRegistrar.php routes/api.php \
  tests/Feature/KlikQrisWebhookTest.php
git commit -m "feat: verify KlikQRIS sandbox callbacks"
```

---

### Task 8: Add one-minute reconciliation and Telegram status refresh

**Files:**
- Create: `src/app/Console/Commands/ReconcileKlikQrisPayments.php`
- Create: `src/tests/Feature/KlikQrisReconciliationTest.php`
- Modify: `src/app/Services/Telegram/TelegramOrderIntents.php`
- Modify: `src/routes/console.php`

**Interfaces:**
- Command: `klikqris:reconcile {--chunk=100}`.
- Private Telegram refresh branch uses the same client and `processReconciliationForPayment()` as webhook verification.

- [ ] **Step 1: Write failing reconciliation tests**

Cover pending `SUCCESS`, pending `PENDING`, local expired but provider `SUCCESS`, already paid skip, non-KlikQRIS skip, provider outage counted as failed without aborting the chunk, and sanitized command output.

Assert command summary exactly includes `processed`, `skipped`, and `failed` counts without merchant reference, signature, or credential.

- [ ] **Step 2: Write failing Telegram status-button test**

Create a pending KlikQRIS order, fake status `SUCCESS`, invoke callback `order:{invoice}`, and assert status verification runs before order detail is rendered. Confirm the detail shows paid status and does not call Pakasir.

- [ ] **Step 3: Run tests and verify RED**

```bash
cd src
php artisan test tests/Feature/KlikQrisReconciliationTest.php \
  tests/Feature/Telegram/KlikQrisCheckoutTest.php
```

Expected: FAIL because command/schedule and Telegram KlikQRIS refresh branch do not exist.

- [ ] **Step 4: Implement bounded reconciliation**

Query provider `klikqris` and statuses pending/expired/failed plus `provider_create_state = outcome_unknown`. Use `chunkById()`. For each row, call status and processor. Do not call create from the reconciliation command except through the existing uncertain-outcome recovery rules.

- [ ] **Step 5: Schedule and wire Telegram status refresh**

Add:

```php
Schedule::command('klikqris:reconcile --chunk=100')->everyMinute()->withoutOverlapping();
```

In `TelegramOrderIntents`, branch on payment provider. Preserve the current Pakasir refresh method unchanged; add a KlikQRIS method using its own rate-limit key and the authoritative processor.

- [ ] **Step 6: Run tests and commit**

```bash
cd src
php artisan test tests/Feature/KlikQrisReconciliationTest.php \
  tests/Feature/Telegram/KlikQrisCheckoutTest.php
git add app/Console/Commands/ReconcileKlikQrisPayments.php \
  app/Services/Telegram/TelegramOrderIntents.php routes/console.php \
  tests/Feature/KlikQrisReconciliationTest.php \
  tests/Feature/Telegram/KlikQrisCheckoutTest.php
git commit -m "feat: reconcile KlikQRIS sandbox payments"
```

---

### Task 9: Add the voluntary Web Storefront fallback and provider-neutral status refresh limiter

**Files:**
- Create: `src/tests/Feature/Storefront/KlikQrisStorefrontTest.php`
- Create: `src/tests/Feature/KlikQrisAdminObservabilityTest.php`
- Modify: `src/app/Storefront/StorefrontShopCheckout.php`
- Modify: `src/app/Storefront/StorefrontShopPaymentStatusRefresh.php`
- Modify: `src/app/Storefront/StorefrontShopSignedInvoice.php`
- Modify: `src/app/Storefront/StorefrontShopSignedInvoiceReadResult.php`
- Modify: `src/app/Storefront/StorefrontShopOrderPresenter.php`
- Modify: `src/resources/views/storefront/partials/shop/qris-payment.blade.php`
- Modify: `src/app/Http/Controllers/Admin/AdminOrderDetailController.php`
- Modify: `src/resources/views/admin/orders/show.blade.php`
- Modify: `src/app/Bootstrap/ApplicationRateLimiterRegistrar.php`
- Modify: `src/routes/web.php`

**Interfaces:**
- Adds invoice fields `qrisImageSource: ?string` and `payableAmountDisplay: ?string`.
- Renames only the storefront limiter registration from Pakasir-specific to `automatic-payment-status-refresh`; the old limiter name may remain as a compatibility alias during this task.

- [ ] **Step 1: Write failing storefront checkout/render tests**

With sandbox selector enabled, submit local storefront QRIS checkout and assert provider `klikqris`, QR create occurs, invoice shows provider QR URL, exact `1,016.00`, fee, and deadline. Assert no hosted/Pakasir URL or Popup Snap script exists.

With selector disabled, assert existing Pakasir readiness/selection behavior is unchanged.

- [ ] **Step 2: Write failing status refresh and admin observability tests**

Assert:

- KlikQRIS pending payment refresh calls KlikQRIS status and becomes paid on matching `SUCCESS`;
- single-flight/recent-refresh behavior prevents duplicate provider requests;
- provider failure returns retryable JSON without leaking details;
- Pakasir refresh still uses Pakasir services;
- manual/Binance payment remains unsupported by automatic QRIS refresh.

In `KlikQrisAdminObservabilityTest`, open the authorized admin order detail and assert it shows provider `klikqris`, hashed or safely truncated merchant reference, base amount, exact final amount, `provider_create_state`, provider status, expiry, callback count, and latest reconciliation time. Assert the HTML contains no signature, API key, QR base64, or raw payload JSON.

- [ ] **Step 3: Run tests and verify RED**

```bash
cd src
php artisan test tests/Feature/Storefront/KlikQrisStorefrontTest.php \
  tests/Feature/KlikQrisAdminObservabilityTest.php
```

Expected: FAIL because storefront checkout/status/presentation only supports Pakasir native QRIS and the admin order detail lacks sanitized provider observability.

- [ ] **Step 4: Add selector-aware storefront checkout**

In `paymentIntent()`, after Binance handling and before Pakasir validation:

```php
if ($this->klikQrisSelector->enabled()) {
    return CheckoutPaymentIntents::klikQris();
}
```

After order creation, call `KlikQrisNativeQrisService::ensureCreated()` for KlikQRIS, mirroring the current native-Pakasir recovery semantics without changing the Pakasir branch.

- [ ] **Step 5: Add provider-aware invoice presentation**

For KlikQRIS payable payments, `qrisImageSource` is the sanitized HTTPS `qris_url`. For Pakasir payable payments, it remains the existing generated data URI. Render with:

```blade
<img src="{{ $qrisImageSource }}" alt="QRIS invoice {{ $order->invoice_number }}" ...>
```

Show `payableAmountDisplay` exactly for KlikQRIS. Continue using the existing IDR formatter for Pakasir/manual data.

- [ ] **Step 6: Add provider-aware status refresh, limiter alias, and safe admin observability**

Branch by `$payment->provider`. Both providers share the existing coordinator but invoke their own create recovery, client, and processor. Register `automatic-payment-status-refresh`, update the route middleware, and keep `pakasir-status-refresh` registered as an alias until all tests prove no hidden caller depends on it.

Load payment events for the authorized admin order detail and map them to bounded summaries containing event type, sanitized provider status, received/processed timestamps, and delivery count. Build payment detail rows with provider, a hash/truncated merchant reference, base/final amounts, create state, and expiry. Never pass `provider_transaction_token`, raw payload, QR base64, or credential configuration to the view.

- [ ] **Step 7: Run storefront and Pakasir regression tests**

```bash
cd src
php artisan test tests/Feature/Storefront/KlikQrisStorefrontTest.php \
  tests/Feature/KlikQrisAdminObservabilityTest.php \
  tests/Feature/PakasirGatewayTest.php \
  tests/Feature/AdminDashboardPaymentModeTest.php
```

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add src/app/Storefront/StorefrontShopCheckout.php \
  src/app/Storefront/StorefrontShopPaymentStatusRefresh.php \
  src/app/Storefront/StorefrontShopSignedInvoice.php \
  src/app/Storefront/StorefrontShopSignedInvoiceReadResult.php \
  src/app/Storefront/StorefrontShopOrderPresenter.php \
  src/resources/views/storefront/partials/shop/qris-payment.blade.php \
  src/app/Http/Controllers/Admin/AdminOrderDetailController.php \
  src/resources/views/admin/orders/show.blade.php \
  src/app/Bootstrap/ApplicationRateLimiterRegistrar.php src/routes/web.php \
  src/tests/Feature/Storefront/KlikQrisStorefrontTest.php \
  src/tests/Feature/KlikQrisAdminObservabilityTest.php
git commit -m "feat: support KlikQRIS in local storefront"
```

---

### Task 10: Harden expiry, late payment, and cross-provider regression behavior

**Files:**
- Modify: `src/tests/Feature/KlikQrisNativeQrisTest.php`
- Modify: `src/tests/Feature/KlikQrisWebhookTest.php`
- Modify: `src/tests/Feature/PakasirGatewayTest.php`
- Modify: `src/tests/Feature/PakasirWebhookTest.php`
- Modify: `src/tests/Feature/Telegram/KlikQrisCheckoutTest.php`
- Modify implementation files only where these tests expose missing behavior.

**Interfaces:**
- No new public interface. This task closes state-machine and regression gaps before live sandbox use.

- [ ] **Step 1: Add failing expiry tests**

Freeze time. Create a provider deadline 15 minutes ahead, run `orders:expire-pending` after deadline, and assert order/payment expired, inventory reservation released, old QR no longer payable, and Telegram expiry notification uses the fresh-checkout button.

- [ ] **Step 2: Add failing late-success tests**

After local expiry, return authoritative provider `SUCCESS`. Assert existing late-payment policy is applied through `OrderLifecycle`: no duplicate inventory fulfillment and no silent resurrection of an unavailable order. Match the established Pakasir late-payment semantics rather than inventing a new transition.

- [ ] **Step 3: Add cross-provider isolation tests**

Use the same `merchant_ref` value on a Pakasir fixture and a KlikQRIS fixture. Assert each callback/status query only finds its own provider row. Assert enabling KlikQRIS sandbox does not alter already-created Pakasir payments or their callback processing.

- [ ] **Step 4: Run focused tests and verify RED/GREEN**

```bash
cd src
php artisan test tests/Feature/KlikQrisNativeQrisTest.php \
  tests/Feature/KlikQrisWebhookTest.php \
  tests/Feature/PakasirGatewayTest.php \
  tests/Feature/PakasirWebhookTest.php \
  tests/Feature/Telegram/KlikQrisCheckoutTest.php
```

Expected before fixes: at least the newly added boundary tests fail. Make the smallest state/query changes necessary, then rerun until all pass.

- [ ] **Step 5: Commit the hardening slice**

```bash
git add src/app src/tests/Feature/KlikQrisNativeQrisTest.php \
  src/tests/Feature/KlikQrisWebhookTest.php \
  src/tests/Feature/PakasirGatewayTest.php \
  src/tests/Feature/PakasirWebhookTest.php \
  src/tests/Feature/Telegram/KlikQrisCheckoutTest.php
git commit -m "test: harden KlikQRIS payment boundaries"
```

Before committing, inspect `git diff --cached --name-only` and unstage unrelated `src/app` files; the final commit must contain only files changed to satisfy these tests.

---

### Task 11: Add a secret-safe sandbox validation command and operator runbook

**Files:**
- Create: `src/app/Console/Commands/ValidateKlikQrisSandbox.php`
- Create: `src/tests/Feature/KlikQrisSandboxValidationCommandTest.php`
- Create: `docs/klikqris-sandbox-validation.md`

**Interfaces:**
- Command: `klikqris:validate-sandbox {payment : Payment UUID or merchant_ref} {--create} {--reconcile}`.
- Exit success only when readiness passes, the payment is provider `klikqris`, stored/create/status fields match, QR is payable or paid as expected, and no sensitive value is printed.

- [ ] **Step 1: Write failing command tests**

Cover:

- missing configuration fails with labels only;
- production environment refuses execution;
- non-KlikQRIS payment is rejected;
- `--create` invokes idempotent creation;
- `--reconcile` invokes authoritative status processing;
- success output contains payment UUID, hashed merchant reference, normalized status, total display, and expiry;
- output never contains API key, merchant ID, signature, QR URL query secrets, raw QR base64, or full provider payload.

- [ ] **Step 2: Run tests and verify RED**

```bash
cd src
php artisan test tests/Feature/KlikQrisSandboxValidationCommandTest.php
```

Expected: FAIL because the command does not exist.

- [ ] **Step 3: Implement the validation command**

Resolve by UUID first, then exact merchant reference. Display merchant reference as `hash('sha256', $payment->merchant_ref)` truncated to 12 characters. Use readiness, native service, and callback processor rather than duplicating API logic.

- [ ] **Step 4: Write the runbook without credentials**

The runbook must include:

1. worktree/branch check;
2. required environment variable names only;
3. confirmation that Telegram username is the testing bot without printing token;
4. Cloudflare Tunnel callback URL pattern;
5. dashboard callback URL and 15-minute expiry settings;
6. Docker refresh command only after verifying the worktree `.env` is non-production and points to the testing bot;
7. create checkout in Telegram;
8. copy transaction signature from KlikQRIS dashboard and execute simulation there;
9. observe webhook and status verification;
10. duplicate-webhook, no-webhook reconciliation, expiry, and new-checkout scenarios;
11. evidence table with pass/fail and timestamps;
12. explicit statement that passing sandbox does not authorize production.

For local Docker application, use the existing project name and do not create a duplicate stack:

```bash
docker compose -p telegram-bot-prod up -d --build --remove-orphans
docker compose -p telegram-bot-prod ps
```

Then run exact health checks:

```bash
docker compose -p telegram-bot-prod exec -T app php artisan ops:health --db --queue-schema --queue-depth --supervisor
docker compose -p telegram-bot-prod exec -T scheduler php artisan ops:health --scheduler-heartbeat=180
```

Do not execute Docker commands until `.env` has been verified as the LhieStore Testing Bot and a non-production environment.

- [ ] **Step 5: Run tests, scan the runbook, and commit**

```bash
cd src
php artisan test tests/Feature/KlikQrisSandboxValidationCommandTest.php
cd ..
! grep -RInE 'sk_sandbox_[A-Za-z0-9]+|x-api-key:[[:space:]]*[A-Za-z0-9]+' docs/klikqris-sandbox-validation.md src/.env.example
git add src/app/Console/Commands/ValidateKlikQrisSandbox.php \
  src/tests/Feature/KlikQrisSandboxValidationCommandTest.php \
  docs/klikqris-sandbox-validation.md
git commit -m "docs: add KlikQRIS sandbox validation runbook"
```

---

### Task 12: Run release-quality verification without production activation

**Files:**
- Modify only files required by failures found in this task.
- Do not add production configuration or deploy workflow changes.

**Interfaces:**
- Produces a verified sandbox branch and a factual validation report. It does not produce a production release.

- [ ] **Step 1: Run formatter and focused KlikQRIS suite**

```bash
cd src
./vendor/bin/pint --test
php artisan test \
  tests/Unit/Services/KlikQris \
  tests/Feature/KlikQrisGatewayTest.php \
  tests/Feature/KlikQrisNativeQrisTest.php \
  tests/Feature/KlikQrisWebhookTest.php \
  tests/Feature/KlikQrisReconciliationTest.php \
  tests/Feature/Telegram/KlikQrisCheckoutTest.php \
  tests/Feature/Storefront/KlikQrisStorefrontTest.php \
  tests/Feature/KlikQrisAdminObservabilityTest.php \
  tests/Feature/KlikQrisSandboxValidationCommandTest.php
```

Expected: zero failures. If Pint reports formatting changes, run `./vendor/bin/pint`, inspect the diff, rerun `--test`, and commit formatting with the affected task rather than hiding it in a broad final commit.

- [ ] **Step 2: Run Pakasir and shared payment regressions**

```bash
cd src
php artisan test \
  tests/Feature/PakasirGatewayTest.php \
  tests/Feature/PakasirWebhookTest.php \
  tests/Feature/AdminDashboardPaymentModeTest.php \
  tests/Feature/AdminOrderPaymentOperationsTest.php \
  tests/Feature/OrderCartDomainTest.php \
  tests/Unit/Services/Telegram/TelegramPaymentInstructionRendererTest.php
```

Expected: zero failures and no changed Pakasir behavior.

- [ ] **Step 3: Run full automated gates**

```bash
cd src
composer test
composer audit
composer test:concurrency-gate
```

Expected: zero PHPUnit failures, no known vulnerable Composer dependency, and PostgreSQL concurrency gate success.

- [ ] **Step 4: Run configuration and route inspection**

```bash
cd src
php artisan route:list | grep -E 'klikqris|pakasir'
php artisan schedule:list | grep -E 'klikqris:reconcile|pakasir:reconcile|orders:expire-pending'
php artisan about --only=environment
! grep -RInE 'KLIKQRIS_(PRODUCTION|LIVE)|https://klikqris\.com/api/qris/create' app config routes .env.example
```

Expected:

- sandbox callback exists;
- both KlikQRIS and Pakasir reconciliation schedules exist;
- application environment is the intended non-production environment;
- no KlikQRIS production variable or live create URL exists in source configuration;
- no command in this step prints the configured sandbox API key or merchant credential.

- [ ] **Step 5: Perform real sandbox validation**

Follow `docs/klikqris-sandbox-validation.md` using LhieStore Testing Bot and the local tunnel. Record evidence for:

- Telegram QR create and readable image;
- exact provider total;
- simulated paid webhook;
- authoritative `SUCCESS` verification;
- exactly-once receipt/fulfillment;
- duplicate webhook;
- reconciliation with webhook intentionally omitted;
- 15-minute expiry;
- fresh checkout generating new references;
- create/status failure recovery;
- local storefront fallback.

Do not mark a scenario passed from automated fakes alone.

- [ ] **Step 6: Create a sandbox results note and stop before production**

Create `docs/klikqris-sandbox-results-2026-07-28.md` containing only sanitized evidence, test commands, pass/fail status, timestamps, and unresolved findings. End it with:

```text
Production authorization: NOT GRANTED.
Pakasir status: unchanged and still supported.
Next step: owner review before any production design or cutover work.
```

Commit only after real sandbox results exist:

```bash
git add docs/klikqris-sandbox-results-2026-07-28.md
git commit -m "test: record KlikQRIS sandbox validation"
```

- [ ] **Step 7: Verify final branch state**

```bash
git status --short
git log --oneline --decorate -15
git diff main...HEAD --stat
git diff main...HEAD -- src/app/Services/Pakasir src/app/Http/Controllers/PakasirWebhookController.php src/routes/api.php
```

Expected: clean worktree; a sequence of scoped commits; Pakasir provider files unchanged unless an explicitly reviewed regression-only change was necessary; route diff only adds the sandbox callback while retaining Pakasir callback; no production deployment has occurred.

---

## Requirement-to-Task Coverage

- Sandbox-only readiness and production refusal: Tasks 1, 4, 11, 12.
- Exact amount parsing/display: Tasks 2, 5, 6, 9.
- Create/status API contract and retry asymmetry: Tasks 3, 5.
- Idempotent create and outcome recovery: Task 5.
- Telegram native QR without redirect/Snap: Task 6.
- Untrusted webhook plus authoritative status verification: Task 7.
- Duplicate callback and exactly-once fulfillment: Tasks 7, 10.
- One-minute reconciliation and manual status check: Task 8.
- Local storefront fallback and sanitized admin observability: Task 9.
- Fifteen-minute expiry and fresh checkout: Tasks 6, 10.
- Secret sanitization and safe operator tooling: Tasks 2, 3, 7, 11.
- Pakasir isolation/regression: Tasks 4, 6, 9, 10, 12.
- Real sandbox evidence and explicit production stop: Tasks 11, 12.
