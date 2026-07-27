# Telegram Store Growth Statistics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Telegram admin daily-statistics entry point with one secure growth report containing today, this week, and this month compared with equivalent previous periods.

**Architecture:** Keep Telegram authorization and private-chat enforcement in the existing router and callback pipeline. Extract range calculation and store metric queries into a focused `TelegramStoreStatsReport` service, while `TelegramAdminStatsIntents` remains responsible for Telegram presentation and backward-compatible entry points. Capture one Asia/Jakarta report timestamp, build six bounded ranges, preserve canonical payment selection, and format currency-specific growth without combining currencies.

**Tech Stack:** PHP 8.3+, Laravel 13, Eloquent, Carbon, PHPUnit 12, Laravel HTTP fakes, SQLite fast tests, PostgreSQL 16 release gate.

## Global Constraints

- The visible admin keyboard label is exactly `📊 Statistik`.
- One response contains `Hari Ini`, `Minggu Ini`, and `Bulan Ini`.
- Today compares with the same elapsed duration yesterday.
- The current Monday-based week compares with the same elapsed duration one week earlier.
- The current month compares with the same date/time span one month earlier, clamped to the previous month end when necessary.
- All boundaries use `Asia/Jakarta` and one captured report-end timestamp.
- Legacy text `📊 Statistik Harian` and callback `statistik_harian` remain supported.
- Admin and private-chat gates must not be weakened.
- Paid orders use the first canonical paid payment and count each order once.
- Receipt changes are calculated separately per currency. IDR and USDT are never summed or compared across currencies.
- `sendAutomatedDailyStats()` keeps the existing yesterday-only output.
- No cache, write-side state, new dependency, schema change, or per-order query is introduced.

---

## File Structure

- Create `src/app/Services/Telegram/TelegramStoreStatsReport.php`: builds current/previous ranges and calculates store metrics for each range.
- Modify `src/app/Services/Telegram/TelegramAdminStatsIntents.php`: delegates calculations, formats growth sections, sends the interactive report, and preserves the automated yesterday report.
- Modify `src/app/Services/Telegram/TelegramMenuIntents.php`: renders the new keyboard label.
- Modify `src/app/Services/Telegram/TelegramMessageRouter.php`: accepts both new and legacy text labels through the same authorization path.
- Modify `src/app/Services/Telegram/Callbacks/TelegramAdminStatsCallbackHandler.php`: calls the new report entry point while retaining legacy callback support.
- Modify `src/tests/Feature/Telegram/AdminMenuTest.php`: verifies label, routing, backward compatibility, and privacy behavior.
- Modify `src/tests/Unit/Services/Telegram/TelegramAdminStatsIntentsTest.php`: verifies ranges, growth formatting, canonical payment handling, currencies, and automated-report regression.

---

### Task 1: Rename the visible button and preserve secure entry-point compatibility

**Files:**
- Modify: `src/tests/Feature/Telegram/AdminMenuTest.php:15-31,219-245`
- Modify: `src/app/Services/Telegram/TelegramMenuIntents.php:72-94`
- Modify: `src/app/Services/Telegram/TelegramMessageRouter.php:29-88, private isPrivateOnlyCommand method`
- Modify: `src/app/Services/Telegram/Callbacks/TelegramAdminStatsCallbackHandler.php:32-47`

**Interfaces:**
- Consumes: existing `TelegramAdminAccess::isAdmin()`, `TelegramChatPrivacyGuard`, and callback-stage privacy enforcement.
- Produces: `TelegramAdminStatsIntents::showStoreGrowth(Customer $customer, string $chatId, ?string $callbackQueryId = null): void`, introduced as a temporary expected interface and implemented in Task 3.
- Compatibility: `📊 Statistik`, `📊 Statistik Harian`, and `statistik_harian` all resolve to the same secure report action.

- [ ] **Step 1: Update the keyboard regression test first**

Change the admin keyboard assertion in `AdminMenuTest` to require the new label and reject the legacy visible label:

```php
Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
    && str_contains($request['text'], 'Menu utama')
    && ($request['reply_markup']['keyboard'][3][0]['text'] ?? null) === '📊 Statistik'
    && ! str_contains(json_encode($request['reply_markup']), '📊 Statistik Harian')
    && ! array_key_exists('callback_data', $request['reply_markup']['keyboard'][3][0] ?? []));
```

- [ ] **Step 2: Add routing tests for new and legacy text**

Replace the daily-only routing test with a data provider or two explicit tests that post these texts as the configured admin in a private chat:

```php
public static function statisticsLabels(): array
{
    return [
        'current label' => ['📊 Statistik'],
        'legacy label' => ['📊 Statistik Harian'],
    ];
}

#[DataProvider('statisticsLabels')]
public function test_admin_can_open_store_growth_stats_from_current_and_legacy_labels(string $label): void
{
    config()->set('services.telegram.admin_user_id', '999');
    $this->fakeTelegramHttp();

    $this->postTelegramWebhook($this->messageUpdate(315, $label, ['id' => 999], 999))->assertOk();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
        && (string) $request['chat_id'] === '999'
        && str_contains((string) $request['text'], '📊 Statistik Hari Ini'));
}
```

Add `use PHPUnit\Framework\Attributes\DataProvider;`.

- [ ] **Step 3: Add private-chat and forged-callback regression assertions**

Add a group-chat test using the new label and retain the existing non-admin forged callback test. The group test must assert the private-chat redirect is sent and no growth report is sent:

```php
public function test_store_growth_stats_are_private_chat_only(): void
{
    config()->set('services.telegram.admin_user_id', '999');
    $this->fakeTelegramHttp();

    $this->postTelegramWebhook(
        $this->messageUpdate(319, '📊 Statistik', ['id' => 999], -100123, 'group')
    )->assertOk();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
        && (string) $request['chat_id'] === '-100123'
        && str_contains((string) $request['text'], 'Lanjut di chat pribadi'));
    Http::assertNotSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), '📊 Pertumbuhan Store'));
}
```

- [ ] **Step 4: Run the focused feature tests and verify RED**

Run:

```bash
cd src
php artisan test tests/Feature/Telegram/AdminMenuTest.php
```

Expected: FAIL because the keyboard still renders `📊 Statistik Harian`, the new text is not routed, and `showStoreGrowth()` does not yet exist. If failure is a PHP syntax or missing import error, fix the test until it fails only on missing behavior.

- [ ] **Step 5: Make the minimal menu and routing changes**

In `TelegramMenuIntents::mainMenuKeyboard()`:

```php
$keyboard[] = [['text' => '📊 Statistik'], ['text' => '📦 Cek Stok']];
```

In `TelegramMessageRouter::route()`:

```php
if (in_array($text, ['📊 Statistik', '📊 Statistik Harian'], true)) {
    $this->session->forget($customer);
    if (! $this->adminAccess->isAdmin($customer, $chatId)) {
        $this->bot->sendMessage($chatId, $this->text($customer, 'Akses ditolak.', 'Access denied.'));

        return;
    }

    $this->adminStats->showStoreGrowth($customer, $chatId);

    return;
}
```

Add both labels to the private-only command list in `isPrivateOnlyCommand()`.

In `TelegramAdminStatsCallbackHandler::handle()` call:

```php
$this->adminStats->showStoreGrowth(
    $context->customer,
    $context->chatId,
    $context->callbackQueryId,
);
```

Do not change `supports()` or `allowsNonPrivateChat()`.

- [ ] **Step 6: Add a temporary delegating method so the focused feature test can run**

Add this method to `TelegramAdminStatsIntents`; Task 3 replaces its body with the growth formatter:

```php
public function showStoreGrowth(Customer $customer, string $chatId, ?string $callbackQueryId = null): void
{
    $this->showDailyStats($customer, $chatId, $callbackQueryId);
}
```

- [ ] **Step 7: Run the feature tests and verify the compatibility layer**

Run:

```bash
cd src
php artisan test tests/Feature/Telegram/AdminMenuTest.php
```

Expected: all keyboard, privacy, current-label, legacy-label, and legacy-callback authorization tests pass through the existing daily presentation. Task 3 changes the routing assertion from `📊 Statistik Hari Ini` to `📊 Pertumbuhan Store` before implementing the final formatter.

- [ ] **Step 8: Commit the independently green entry-point slice**

```bash
git add src/app/Services/Telegram/TelegramMenuIntents.php \
  src/app/Services/Telegram/TelegramMessageRouter.php \
  src/app/Services/Telegram/Callbacks/TelegramAdminStatsCallbackHandler.php \
  src/app/Services/Telegram/TelegramAdminStatsIntents.php \
  src/tests/Feature/Telegram/AdminMenuTest.php
git commit -m "feat: rename Telegram statistics entry point"
```

---

### Task 2: Build the bounded multi-period report calculator

