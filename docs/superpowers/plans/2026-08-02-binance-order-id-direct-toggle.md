# Binance Order ID Direct Toggle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the owner switch Binance verification directly between Order ID and sequential Amount unik without an admin canary payment.

**Architecture:** `BinanceVerificationModeManager` will persist the selected mode immediately after validating only Order ID credentials. Checkout will read that selected mode directly. Existing payments preserve their stored mode; the existing broad pending-Binance unique index and cross-mode sequential allocator continue preventing collisions.

**Tech Stack:** PHP 8.4, Laravel 12, Eloquent, PHPUnit 11, PostgreSQL/MySQL, Blade, Docker Compose.

## Global Constraints

- `order_id` activation requires Binance API key, API secret, and a configured Pay ID or UID; no successful payment is required.
- `amount_unique` remains deterministic: exact catalog amount when free, then increments by `1_000` micro-USDT (`0.001` USDT).
- Existing payments retain their snapshotted mode, amount, receiver, and lifecycle after any toggle.
- The existing partial unique index remains broad across all pending automatic Binance payments.
- The sequential allocator must continue treating pending automatic Order ID and amount-unique payments as occupied.
- Remove all runtime/UI/configuration/docs/test dependencies on the admin canary and readiness marker.
- Do not destructively drop the historical readiness-marker column or migrations.
- Do not change Order ID submission, verification, retry, or delivery behavior.

---

## File Structure

- `src/app/Services/Binance/BinanceVerificationModeManager.php` — direct credential-gated mode persistence.
- `src/app/Services/Checkout/BinancePayCheckoutIntent.php` — selects the active dashboard mode without customer/admin canary branching.
- `src/app/Services/Admin/AdminDashboardStatsData.php` and `src/app/Storefront/StorefrontProductPresenter.php` — expose selected mode without readiness-marker state/copy.
- `src/resources/views/admin/dashboard/stats-tab.blade.php` — dashboard labels explain direct credential-gated toggle instead of canary readiness.
- `src/app/Services/Binance/BinanceOrderIdCanaryEvidence.php` and `src/app/Services/Binance/BinanceOrderIdActivation.php` — delete; no runtime needs canary evidence or guarded activation.
- `src/app/Console/Commands/HealthCheck.php`, `src/app/Bootstrap/ProductionConfigurationValidator.php`, `src/config/services.php`, `.env.example`, `src/.env.example`, `docker-compose.yml`, `docs/ci-cd.md` — remove canary operational surface.
- `src/tests/Feature/AdminDashboardPaymentModeTest.php`, `src/tests/Feature/Telegram/BinanceCheckoutModeTest.php`, `src/tests/Feature/FoundationInfrastructureTest.php` — replace canary contract coverage with direct-toggle tests.

### Task 1: Direct Dashboard Mode Policy and Checkout Selection

**Files:**
- Modify: `src/app/Services/Binance/BinanceVerificationModeManager.php:9-44`
- Modify: `src/app/Services/Checkout/BinancePayCheckoutIntent.php:25-50`
- Modify: `src/tests/Feature/AdminDashboardPaymentModeTest.php:152-230`
- Modify: `src/tests/Feature/Telegram/BinanceCheckoutModeTest.php:49-105`

**Interfaces:**
- Consumes: `BinanceVerificationModeManager::change(ManualPaymentSetting $setting, string $mode): ManualPaymentSetting`, `BinancePayCheckoutIntent::verificationMode(?Cart $cart = null): string`.
- Produces: owner-selected `order_id` mode immediately after credential validation; every buyer's new checkout uses the selected valid mode.

- [ ] **Step 1: Replace failing canary tests with direct-toggle behavior**

In `AdminDashboardPaymentModeTest`, replace the successful-canary fixture with an owner request that has valid API key, secret, and Pay ID but no customer/order/payment canary. Assert the dashboard POST succeeds and persists `binance_verification_mode = order_id`.

Keep the missing-credential tests, asserting the mode remains `amount_unique` and the error is scoped to `binance_verification_mode`.

In `BinanceCheckoutModeTest`, replace the admin-canary and non-admin-pre-readiness cases with one non-admin cart using a setting of `order_id`, valid credentials, and no readiness timestamp. Assert:

```php
$this->assertSame('order_id', $payment->binance_verification_mode);
$this->assertSame(3_250_000, $payment->amount);
$this->assertSame('awaiting_submission', $payment->binance_verification_status);
```

- [ ] **Step 2: Run direct-toggle tests to verify current canary gate fails**

Run:

```bash
cd src && php artisan test tests/Feature/AdminDashboardPaymentModeTest.php tests/Feature/Telegram/BinanceCheckoutModeTest.php --filter='order_id|canary'
```

Expected: FAIL because current manager requires `BinanceOrderIdCanaryEvidence`, and checkout requires the readiness marker except for the configured admin canary.

