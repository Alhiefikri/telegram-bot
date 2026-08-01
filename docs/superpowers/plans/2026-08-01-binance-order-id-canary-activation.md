# Binance Order ID Canary Activation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make a real admin-only Order ID checkout possible before global readiness and require its persisted success evidence before production activation.

**Architecture:** Checkout mode selection receives the locked cart and recognizes only the configured Telegram admin as a pre-readiness canary. A focused evidence service validates a recent, fully paid canary before the existing transactional activation changes global settings.

**Tech Stack:** PHP 8.4, Laravel 12, PHPUnit 11, PostgreSQL 16, GitHub Actions, Docker Compose.

## Global Constraints

- Non-admin buyers remain on `amount_unique` until global readiness is recorded.
- Admin canary uses exact amount and the full buyer-visible **I've Paid** / Order ID flow.
- Activation requires database evidence, API/receiver configuration, CI, public smoke, and queue health.
- Existing legacy payments never change their snapshotted mode or amount.
- Production deploy remains manual from `main` through `Deploy production`.

---

### Task 1: Admin-Only Pre-Activation Canary Selection

**Files:**
- Modify: `src/app/Services/Checkout/BinancePayCheckoutIntent.php`
- Modify: `src/app/Services/OrderCheckoutService.php`
- Test: `src/tests/Feature/Telegram/BinanceCheckoutModeTest.php`
- Test: `src/tests/Feature/Telegram/CartCheckoutTest.php`

**Interfaces:**
- Produces: `BinancePayCheckoutIntent::verificationMode(?Cart $cart = null): string`.
- Consumes: `config('services.telegram.admin_user_id')` and the cart customer relationship.

- [ ] **Step 1: Write failing admin/non-admin canary tests**

```php
config()->set('services.telegram.admin_user_id', '777');
$admin = Customer::factory()->create(['telegram_id' => 777]);
$payment = $this->checkoutFor($admin)->payments()->sole();
$this->assertSame('order_id', $payment->binance_verification_mode);
$this->assertSame(3_250_000, $payment->amount);
```

Add a non-admin checkout with identical product price that remains
`amount_unique`, plus a Telegram assertion that only the admin canary receives
the **I've Paid** button and Order ID instructions.

- [ ] **Step 2: Verify canary tests fail in legacy mode**

Run: `cd src && php artisan test tests/Feature/Telegram/BinanceCheckoutModeTest.php tests/Feature/Telegram/CartCheckoutTest.php --filter='canary'`
Expected: FAIL because pre-readiness admin checkout is currently `amount_unique`.

- [ ] **Step 3: Pass the locked cart into mode selection and recognize the admin**

```php
public function verificationMode(?Cart $cart = null): string
{
    if ($this->isAdminCanary($cart)) {
        return 'order_id';
    }
    // Existing readiness/global selection follows unchanged.
}
```

Require configured Binance API credentials and receiver for canary mode; otherwise preserve legacy mode.

- [ ] **Step 4: Run Binance checkout and Telegram tests**

Run: `cd src && php artisan test tests/Feature/Telegram/BinanceCheckoutModeTest.php tests/Feature/Telegram/CartCheckoutTest.php`
Expected: PASS.

- [ ] **Step 5: Commit canary selection**

```bash
git add src/app/Services/Checkout/BinancePayCheckoutIntent.php src/app/Services/OrderCheckoutService.php src/tests/Feature/Telegram/BinanceCheckoutModeTest.php src/tests/Feature/Telegram/CartCheckoutTest.php
git commit -m "feat: add admin Binance Order ID canary"
```

### Task 2: Persisted Canary Evidence Gate

**Files:**
- Create: `src/app/Services/Binance/BinanceOrderIdCanaryEvidence.php`
- Modify: `src/app/Console/Commands/MarkBinanceOrderIdReady.php`
- Test: `src/tests/Feature/Telegram/BinanceOrderIdActivationTest.php`

**Interfaces:**
- Produces: `BinanceOrderIdCanaryEvidence::assertSuccessfulRecentAdminCanary(): Payment`.
- Consumes: paid `Payment`, related `Order` and `Customer`, configured admin ID, and `binance.canary_max_age_hours`.

- [ ] **Step 1: Replace the permissive activation test with failing evidence cases**

Add one test per missing condition: no canary, wrong customer, legacy mode, pending payment, missing submitted Order ID, missing provider reference, missing receiver snapshot, non-matched verification status, and stale completion. Each command invocation must fail and leave readiness null.

Add a success fixture with `order_id` mode, paid payment/order, completed order, submitted Order ID, provider reference, receiver snapshot, `confirmed` verification status, and recent `paid_at`.