**Files:**
- Create: `src/app/Services/Telegram/TelegramStoreStatsReport.php`
- Modify: `src/tests/Unit/Services/Telegram/TelegramAdminStatsIntentsTest.php`
- Modify: `src/app/Services/Telegram/TelegramAdminStatsIntents.php:14-22,89-150`

**Interfaces:**
- Produces `TelegramStoreStatsReport::today(?Carbon $reportEndAt = null): array`.
- Produces `TelegramStoreStatsReport::yesterday(?Carbon $reportEndAt = null): array`.
- Produces `TelegramStoreStatsReport::growth(?Carbon $reportEndAt = null): array`.
- `growth()` returns:

```php
array{
    report_end_at: Carbon,
    periods: array{
        today: array{current: Stats, previous: Stats},
        week: array{current: Stats, previous: Stats},
        month: array{current: Stats, previous: Stats}
    }
}
```

Where `Stats` is:

```php
array{
    new_orders: int,
    paid_orders: int,
    sold_products: int,
    sales_value: int,
    receipts: array<string, int>,
    earning: int
}
```

- [ ] **Step 1: Write a range-membership regression test**

Freeze time explicitly in Asia/Jakarta and seed four paid orders:

```php
Carbon::setTestNow(Carbon::parse('2026-07-08 15:00:00', 'Asia/Jakarta'));
config()->set('app.timezone', 'Asia/Jakarta');

// Wednesday today: appears in today, week, and month.
$this->createPaidOrderAt('2026-07-08 10:00:00', 10000, 1);
// Tuesday in the current week: appears in week and month only.
$this->createPaidOrderAt('2026-07-07 10:00:00', 20000, 2);
// Earlier in July before this Monday: appears in month only.
$this->createPaidOrderAt('2026-07-02 10:00:00', 30000, 3);
// June: excluded from all current periods.
$this->createPaidOrderAt('2026-06-20 10:00:00', 40000, 4);

$report = app(TelegramStoreStatsReport::class)->growth();

$this->assertSame(1, $report['periods']['today']['current']['paid_orders']);
$this->assertSame(3, $report['periods']['week']['current']['sold_products']);
$this->assertSame(6, $report['periods']['month']['current']['sold_products']);
$this->assertSame(60000, $report['periods']['month']['current']['sales_value']);
```

Implement a private test helper that creates a customer, product, paid order, order item, and canonical IDR payment at the supplied timestamp. Keep it in the test class.

- [ ] **Step 2: Write an equivalent-elapsed-period comparison test**

At Wednesday 2026-07-08 15:00 WIB, seed comparison records inside and just outside the expected prior windows:

```php
$this->createPaidOrderAt('2026-07-07 14:59:59', 11000, 1); // previous-day window included
$this->createPaidOrderAt('2026-06-30 15:00:00', 12000, 1); // previous-week window included
$this->createPaidOrderAt('2026-06-08 15:00:00', 13000, 1); // previous-month window included
$this->createPaidOrderAt('2026-06-08 15:00:01', 14000, 1); // just outside previous-month window

$report = app(TelegramStoreStatsReport::class)->growth();

$this->assertSame(1, $report['periods']['today']['previous']['paid_orders']);
$this->assertSame(1, $report['periods']['week']['previous']['paid_orders']);
$this->assertSame(1, $report['periods']['month']['previous']['paid_orders']);
```

Use independent test methods if shared rows would make counts ambiguous. The production change that must make each test pass is correct construction of the prior end boundary.

- [ ] **Step 3: Write a short-previous-month clamp test**

Freeze time at `2026-03-31 15:00:00 Asia/Jakarta`. Seed a payment on `2026-02-28 14:59:59` and one on `2026-03-01 00:00:00`. Assert the February record is included in `month.previous`, and the March record is only in `month.current`. This prevents Carbon month overflow from moving the previous end into March.

- [ ] **Step 4: Write canonical-payment and currency regression tests for growth periods**

Create one order whose first paid payment is IDR in the current month and whose duplicate paid row is USDT later. Assert the order count is one, only the IDR receipt exists, and no USDT receipt is emitted. Add a second genuine USDT order and assert it remains a separate receipt key.

```php
$this->assertSame(2, $current['paid_orders']);
$this->assertSame(49000, $current['receipts']['IDR']);
$this->assertSame(2_500_000, $current['receipts']['USDT']);
```

- [ ] **Step 5: Run calculator tests and verify RED**

Run:

```bash
cd src
php artisan test tests/Unit/Services/Telegram/TelegramAdminStatsIntentsTest.php
```