- [ ] **Step 3: Implement direct mode persistence and selection**

Replace `BinanceVerificationModeManager` dependencies with no constructor dependencies. Preserve mode validation and credential validation. For either valid mode, persist only:

```php
$setting->forceFill(['binance_verification_mode' => $mode])->save();

return $setting->fresh();
```

Do not call `BinanceOrderIdCanaryEvidence` or `BinanceOrderIdActivation`; do not write `binance_order_id_compatibility_ready_at`.

In `BinancePayCheckoutIntent::verificationMode()`, remove `$ready`, `isAdminCanary()`, Telegram-admin configuration, and credential-specific customer logic. Return the configured `amount_unique` or `order_id` value, otherwise `amount_unique`:

```php
$mode = ManualPaymentSetting::current()?->binance_verification_mode;

return in_array($mode, ['amount_unique', 'order_id'], true) ? $mode : 'amount_unique';
```

- [ ] **Step 4: Run mode and checkout tests to verify direct toggle passes**

Run:

```bash
cd src && php artisan test tests/Feature/AdminDashboardPaymentModeTest.php tests/Feature/Telegram/BinanceCheckoutModeTest.php
```

Expected: PASS. An owner can select Order ID without a payment; new non-admin Order ID checkout is exact; missing credentials are rejected; legacy checkout remains covered.

- [ ] **Step 5: Commit direct policy change**

```bash
git add src/app/Services/Binance/BinanceVerificationModeManager.php src/app/Services/Checkout/BinancePayCheckoutIntent.php src/tests/Feature/AdminDashboardPaymentModeTest.php src/tests/Feature/Telegram/BinanceCheckoutModeTest.php
git commit -m "feat: toggle Binance Order ID without canary"
```

### Task 2: Preserve Cross-Mode Amount Safety and Remove Canary Runtime

**Files:**
- Delete: `src/app/Services/Binance/BinanceOrderIdCanaryEvidence.php`
- Delete: `src/app/Services/Binance/BinanceOrderIdActivation.php`
- Delete: `src/tests/Feature/Telegram/BinanceOrderIdActivationTest.php`
- Modify: `src/app/Services/Admin/AdminDashboardStatsData.php:167-200`
- Modify: `src/app/Storefront/StorefrontProductPresenter.php:132-136`
- Modify: `src/app/Console/Commands/HealthCheck.php:5-25,79-93`
- Modify: `src/app/Bootstrap/ProductionConfigurationValidator.php:60-81`
- Modify: `src/tests/Feature/BinanceUniquePaymentAmountTest.php`

**Interfaces:**
- Consumes: current cross-mode occupancy behavior of `BinanceUniquePaymentAmount::allocate(int): int` and snapshotted payment modes.
- Produces: no canary services or health contract; dashboard/storefront mode state directly reflects the selected setting; pending Order ID amounts continue reserving a sequential legacy slot.

- [ ] **Step 1: Preserve cross-mode allocation coverage and identify obsolete canary source**

Keep the existing regression in `BinanceUniquePaymentAmountTest` that creates a pending automatic Order ID payment at `1_000_000` and asserts legacy allocation returns `1_001_000`; its credential-restoration teardown must remain intact.

Replace no tests in `BinanceOrderIdActivationTest`: that file exists solely for the guarded canary activation contract and must be deleted with its source services. The direct-toggle tests from Task 1 become the authoritative activation contract.

- [ ] **Step 2: Run safety and canary-contract tests before removal**

Run:

```bash
cd src && php artisan test tests/Feature/BinanceUniquePaymentAmountTest.php tests/Feature/Telegram/BinanceOrderIdActivationTest.php tests/Feature/AdminDashboardPaymentModeTest.php
```

Expected: PASS before removal. The run establishes sequential cross-mode safety and the old canary contract that Task 2 removes.

- [ ] **Step 3: Remove canary services and runtime dependencies**

Delete `BinanceOrderIdCanaryEvidence.php`, `BinanceOrderIdActivation.php`, and `BinanceOrderIdActivationTest.php`. Remove `BinanceOrderIdCanaryEvidence` imports/dependency injection, the `--binance-canary` option, and canary output block from `HealthCheck`.

Remove canary-age validation from `ProductionConfigurationValidator`.

In `AdminDashboardStatsData`, remove `binance_order_id_ready` and `binance_order_id_guard_message`; expose only selected `binance_verification_mode` and existing Binance enablement/TTL fields. In `StorefrontProductPresenter`, treat Order ID as available whenever the selected setting equals `order_id`, without testing the marker.

Do not edit `BinanceUniquePaymentAmount` occupancy filters: its broad pending automatic Binance query is the required protection compatible with the broad unique index.

- [ ] **Step 4: Run removal and safety tests**

Run:

