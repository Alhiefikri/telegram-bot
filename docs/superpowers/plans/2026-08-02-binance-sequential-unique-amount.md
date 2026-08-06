# Binance Sequential Unique Amount Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace random legacy Binance Pay amount allocation with the lowest available sequential `0.001` USDT amount, starting at the catalog amount.

**Architecture:** `BinanceUniquePaymentAmount` will query occupied legacy Binance amounts in bounded ascending batches and select the first missing mill-USDT slot in memory. The existing partial unique index remains the concurrency authority; `OrderCheckoutService` retries a transaction that loses the insert race. The obsolete random-offset configuration is removed because allocation no longer has a configurable random range.

**Tech Stack:** PHP 8.4, Laravel 12, Eloquent, PHPUnit 11, PostgreSQL/MySQL, Docker Compose.

## Global Constraints

- Apply only to `manual` `BINANCE_PAY_ID` payments with `amount_unique` verification and automatic Binance verification enabled.
- Treat only rows with `status = pending` as occupied; terminal payment states release the amount.
- Preserve integer micro-USDT storage; one allocation step is exactly `1_000` micro-USDT (`0.001` USDT).
- Start at the exact catalog amount. Do not round up or add a minimum offset.
- Preserve exact catalog amounts for `order_id` payments and every non-Binance payment.
- Preserve the existing partial unique index and checkout transaction retry as the final concurrency guard.
- Do not introduce a reservation table, database-specific SQL, gap locks, or advisory locks.
- Remove `BINANCE_UNIQUE_AMOUNT_MIN_OFFSET_MICRO` and `BINANCE_UNIQUE_AMOUNT_MAX_OFFSET_MICRO` from application and Docker configuration.

---

## File Structure

- `src/app/Services/Binance/BinanceUniquePaymentAmount.php` — owns portable, bounded, deterministic selection of the smallest free legacy Binance amount.
- `src/tests/Feature/BinanceUniquePaymentAmountTest.php` — verifies database-backed occupancy, slot reuse, batch continuation, and scope rules directly against the allocator.
- `src/tests/Feature/Telegram/BinanceCheckoutModeTest.php` — proves checkout persists the catalog amount for a free legacy Binance payment and the next sequential amount when the first slot is occupied.
- `src/config/services.php` — removes configuration keys that no longer represent a supported behavior.
- `.env.example`, `src/.env.example`, `docker-compose.yml` — remove obsolete random-offset environment variables so Docker and documented configuration cannot re-enable random charging.

### Task 1: Deterministic Allocator Contract

**Files:**
- Create: `src/tests/Feature/BinanceUniquePaymentAmountTest.php`
- Modify: `src/app/Services/Binance/BinanceUniquePaymentAmount.php:13-68`

**Interfaces:**
- Consumes: `BinanceUniquePaymentAmount::allocate(int $baseAmountMicro): int`, `Payment`, `PaymentStatus`, and configured `BinancePayClient` credentials.
- Produces: `allocate()` returns the smallest free micro-USDT amount in the sequence `base`, `base + 1_000`, `base + 2_000`, … among active legacy Binance payment rows.

- [ ] **Step 1: Write focused failing allocator tests**

Create `src/tests/Feature/BinanceUniquePaymentAmountTest.php` using `RefreshDatabase`. Configure Binance API credentials in `setUp()` so the allocator does not take its existing non-configured bypass. Add this helper and tests:

```php
private function occupy(int $amount, PaymentStatus $status = PaymentStatus::Pending): Payment
{
    return Payment::factory()->create([
        'provider' => 'manual',
        'method' => 'BINANCE_PAY_ID',
        'amount' => $amount,
        'currency' => 'USDT',
        'status' => $status,
        'binance_auto_verification_enabled' => true,
        'binance_verification_mode' => 'amount_unique',
    ]);
}

public function test_returns_the_exact_base_amount_when_it_is_free(): void
{
    $this->assertSame(1_000_000, app(BinanceUniquePaymentAmount::class)->allocate(1_000_000));
}

public function test_returns_the_lowest_free_sequential_mill_usdt_amount(): void
{
    $this->occupy(1_000_000);
    $this->occupy(1_001_000);

    $this->assertSame(1_002_000, app(BinanceUniquePaymentAmount::class)->allocate(1_000_000));
}

public function test_reuses_a_lower_gap_before_higher_occupied_amounts(): void
{
    $this->occupy(1_000_000);
    $this->occupy(1_002_000);

    $this->assertSame(1_001_000, app(BinanceUniquePaymentAmount::class)->allocate(1_000_000));
}
```

Add separate tests proving an `Expired` payment, a paid payment, an `order_id` payment, and a non-Binance payment do not occupy `1_000_000`. Add a batch-boundary test that occupies exactly the allocator batch size starting at `1_000_000` and expects the immediately following mill-USDT amount.

- [ ] **Step 2: Run the new tests to verify current behavior fails**

Run:

```bash
cd src && php artisan test tests/Feature/BinanceUniquePaymentAmountTest.php
```

Expected: FAIL. The current allocator starts at a random amount above the base and cannot satisfy the exact-base or deterministic sequence assertions.

- [ ] **Step 3: Replace random selection with bounded ascending batch selection**

In `BinanceUniquePaymentAmount`, remove `offsetRange()`, `random_int()`, and `MICRO_PER_MILLI_USDT` offset conversion logic. Introduce explicit constants:

```php
private const MICRO_PER_MILLI_USDT = 1_000;
private const CANDIDATE_BATCH_SIZE = 1_000;
```

Implement `allocate()` with a `for ($rangeStart = $baseAmountMicro; ; $rangeStart += self::CANDIDATE_BATCH_SIZE * self::MICRO_PER_MILLI_USDT)` loop. Per range:

```php
$rangeEnd = $rangeStart + ((self::CANDIDATE_BATCH_SIZE - 1) * self::MICRO_PER_MILLI_USDT);
$occupied = Payment::query()
    ->where('provider', 'manual')
    ->where('method', 'BINANCE_PAY_ID')
    ->where('status', PaymentStatus::Pending->value)
    ->where('binance_auto_verification_enabled', true)
    ->where('binance_verification_mode', 'amount_unique')
    ->whereBetween('amount', [$rangeStart, $rangeEnd])
    ->orderBy('amount')
    ->pluck('amount')
    ->mapWithKeys(fn (int $amount): array => [$amount => true])
    ->all();

for ($candidate = $rangeStart; $candidate <= $rangeEnd; $candidate += self::MICRO_PER_MILLI_USDT) {
    if (! isset($occupied[$candidate])) {
        return $candidate;
    }
}
```

Keep the existing early return for a non-configured `BinancePayClient`; it preserves the current manual/no-auto-verification checkout behavior. Do not add a maximum offset or random fallback.

- [ ] **Step 4: Run the allocator tests to verify they pass**

Run:

```bash
cd src && php artisan test tests/Feature/BinanceUniquePaymentAmountTest.php
```

Expected: PASS. The allocator returns `1.000`, then the smallest free next mill-USDT amount, ignores terminal/unrelated rows, and advances only after a full batch.

- [ ] **Step 5: Commit the isolated allocator change**

```bash
git add src/app/Services/Binance/BinanceUniquePaymentAmount.php src/tests/Feature/BinanceUniquePaymentAmountTest.php
git commit -m "fix: allocate Binance amounts sequentially"
```

### Task 2: Checkout Regression and Configuration Cutover

**Files:**
- Modify: `src/tests/Feature/Telegram/BinanceCheckoutModeTest.php:17-158`
- Modify: `src/config/services.php:10-22`
- Modify: `.env.example:80-92`
- Modify: `src/.env.example:164-176`
- Modify: `docker-compose.yml:58-70`