Expected: FAIL because `TelegramStoreStatsReport` and `growth()` do not exist. Existing daily and automated tests must remain green when run separately.

- [ ] **Step 6: Implement `TelegramStoreStatsReport` with explicit ranges**

Create the service with constructor injection:

```php
final class TelegramStoreStatsReport
{
    public function __construct(
        private readonly PaymentReceiptReport $receiptReport,
    ) {}

    public function growth(?Carbon $reportEndAt = null): array
    {
        $end = ($reportEndAt ?? now('Asia/Jakarta'))->copy()->timezone('Asia/Jakarta');
        $dayStart = $end->copy()->startOfDay();
        $weekStart = $end->copy()->startOfWeek(Carbon::MONDAY);
        $monthStart = $end->copy()->startOfMonth();

        $previousMonthStart = $monthStart->copy()->subMonthNoOverflow();
        $elapsedSeconds = $monthStart->diffInSeconds($end);
        $previousMonthEnd = $previousMonthStart->copy()->addSeconds($elapsedSeconds);
        $previousMonthLastMoment = $previousMonthStart->copy()->endOfMonth();
        if ($previousMonthEnd->greaterThan($previousMonthLastMoment)) {
            $previousMonthEnd = $previousMonthLastMoment;
        }

        return [
            'report_end_at' => $end,
            'periods' => [
                'today' => $this->pair($dayStart, $end, $dayStart->copy()->subDay(), $end->copy()->subDay()),
                'week' => $this->pair($weekStart, $end, $weekStart->copy()->subWeek(), $end->copy()->subWeek()),
                'month' => $this->pair($monthStart, $end, $previousMonthStart, $previousMonthEnd),
            ],
        ];
    }
}
```

Use `pair()` to call one private `calculateStats(Carbon $start, Carbon $end, bool $includeEnd = true): array`. Move the existing canonical payment, sold-item, sales-value, and receipt logic there without changing its semantics.

- [ ] **Step 7: Delegate daily methods without breaking callers**

Inject `TelegramStoreStatsReport` into `TelegramAdminStatsIntents`. Make `calculateTodayStats()` delegate to `today()` and make the private yesterday calculation delegate to `yesterday()`. Preserve the current public method name because existing callers and tests rely on it.

- [ ] **Step 8: Run focused calculator tests and refactor only while green**

Run:

```bash
cd src
php artisan test tests/Unit/Services/Telegram/TelegramAdminStatsIntentsTest.php
```

Expected: all range, canonical payment, currency, daily, and automated yesterday tests pass.

- [ ] **Step 9: Commit the calculator slice**

```bash
git add src/app/Services/Telegram/TelegramStoreStatsReport.php \
  src/app/Services/Telegram/TelegramAdminStatsIntents.php \
  src/tests/Unit/Services/Telegram/TelegramAdminStatsIntentsTest.php
git commit -m "feat: calculate Telegram store growth periods"
```

---

### Task 3: Format one readable growth report with truthful change indicators

**Files:**
- Modify: `src/tests/Unit/Services/Telegram/TelegramAdminStatsIntentsTest.php`
- Modify: `src/app/Services/Telegram/TelegramAdminStatsIntents.php`
- Modify: files from Task 1 that remain uncommitted.

**Interfaces:**
- Consumes: `TelegramStoreStatsReport::growth()` from Task 2.
- Produces: `TelegramAdminStatsIntents::showStoreGrowth(Customer $customer, string $chatId, ?string $callbackQueryId = null): void`.
- Keeps: `showDailyStats()` as a backward-compatible delegator to `showStoreGrowth()`.
- Produces private `changeIndicator(int $current, int $previous): string` and `formatGrowthMessage(array $report): string`.

- [ ] **Step 1: Write a response-shape test**

First change the Task 1 routing assertion from `📊 Statistik Hari Ini` to `📊 Pertumbuhan Store`. Freeze time, fake Telegram, seed current and prior records, invoke `showStoreGrowth()`, and assert one send contains all three sections and the timestamp:

```php
$text = (string) $sends->first()[0]['text'];
$this->assertStringContainsString('📊 Pertumbuhan Store', $text);
$this->assertStringContainsString('Diperbarui 08 Jul 2026 15:00 WIB', $text);
$this->assertStringContainsString('☀️ Hari Ini', $text);
$this->assertStringContainsString('🗓 Minggu Ini', $text);
$this->assertStringContainsString('📅 Bulan Ini', $text);
$this->assertLessThanOrEqual(4096, mb_strlen($text));
```