```bash
cd src && php artisan test tests/Feature/BinanceUniquePaymentAmountTest.php tests/Feature/AdminDashboardPaymentModeTest.php tests/Feature/Telegram/BinanceCheckoutModeTest.php tests/Feature/Telegram/BinanceAutoVerifyTest.php
```

Expected: PASS. No selected Order ID mode depends on a canary; direct mode checkout works; Amount unik remains sequential and cross-mode collision-safe; existing verification behavior remains intact.

- [ ] **Step 5: Commit canary runtime removal**

```bash
git add -u src/app/Services/Binance/BinanceOrderIdCanaryEvidence.php src/app/Services/Binance/BinanceOrderIdActivation.php src/tests/Feature/Telegram/BinanceOrderIdActivationTest.php
git add src/app/Services/Admin/AdminDashboardStatsData.php src/app/Storefront/StorefrontProductPresenter.php src/app/Console/Commands/HealthCheck.php src/app/Bootstrap/ProductionConfigurationValidator.php src/tests/Feature/BinanceUniquePaymentAmountTest.php
git commit -m "refactor: remove Binance Order ID canary gate"
```

### Task 3: Remove Canary Configuration, Dashboard Copy, and Operations Documentation

**Files:**
- Modify: `src/resources/views/admin/dashboard/stats-tab.blade.php:185-210`
- Modify: `src/config/services.php`
- Modify: `.env.example`
- Modify: `src/.env.example`
- Modify: `docker-compose.yml`
- Modify: `docs/ci-cd.md:157-163`
- Modify: `src/tests/Feature/FoundationInfrastructureTest.php`

**Interfaces:**
- Consumes: direct dashboard toggle from Task 1 and canary-free metadata from Task 2.
- Produces: owner-facing direct toggle copy and no operational configuration/documentation that claims an admin canary is required.

- [ ] **Step 1: Write failing UI/configuration contract tests**

Update dashboard feature assertions to require direct-copy text such as `Pilih Order ID setelah kredensial Binance dikonfigurasi.` and assert the obsolete `transaksi canary admin` text is absent.

In infrastructure tests, assert `services.binance.canary_max_age_hours` is absent and production validation no longer depends on that setting. Assert root env examples, application env example, and Compose source no longer contain `BINANCE_ORDER_ID_CANARY_MAX_AGE_HOURS`.

- [ ] **Step 2: Run tests to verify current canary copy/config fails**

Run:

```bash
cd src && php artisan test tests/Feature/AdminDashboardPaymentModeTest.php tests/Feature/FoundationInfrastructureTest.php
```

Expected: FAIL because dashboard copy, service config, production validation, and environment templates still expose the canary contract.

- [ ] **Step 3: Remove canary surfaces and update direct-toggle copy**

Remove `BINANCE_ORDER_ID_CANARY_MAX_AGE_HOURS` from all environment and Compose examples and delete `canary_max_age_hours` from `services.binance` configuration.

Replace the dashboard readiness paragraph with direct configuration guidance:

```blade
<p class="mt-1 text-[11px] font-semibold text-brand-muted">Pilih Order ID setelah API key, secret, dan Binance Pay ID atau UID dikonfigurasi. Amount unik tetap memakai nominal berurutan.</p>
```

Update `docs/ci-cd.md` so Order ID activation says the owner selects it in **Admin → Mode pembayaran → Verifikasi Binance** after configuring API credentials and Pay ID/UID; remove all self-payment, admin-canary, readiness-marker, and canary-evidence language. Preserve the rollback instruction to select **Amount unik (legacy)**.

- [ ] **Step 4: Run focused UI/configuration tests and production-health smoke**

Run:

```bash
cd src && php artisan test tests/Feature/AdminDashboardPaymentModeTest.php tests/Feature/FoundationInfrastructureTest.php && php artisan config:clear && php artisan config:cache && php artisan ops:health
```

Expected: PASS. Dashboard shows direct-toggle guidance, canary configuration is absent, and application configuration/health boot successfully.

- [ ] **Step 5: Commit operational cutover**

```bash
git add src/resources/views/admin/dashboard/stats-tab.blade.php src/config/services.php .env.example src/.env.example docker-compose.yml docs/ci-cd.md src/tests/Feature/FoundationInfrastructureTest.php src/tests/Feature/AdminDashboardPaymentModeTest.php
git commit -m "docs: remove Binance Order ID canary operations"
```

## Plan Self-Review

- **Spec coverage:** Task 1 provides direct credential-gated selection and non-admin checkout. Task 2 removes runtime canary dependencies while preserving cross-mode sequential safety and payment snapshots. Task 3 removes dashboard, configuration, health, and documentation canary surfaces.
- **Placeholder scan:** Every task supplies named files, expected test outcomes, concrete code or assertions, and commit boundaries; no deferred or ambiguous work is present.
- **Type consistency:** Existing mode strings remain `amount_unique` and `order_id`; the manager and checkout method signatures are preserved; no consumer gains a new API.
