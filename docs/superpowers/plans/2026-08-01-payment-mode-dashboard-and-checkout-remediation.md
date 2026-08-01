# Payment Mode Dashboard and Checkout Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the dashboard the sole guarded Binance verification-mode control and remediate schema, canonical-host, and terminal invoice defects found by dogfooding.

**Architecture:** Persist the effective mode in `manual_payment_settings`, route dashboard activation through the existing canary evidence and cutover invariants, and remove environment mode selection. Add an idempotent forward migration for previously-applied partial schemas, canonicalize hosts in prepended middleware, and centralize method-aware terminal presentation.

**Tech Stack:** PHP 8.4, Laravel 12, PostgreSQL 16, SQLite tests, Blade, PHPUnit 12, Docker Compose.

## Global Constraints

- Existing payments retain their snapshotted verification mode.
- Order ID activation requires configured credentials, receiver identity, recent successful admin-canary evidence, and safe pending-legacy state.
- Returning to `amount_unique` is always available.
- `.env` does not select checkout behavior.
- Database repairs use a new additive migration; applied migrations are never edited again.
- Canonicalization does not replay unsafe HTTP methods across hosts.
- No real payment or production deployment is performed automatically.

---

### Task 1: Repair Previously Applied Binance Schema

**Files:**
- Create: `src/database/migrations/2026_08_01_000004_repair_binance_order_id_schema.php`
- Modify: `src/tests/Feature/Telegram/BinanceOrderIdSchemaTest.php`

**Interfaces:**
- Produces: all Binance fields required by `Payment` and `ManualPaymentSetting`, plus `payments_binance_pending_verification_lookup`.
- Preserves: existing rows and `amount_unique` as the legacy default.

- [ ] **Step 1: Write an upgrade regression test**

Create a historical partial schema by dropping the later-added columns/index after migration setup, invoke the new migration's `up()`, and assert the missing columns return while an existing pending payment remains `amount_unique`.

```php
$this->dropLaterBinanceSchemaElements();
(new RepairBinanceOrderIdSchema)->up();

$this->assertTrue(Schema::hasColumns('payments', [
    'binance_verification_mode',
    'binance_receiver_type',
    'binance_receiver_value',
    'binance_order_id_submission_version',
    'binance_verification_status',
]));
$this->assertTrue(Schema::hasColumns('manual_payment_settings', [
    'binance_verification_mode',
    'binance_order_id_compatibility_ready_at',
]));
```

- [ ] **Step 2: Run the schema test and verify RED**

Run: `php artisan test tests/Feature/Telegram/BinanceOrderIdSchemaTest.php`
Expected: FAIL because the repair migration does not exist.

- [ ] **Step 3: Implement the additive migration**

Use `Schema::hasColumn()` guards per field, add fields in portable groups, backfill null/invalid setting and payment modes to `amount_unique`, and create missing PostgreSQL/SQLite-compatible indexes by name. `down()` removes only objects introduced by this migration when safe.

- [ ] **Step 4: Run the schema test and verify GREEN**

Run: `php artisan test tests/Feature/Telegram/BinanceOrderIdSchemaTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/database/migrations/2026_08_01_000004_repair_binance_order_id_schema.php src/tests/Feature/Telegram/BinanceOrderIdSchemaTest.php
git commit -m "fix: repair upgraded Binance order ID schema"
```

### Task 2: Make Dashboard the Guarded Mode Control Plane

**Files:**
- Create: `src/app/Services/Binance/BinanceVerificationModeManager.php`
- Modify: `src/app/Http/Requests/Admin/UpdatePaymentModeRequest.php`
- Modify: `src/app/Http/Controllers/Admin/AdminPaymentModeController.php`
- Modify: `src/app/Services/Admin/AdminDashboardStatsData.php`
- Modify: `src/resources/views/admin/dashboard/stats-tab.blade.php`
- Modify: `src/tests/Feature/AdminDashboardPaymentModeTest.php`
- Modify: `src/tests/Feature/Telegram/BinanceOrderIdActivationTest.php`