- [ ] **Step 2: Write growth-indicator tests covering all baseline states**

Through real formatted output, seed values that produce:

```text
📈 +50%
📉 -25%
➖ 0%
📈 Baru
```

Assert no `INF`, `NAN`, or division warning text appears. Use amounts whose percentages are exact or one-decimal values, such as current 3 versus previous 2 and current 7 versus previous 8.

- [ ] **Step 3: Write a per-currency comparison test**

Seed current IDR and USDT receipts while the prior period has only IDR. Assert output contains one IDR comparison and a separate USDT line ending in `📈 Baru`. Assert there is no combined total labeled as a single currency.

- [ ] **Step 4: Run formatter tests and verify RED**

Run:

```bash
cd src
php artisan test tests/Unit/Services/Telegram/TelegramAdminStatsIntentsTest.php \
  tests/Feature/Telegram/AdminMenuTest.php
```

Expected: FAIL because the temporary delegator still sends the daily-only message and no growth indicators exist.

- [ ] **Step 5: Implement `showStoreGrowth()` and preserve callback acknowledgement**

```php
public function showStoreGrowth(Customer $customer, string $chatId, ?string $callbackQueryId = null): void
{
    if ($callbackQueryId !== null) {
        $this->bot->answerCallbackQuery($callbackQueryId);
    }

    $report = $this->storeStatsReport->growth();
    $this->sendMessageWithDashboard($chatId, $this->formatGrowthMessage($report));
}

public function showDailyStats(Customer $customer, string $chatId, ?string $callbackQueryId = null): void
{
    $this->showStoreGrowth($customer, $chatId, $callbackQueryId);
}
```

Rename the existing generic send helper to `sendMessageWithDashboard()` so both growth and automated yesterday messages reuse the Mini App button without recalculating text.

- [ ] **Step 6: Implement deterministic growth formatting**

Use this comparison rule:

```php
private function changeIndicator(int $current, int $previous): string
{
    if ($previous === 0) {
        return $current === 0 ? '➖ 0%' : '📈 Baru';
    }

    $percentage = (($current - $previous) / $previous) * 100;
    if (abs($percentage) < 0.05) {
        return '➖ 0%';
    }

    $formatted = number_format(abs($percentage), fmod(abs($percentage), 1.0) === 0.0 ? 0 : 1, ',', '.');

    return $percentage > 0 ? '📈 +'.$formatted.'%' : '📉 -'.$formatted.'%';
}
```

For each period, format scalar metrics from current and previous values. Build the receipt currency list from the union of both receipt-key arrays, sort it, and format each currency independently with existing `Money::rupiah()` and `Money::usdt()` behavior. Do not output a cross-currency aggregate.

- [ ] **Step 7: Preserve automated yesterday formatting**

Keep `sendAutomatedDailyStats()` producing the exact `📊 Rekap Kemarin` structure and dashboard button. Its existing regression test must remain unchanged and green.

- [ ] **Step 8: Run all targeted tests and verify GREEN**

Run:

```bash
cd src
php artisan test --filter='AdminMenuTest|TelegramAdminStatsIntentsTest|WebhookIngressTest'
```

Expected: all tests pass, including legacy callback denial, private-chat gates, canonical payment behavior, and duplicate-update safety.

- [ ] **Step 9: Format changed PHP files and rerun targeted tests**

```bash
cd src
./vendor/bin/pint app/Services/Telegram/TelegramStoreStatsReport.php \
  app/Services/Telegram/TelegramAdminStatsIntents.php \
  app/Services/Telegram/TelegramMenuIntents.php \
  app/Services/Telegram/TelegramMessageRouter.php \
  app/Services/Telegram/Callbacks/TelegramAdminStatsCallbackHandler.php \
  tests/Feature/Telegram/AdminMenuTest.php \
  tests/Unit/Services/Telegram/TelegramAdminStatsIntentsTest.php
php artisan test --filter='AdminMenuTest|TelegramAdminStatsIntentsTest|WebhookIngressTest'
```

Expected: Pint exits zero and targeted tests remain green.

- [ ] **Step 10: Commit the complete Telegram interaction slice**