- [ ] **Step 2: Verify activation still succeeds without evidence**

Run: `cd src && php artisan test tests/Feature/Telegram/BinanceOrderIdActivationTest.php --filter='canary|readiness_command'`
Expected: FAIL because the current command activates with only `--confirm-canary`.

- [ ] **Step 3: Implement the read-only evidence query before the transaction**

```php
$payment = Payment::query()
    ->where('binance_verification_mode', 'order_id')
    ->where('status', PaymentStatus::Paid->value)
    ->where('binance_verification_status', 'confirmed')
    ->whereNotNull('binance_order_id')
    ->whereNotNull('provider_reference')
    ->where('paid_at', '>=', now()->subHours($maxAge))
    ->whereHas('order.customer', fn ($query) => $query->where('telegram_id', $adminId))
    ->latest('paid_at')
    ->first();
```

Validate completed order and nonblank receiver type/value after loading the candidate. Throw a sanitized `RuntimeException` when evidence is absent.

- [ ] **Step 4: Run activation and concurrency tests**

Run: `cd src && php artisan test tests/Feature/Telegram/BinanceOrderIdActivationTest.php tests/Feature/ProductionConcurrencyTest.php`
Expected: PASS.

- [ ] **Step 5: Commit evidence-backed activation**

```bash
git add src/app/Services/Binance/BinanceOrderIdCanaryEvidence.php src/app/Console/Commands/MarkBinanceOrderIdReady.php src/tests/Feature/Telegram/BinanceOrderIdActivationTest.php src/tests/Feature/ProductionConcurrencyTest.php
git commit -m "fix: require Binance canary evidence for activation"
```

### Task 3: Binance Production Operations and Release Gates

**Files:**
- Modify: `src/config/services.php`
- Modify: `.env.example`
- Modify: `src/.env.example`
- Modify: `docker-compose.yml`
- Modify: `src/app/Bootstrap/ProductionConfigurationValidator.php`
- Modify: `src/app/Console/Commands/HealthCheck.php`
- Modify: `.github/workflows/deploy-production.yml`
- Modify: `docs/ci-cd.md`
- Test: `src/tests/Feature/FoundationInfrastructureTest.php`
- Test: `src/tests/Feature/Telegram/BinanceOrderIdSchemaTest.php`
- Test: `scripts/test-deploy-production-release.sh`

**Interfaces:**
- Consumes: canary evidence service and existing protected activation command.
- Produces: configurable `BINANCE_ORDER_ID_CANARY_MAX_AGE_HOURS` with a 24-hour default and observable canary readiness health.

- [ ] **Step 1: Write failing configuration, health, and workflow harness tests**

Assert invalid max age fails production configuration; health reports global mode plus whether recent admin canary evidence exists without exposing identifiers; workflow activation remains after public smoke and fails the release if evidence-backed activation fails.

- [ ] **Step 2: Verify the release-focused tests fail**

Run: `cd src && php artisan test tests/Feature/FoundationInfrastructureTest.php tests/Feature/Telegram/BinanceOrderIdSchemaTest.php && cd .. && ./scripts/test-deploy-production-release.sh`
Expected: FAIL on missing canary age/health contract.

- [ ] **Step 3: Add configuration, sanitized health output, and operator sequence**

Document: deploy candidate; perform admin canary checkout; pay exact amount; tap **I've Paid**; submit Order ID; verify delivery; rerun protected workflow with `mark_binance_order_id_ready=true`; verify new non-admin checkout uses exact amount.

- [ ] **Step 4: Run Binance and release suites**

Run: `cd src && php artisan test tests/Feature/Telegram/BinanceCheckoutModeTest.php tests/Feature/Telegram/BinanceOrderIdActivationTest.php tests/Feature/Telegram/BinanceAutoVerifyTest.php tests/Feature/FoundationInfrastructureTest.php tests/Feature/ProductionConcurrencyTest.php && cd .. && ./scripts/test-deploy-production-release.sh`
Expected: PASS.

- [ ] **Step 5: Commit Binance production operations**

```bash
git add src/config/services.php .env.example src/.env.example docker-compose.yml src/app/Bootstrap/ProductionConfigurationValidator.php src/app/Console/Commands/HealthCheck.php .github/workflows/deploy-production.yml docs/ci-cd.md src/tests/Feature/FoundationInfrastructureTest.php src/tests/Feature/Telegram/BinanceOrderIdSchemaTest.php scripts/test-deploy-production-release.sh
git commit -m "chore: gate Binance activation on production canary"
```