**Interfaces:**
- Produces: `BinanceVerificationModeManager::change(ManualPaymentSetting $setting, string $mode): ManualPaymentSetting`.
- Throws: `InvalidArgumentException` for invalid mode and domain-specific activation failure with buyer-safe/admin-actionable text.
- Consumes: `BinanceOrderIdCanaryEvidence` and the existing pending legacy amount safety invariant.

- [ ] **Step 1: Write dashboard and manager tests**

Assert the dashboard renders both mode choices and effective readiness. Assert `order_id` is rejected without credentials, receiver, current canary evidence, or safe pending state; assert a valid canary activates it; assert `amount_unique` immediately rolls back; assert an existing payment snapshot does not change.

```php
$response->assertSee('Amount unik (legacy)')
    ->assertSee('Order ID');

$this->post(route('admin.payment-mode.update'), [
    'payment_mode' => 'pakasir',
    'pakasir_checkout_flow' => 'api_qris',
    'binance_verification_mode' => 'order_id',
])->assertSessionHasErrors('binance_verification_mode', errorBag: 'paymentMode');
```

- [ ] **Step 2: Run focused tests and verify RED**

Run: `php artisan test tests/Feature/AdminDashboardPaymentModeTest.php tests/Feature/Telegram/BinanceOrderIdActivationTest.php`
Expected: FAIL because the request, manager, controller, presenter, and Blade controls are absent.

- [ ] **Step 3: Implement the shared manager and dashboard controls**

Validate `binance_verification_mode` as `sometimes|string|in:amount_unique,order_id`. Have the controller lock the active setting and delegate mode mutation to the manager. Add audit old/new values. Add presenter keys for effective mode, activation availability, and guard message. Render two POST controls while preserving current QRIS mode, Binance enabled flag, and TTL values.

- [ ] **Step 4: Run focused tests and verify GREEN**

Run: `php artisan test tests/Feature/AdminDashboardPaymentModeTest.php tests/Feature/Telegram/BinanceOrderIdActivationTest.php tests/Feature/Telegram/BinanceCheckoutModeTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/app/Services/Binance/BinanceVerificationModeManager.php src/app/Http/Requests/Admin/UpdatePaymentModeRequest.php src/app/Http/Controllers/Admin/AdminPaymentModeController.php src/app/Services/Admin/AdminDashboardStatsData.php src/resources/views/admin/dashboard/stats-tab.blade.php src/tests/Feature/AdminDashboardPaymentModeTest.php src/tests/Feature/Telegram/BinanceOrderIdActivationTest.php
git commit -m "feat: manage Binance verification mode in dashboard"
```

### Task 3: Remove Environment Mode Selection and Duplicate Activation Control

**Files:**
- Modify: `src/app/Services/Checkout/BinancePayCheckoutIntent.php`
- Modify: `src/config/services.php`
- Modify: `docker-compose.yml`
- Modify: `src/.env.example`
- Modify: `.env.example`
- Modify: `.github/workflows/deploy-production.yml`
- Modify: `scripts/deploy-production-release.sh` only if it references the activation input
- Modify: `docs/ci-cd.md`
- Modify: `src/tests/Feature/Telegram/BinanceCheckoutModeTest.php`
- Modify: `src/tests/Feature/PaymentDocumentationTest.php`

**Interfaces:**
- `BinancePayCheckoutIntent::verificationMode(?Cart $cart = null): string` reads database state only, except the pre-activation admin canary path.
- No `BINANCE_VERIFICATION_MODE` environment contract remains.

- [ ] **Step 1: Write tests proving env cannot select mode**

Set `services.binance.verification_mode=order_id` with a database setting of `amount_unique` and assert a non-admin checkout stays `amount_unique`. Assert documentation/config no longer publishes `BINANCE_VERIFICATION_MODE` or the workflow activation input.

- [ ] **Step 2: Run focused tests and verify RED**

Run: `php artisan test tests/Feature/Telegram/BinanceCheckoutModeTest.php tests/Feature/PaymentDocumentationTest.php`
Expected: FAIL because the fallback and deployment input still exist.

- [ ] **Step 3: Remove fallback and duplicate control plane**