```bash
git add src/app/Services/Telegram/TelegramAdminStatsIntents.php \
  src/app/Services/Telegram/TelegramMenuIntents.php \
  src/app/Services/Telegram/TelegramMessageRouter.php \
  src/app/Services/Telegram/Callbacks/TelegramAdminStatsCallbackHandler.php \
  src/tests/Feature/Telegram/AdminMenuTest.php \
  src/tests/Unit/Services/Telegram/TelegramAdminStatsIntentsTest.php
git commit -m "feat: show Telegram store growth statistics"
```

---

### Task 4: Verify performance, security, regressions, and release readiness

**Files:**
- Inspect: all files changed since `origin/main`.
- No production file should be added during this task unless verification exposes a bug and a new failing regression test is added first.

**Interfaces:**
- Consumes the complete feature from Tasks 1-3.
- Produces fresh verification evidence and an independently reviewed release decision.

- [ ] **Step 1: Run static formatting and dependency gates**

```bash
cd src
./vendor/bin/pint --test
composer validate --strict
composer audit --locked --no-dev --abandoned=fail
```

Expected: every command exits zero. Do not suppress advisories.

- [ ] **Step 2: Run the full SQLite test suite**

```bash
cd src
composer test
```

Expected: all tests pass with no unexpected skips or warnings caused by this feature.

- [ ] **Step 3: Run the PostgreSQL release and concurrency gate**

Use the repository's configured PostgreSQL test environment, then run:

```bash
cd src
php artisan test --env=testing --group=postgres-release
composer test:concurrency-gate
```

Expected: PostgreSQL release tests and the process-level concurrency gate pass. If local PostgreSQL credentials are unavailable, rely on the mandatory CI PostgreSQL lane and do not claim the local gate passed.

- [ ] **Step 4: Inspect query behavior for bounded execution**

In a focused test, enable the query log around one `growth()` call and assert no query is issued inside a loop over individual order IDs. Review the SQL evidence for:

- bounded `created_at` and `paid_at` predicates;
- use of canonical-payment lookup logic;
- aggregate `SUM` queries for order items and order totals;
- a fixed query count that does not increase when more orders are seeded.

Record the observed query count in the completion report. Do not set a brittle exact-count assertion unless the count is intentionally part of the service contract.

- [ ] **Step 5: Review the complete diff for security invariants**

```bash
git diff origin/main...HEAD -- \
  src/app/Services/Telegram \
  src/tests/Feature/Telegram/AdminMenuTest.php \
  src/tests/Unit/Services/Telegram/TelegramAdminStatsIntentsTest.php
git diff --check origin/main...HEAD
```

Confirm explicitly:

- new and legacy labels reach the same admin check;
- group messages are rejected before statistics queries run;
- callback handler remains private-only and rejects forged callbacks;
- no user-controlled string enters SQL or Telegram parse mode;
- all operations are read-only and duplicate-safe;
- no currency is combined with another currency;
- no unbounded order/payment history query was introduced.

- [ ] **Step 6: Request independent code review**

Invoke `superpowers:requesting-code-review` and give the reviewer the design spec, implementation plan, `origin/main...HEAD` diff, and the named security/performance checklist above. Treat any Critical or Important finding as a blocker. Fix blockers with a new failing test first, rerun all relevant gates, and request re-review.

- [ ] **Step 7: Run verification-before-completion**

Invoke `superpowers:verification-before-completion`. Capture fresh command outputs for targeted tests, full tests, Pint, audits, PostgreSQL/concurrency evidence, and `git status --short --branch` before claiming completion.

- [ ] **Step 8: Commit any verification-driven fixes and ensure a clean tree**

```bash
git status --short
git log --oneline origin/main..HEAD
```

Expected: no uncommitted changes and commits contain only the design, calculator, Telegram interaction, and any test-first verification fix.

- [ ] **Step 9: Refresh local Docker only if the user requests local application**

Because PHP application code changed, use the existing project name:

```bash
docker compose -p telegram-bot-prod up -d --build --remove-orphans
docker compose -p telegram-bot-prod ps
docker compose -p telegram-bot-prod exec -T app php artisan ops:health --db --queue-schema --queue-depth --supervisor
```

Expected: app, scheduler, database, and web services are healthy. Never start a duplicate unnamed Compose stack.

- [ ] **Step 10: Release only after explicit production authorization**

The production source is `main`. After authorization, push the verified commits, wait for all CI jobs for the exact SHA, then invoke:

```bash
gh workflow run deploy-production.yml --ref main
```

Watch the run to completion and verify the workflow's migration, app, scheduler, database, Telegram webhook, public smoke, Mini App smoke, immutable image, and duplicate-stack checks. Do not deploy with ad hoc SSH commands.