**Interfaces:**
- Consumes: the deterministic `BinanceUniquePaymentAmount::allocate()` contract from Task 1 and `OrderCheckoutService::checkout()`.
- Produces: legacy checkout persists a sequential amount; runtime and Docker configuration expose no random-offset variables.

- [ ] **Step 1: Write failing checkout integration coverage**

In `BinanceCheckoutModeTest`, configure Binance API credentials for the legacy-mode test and add a checkout scenario with a product price of `1_000_000` micro-USDT. First checkout must persist a `BINANCE_PAY_ID` `amount_unique` payment with `amount = 1_000_000`.

Create a second active cart for a distinct customer and the same product price, check it out, and assert:

```php
$this->assertSame(1_001_000, $secondOrder->payments()->sole()->amount);
```

Keep existing `order_id` exact-amount assertions unchanged; they are the regression proof that the allocator remains bypassed for Order ID verification.

- [ ] **Step 2: Run the checkout test to prove the legacy random allocator violates the contract**

Run:

```bash
cd src && php artisan test tests/Feature/Telegram/BinanceCheckoutModeTest.php --filter='sequential|legacy'
```

Expected: FAIL before Task 1 is applied, because the first configured legacy checkout receives a random amount above `1_000_000`.

- [ ] **Step 3: Remove obsolete random-offset configuration**

Remove both keys from `src/config/services.php`:

```php
'unique_amount_min_offset_micro' => (int) env('BINANCE_UNIQUE_AMOUNT_MIN_OFFSET_MICRO', 1000),
'unique_amount_max_offset_micro' => (int) env('BINANCE_UNIQUE_AMOUNT_MAX_OFFSET_MICRO', 999000),
```

Delete these lines from root and application env examples and from `docker-compose.yml`:

```dotenv
BINANCE_UNIQUE_AMOUNT_MIN_OFFSET_MICRO=1000
BINANCE_UNIQUE_AMOUNT_MAX_OFFSET_MICRO=999000
```

```yaml
BINANCE_UNIQUE_AMOUNT_MIN_OFFSET_MICRO: ${BINANCE_UNIQUE_AMOUNT_MIN_OFFSET_MICRO:-1000}
BINANCE_UNIQUE_AMOUNT_MAX_OFFSET_MICRO: ${BINANCE_UNIQUE_AMOUNT_MAX_OFFSET_MICRO:-999000}
```

Do not remove unrelated Binance history or verification configuration.

- [ ] **Step 4: Run affected tests and static configuration regression tests**

Run:

```bash
cd src && php artisan test tests/Feature/BinanceUniquePaymentAmountTest.php tests/Feature/Telegram/BinanceCheckoutModeTest.php tests/Feature/Telegram/BinanceAutoVerifyTest.php
```

Expected: PASS. Legacy checkout allocates exact/sequential amounts, `order_id` checkout remains exact, and existing legacy auto-verification behavior remains intact.

- [ ] **Step 5: Smoke-test the application configuration**

Run:

```bash
cd src && php artisan config:clear && php artisan config:cache && php artisan ops:health
```

Expected: configuration cache builds successfully and health exits successfully for the configured local environment.

- [ ] **Step 6: Commit the checkout and configuration cutover**

```bash
git add src/tests/Feature/Telegram/BinanceCheckoutModeTest.php src/config/services.php .env.example src/.env.example docker-compose.yml
git commit -m "chore: remove Binance random amount configuration"
```

## Plan Self-Review

- **Spec coverage:** Task 1 implements exact-base, sequential, lowest-gap allocation, active legacy scope, bounded performance, and batch continuation. The existing unique index and retry are deliberately preserved; Task 2 confirms checkout integration and Order ID bypass. Task 2 removes every discovered random-offset configuration surface.
- **Placeholder scan:** No `TODO`, `TBD`, deferred implementation language, or unspecified test cases remain.
- **Type consistency:** `allocate(int): int` remains unchanged. Tests use existing `PaymentStatus`, factories, `BinanceUniquePaymentAmount`, and `OrderCheckoutService` APIs.