Default a missing database row to `amount_unique`; remove the service config key, Compose variable, examples, command-trigger workflow input/step, and command-oriented activation docs. Keep credential and receiver environment values.

- [ ] **Step 4: Run focused tests and verify GREEN**

Run: `php artisan test tests/Feature/Telegram/BinanceCheckoutModeTest.php tests/Feature/PaymentDocumentationTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/app/Services/Checkout/BinancePayCheckoutIntent.php src/config/services.php docker-compose.yml src/.env.example .env.example .github/workflows/deploy-production.yml scripts/deploy-production-release.sh docs/ci-cd.md src/tests/Feature/Telegram/BinanceCheckoutModeTest.php src/tests/Feature/PaymentDocumentationTest.php
git commit -m "refactor: remove Binance mode environment toggle"
```

### Task 4: Canonicalize Storefront Host Before Session

**Files:**
- Create: `src/app/Http/Middleware/RedirectToCanonicalHost.php`
- Modify: `src/bootstrap/app.php`
- Modify: `src/tests/Feature/FoundationInfrastructureTest.php`
- Modify: `src/tests/Feature/StorefrontShopCheckoutTest.php`

**Interfaces:**
- `RedirectToCanonicalHost::handle(Request $request, Closure $next): Response` redirects alternate-host safe requests to `config('app.url')` host while preserving path/query.
- Middleware is prepended globally before the web middleware group.

- [ ] **Step 1: Write canonical-host tests**

Assert `GET https://www.example.test/shop?search=ai` redirects permanently to `https://example.test/shop?search=ai`; canonical requests continue; trusted non-storefront operational hosts are not rewritten when configured; a canonicalized browser session can submit checkout without 419.

- [ ] **Step 2: Run tests and verify RED**

Run: `php artisan test tests/Feature/FoundationInfrastructureTest.php tests/Feature/StorefrontShopCheckoutTest.php --filter='canonical|alternate_host|csrf'`
Expected: FAIL because middleware is absent.

- [ ] **Step 3: Implement and prepend middleware**

Derive scheme/host/port from `config('app.url')`, redirect only `GET` and `HEAD`, preserve `getRequestUri()`, and call `$middleware->prepend(RedirectToCanonicalHost::class)` in `bootstrap/app.php`. Unsafe alternate-host requests return a non-replaying redirect/error response rather than forwarding POST data.

- [ ] **Step 4: Run tests and verify GREEN**

Run: `php artisan test tests/Feature/FoundationInfrastructureTest.php tests/Feature/StorefrontShopCheckoutTest.php --filter='canonical|alternate_host|csrf'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/app/Http/Middleware/RedirectToCanonicalHost.php src/bootstrap/app.php src/tests/Feature/FoundationInfrastructureTest.php src/tests/Feature/StorefrontShopCheckoutTest.php
git commit -m "fix: canonicalize storefront host before session"
```

### Task 5: Make Terminal Invoice Presentation Payment-Aware

**Files:**
- Create: `src/app/Storefront/StorefrontPaymentTerminalCopy.php`
- Modify: `src/app/Storefront/StorefrontShopSignedInvoice.php`
- Modify: `src/app/Storefront/StorefrontShopPaymentStatusRefresh.php`
- Modify: `src/resources/views/storefront/partials/shop/order-summary.blade.php`
- Modify: `src/tests/Feature/StorefrontShopCheckoutTest.php`
- Modify: `src/tests/Feature/StorefrontBinanceOrderIdTest.php`

**Interfaces:**
- Produces: `StorefrontPaymentTerminalCopy::message(OrderLifecycleProjection $projection, ?Payment $payment): string`.
- Produces: `StorefrontPaymentTerminalCopy::effectivePaymentStatus(Order $order, ?Payment $payment): string`.

- [ ] **Step 1: Write copy and status regressions**

Assert cancelled/expired QRIS mentions QRIS, cancelled/expired Binance mentions USDT/Binance and never QRIS, and cancelled order summary displays `cancelled` even when the payment record is retained as pending for financial reconciliation.

- [ ] **Step 2: Run tests and verify RED**

Run: `php artisan test tests/Feature/StorefrontShopCheckoutTest.php tests/Feature/StorefrontBinanceOrderIdTest.php --filter='cancelled|expired|terminal_copy|payment_status'`
Expected: FAIL on QRIS-specific Binance copy and blank/incorrect terminal label.

- [ ] **Step 3: Implement the presentation service**

Move duplicate status-message matches into the new service, branch on `method === 'BINANCE_PAY_ID'`, inject it into both invoice services, and pass an effective presentation status to the order summary without changing persisted provider truth.

- [ ] **Step 4: Run tests and verify GREEN**

Run: `php artisan test tests/Feature/StorefrontShopCheckoutTest.php tests/Feature/StorefrontBinanceOrderIdTest.php --filter='cancelled|expired|terminal_copy|payment_status'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/app/Storefront/StorefrontPaymentTerminalCopy.php src/app/Storefront/StorefrontShopSignedInvoice.php src/app/Storefront/StorefrontShopPaymentStatusRefresh.php src/resources/views/storefront/partials/shop/order-summary.blade.php src/tests/Feature/StorefrontShopCheckoutTest.php src/tests/Feature/StorefrontBinanceOrderIdTest.php
git commit -m "fix: render payment-aware terminal invoice copy"
```

### Task 6: Integrated Verification and Dogfooding

**Files:**
- Modify only if failures reveal a root-cause defect in files already listed.

**Interfaces:**
- Verifies all acceptance criteria; introduces no new contract.

- [ ] **Step 1: Run focused payment suites**

```bash
php artisan test \
  tests/Feature/AdminDashboardPaymentModeTest.php \
  tests/Feature/Telegram/BinanceOrderIdSchemaTest.php \
  tests/Feature/Telegram/BinanceOrderIdActivationTest.php \
  tests/Feature/Telegram/BinanceCheckoutModeTest.php \
  tests/Feature/Telegram/BinanceAutoVerifyTest.php \
  tests/Feature/Telegram/CartCheckoutTest.php \
  tests/Feature/StorefrontShopCheckoutTest.php \
  tests/Feature/StorefrontBinanceOrderIdTest.php \
  tests/Feature/PakasirGatewayTest.php \
  tests/Feature/PakasirWebhookTest.php \
  tests/Unit/Services/Pakasir/PakasirQrisFeePolicyTest.php \
  tests/Unit/Services/Pakasir/PakasirQrisTransactionTest.php
```

Expected: all pass.

- [ ] **Step 2: Run static/style and documentation checks**

```bash
./vendor/bin/pint --test
php artisan test tests/Feature/PaymentDocumentationTest.php tests/Feature/FoundationInfrastructureTest.php
```

Expected: all pass.

- [ ] **Step 3: Refresh the existing local stack**

```bash
APP_ENV=production docker compose -p telegram-bot-prod up -d --build --remove-orphans
```

Expected: no duplicate Compose project and all services become healthy.

- [ ] **Step 4: Run operational health checks**

```bash
docker compose -p telegram-bot-prod ps
docker compose -p telegram-bot-prod exec -T app php artisan ops:health --db --queue-schema --queue-depth --supervisor --pakasir
docker compose -p telegram-bot-prod exec -T scheduler php artisan ops:health --scheduler-heartbeat=180
```

Expected: healthy services, zero unsafe queue backlog, healthy scheduler, and no provider call from health.

- [ ] **Step 5: Dogfood Telegram then storefront**

Telegram: confirm dashboard exposes both modes; confirm missing canary prevents global activation; confirm admin canary checkout displays exact USDT plus Order ID prompt; create unpaid QRIS and verify base/fee/total. Storefront: begin at alternate host and verify canonical redirect, create unpaid QRIS and Binance orders, inspect method-aware copy, check pending status, cancel, and confirm stock release. Do not claim real Order ID payment success without a real transaction.

- [ ] **Step 6: Run public smoke without deploying**

```bash
BASE_URL=https://www.lhiestore.web.id SMOKE_PUBLIC_ONLY=true SMOKE_TIMEOUT=15 ./scripts/smoke-production.sh
```

Expected: public endpoints pass. Report that production still requires the manual `Deploy production` workflow from `main`.
