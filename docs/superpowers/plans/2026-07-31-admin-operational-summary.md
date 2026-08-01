# Admin Operational Summary Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the duplicated Ringkasan metrics with four linked operational queues whose counts and filtered destinations agree.

**Architecture:** Keep the existing server-rendered Blade and query-string navigation. Centralize reusable payment-attention and low-stock predicates in the dashboard data services, derive queue view models from those services, and apply the same predicates before destination pagination.

**Tech Stack:** PHP 8, Laravel, Blade, Alpine.js, PHPUnit feature tests, Playwright.

## Global Constraints

- Low stock means an active product with numeric available stock `max(0, stock - reserved_stock) <= 3`.
- Payment attention includes pending orders with a submitted manual payment proof and cancelled/expired orders whose payment status is paid.
- Reservation cards open the existing waiting-order filter; no credential-level queue is added.
- Provider, TTL, and support controls remain functional but are collapsed below operational and secondary content.
- Do not modify unrelated Camouproxy files or commit unless the user explicitly requests a commit.

---

### Task 1: Filtered Operational Data

**Files:**
- Modify: `src/tests/Feature/AdminDashboardOverviewTest.php`
- Modify: `src/tests/Feature/AdminDashboardSalesRenderingTest.php`
- Modify: `src/app/Http/Controllers/Admin/AdminDashboardController.php`
- Modify: `src/app/Services/Admin/AdminDashboardData.php`
- Modify: `src/app/Services/Admin/AdminDashboardSalesData.php`
- Modify: `src/app/Services/Admin/AdminDashboardProductsData.php`
- Modify: `src/app/Services/Admin/AdminDashboardStatsData.php`

**Interfaces:**
- `AdminDashboardData::forAdmin(User $user, ?string $requestedTab = null, ?string $salesFilter = null, int $productsPage = 1, ?string $productsFilter = null): array`
- `AdminDashboardProductsData::forProducts(bool $canManageProducts, int $productsPage = 1, ?string $activeFilter = null): array`
- `AdminDashboardSalesData::forSales(array $statusCounts, bool $canRetrySalesProof = false, ?string $activeFilter = null): array`
- `statsTab.operationalQueues`: list of `{key, label, count, hint, tone, url}`.

- [ ] **Step 1: Write failing feature tests for queue counts and URLs**

Create active products with available stock 3 and 4, processing and waiting orders, one pending proof order, and one late-paid expired order. Assert:

```php
$this->assertSame(
    ['processing_orders', 'payment_attention', 'stock_reservations', 'low_stock'],
    array_column($data['statsTab']['operationalQueues'], 'key'),
);
$this->assertSame([1, 2, 1, 1], array_column($data['statsTab']['operationalQueues'], 'count'));
$this->assertSame(
    route('admin.dashboard', ['tab' => 'penjualan', 'sales_filter' => 'payment_attention']),
    $data['statsTab']['operationalQueues'][1]['url'],
);
```

- [ ] **Step 2: Write failing destination-filter tests**

Request `/admin?tab=penjualan&sales_filter=payment_attention` and assert both payment exceptions render while an ordinary pending order does not. Request `/admin?tab=produk&products_filter=low_stock` and assert the active product at 3 renders while the product at 4 and an inactive low-stock product do not.

- [ ] **Step 3: Run focused tests and verify RED**

Run: `php artisan test tests/Feature/AdminDashboardOverviewTest.php tests/Feature/AdminDashboardSalesRenderingTest.php`

Expected: failures for missing `operationalQueues`, unsupported `payment_attention`, and ignored `products_filter`.

- [ ] **Step 4: Implement normalized destination filters**

Pass `products_filter` from the controller into `forAdmin()`. In products data, normalize to `all|low_stock` and add this query condition before pagination:

```php
->when($activeFilter === 'low_stock', fn ($query) => $query
    ->where('is_active', true)
    ->whereRaw('stock - reserved_stock <= ?', [3]))
```

Expose `active_filter` and a `filters` collection containing `Semua` and `Stok rendah`, with URLs to the Produk tab.

In sales data, normalize `payment_attention`, append a `Perlu perhatian` filter, and apply:

```php
->when($activeFilter === 'payment_attention', fn ($query) => $query->where(function ($query): void {
    $query->where(function ($query): void {
        $query->where('payment_status', PaymentStatus::Pending)
            ->whereHas('payments', fn ($payments) => $payments->whereNotNull('payment_proof_submitted_at'));
    })->orWhere(function ($query): void {
        $query->whereIn('order_status', [OrderStatus::Cancelled, OrderStatus::Expired])
            ->where('payment_status', PaymentStatus::Paid);
    });
}))
```

- [ ] **Step 5: Build operational queue view models from matching predicates**

Count processing and waiting orders from status counts. Add service methods for payment-attention and active low-stock counts using the same predicates as destination filtering. Return four linked queue cards from `AdminDashboardStatsData`; do not alter the header `summary` payload.

- [ ] **Step 6: Run focused tests and verify GREEN**

Run: `php artisan test tests/Feature/AdminDashboardOverviewTest.php tests/Feature/AdminDashboardSalesRenderingTest.php`

Expected: all focused tests pass.

---

### Task 2: Operational-First Ringkasan

**Files:**
- Modify: `src/tests/Feature/AdminDashboardOverviewTest.php`
- Modify: `src/resources/views/admin/dashboard.blade.php`
- Modify: `src/resources/views/admin/dashboard/stats-tab.blade.php`
- Modify: `src/resources/views/admin/dashboard/products-tab.blade.php`
- Modify: `src/tests/Browser/admin-dashboard-responsive.spec.js`

**Interfaces:**
- Consumes: `statsTab.operationalQueues`, `productsTab.filters`, and `productsTab.active_filter` from Task 1.
- Produces: `section[aria-label="Perlu tindakan"]` and anchors with `data-operational-queue`.

- [ ] **Step 1: Write failing render-order and browser tests**

In the feature test, assert the rendered HTML contains `Perlu tindakan` before `Link toko`, `Pendapatan 7 hari`, and `Pengaturan pembayaran & dukungan`. Assert each queue is an anchor with its exact filtered URL, generic stats appear only in the header section, and payment settings are inside a closed `<details>` element.

In Playwright, parameterize 1280px and 375px viewports. Log in, locate `section[aria-label="Perlu tindakan"]`, and use `expectBefore()` to prove it precedes Link toko, Pendapatan 7 hari, and the settings disclosure. Assert exact href query parameters for all four `data-operational-queue` anchors, follow payment attention and low-stock links, and assert their active filter controls.

- [ ] **Step 2: Run the render test and verify RED**

Run:

```bash
php artisan test tests/Feature/AdminDashboardOverviewTest.php --filter=operational
npm run build && npx playwright test tests/Browser/admin-dashboard-responsive.spec.js -g "prioritizes operational work"
```

Expected: both fail because the current tab starts with Link toko and has no operational section.

- [ ] **Step 3: Render the queue first**

Bind `$operationalQueues` and `$productFilters` in `dashboard.blade.php`. At the top of `stats-tab.blade.php`, render a two-column responsive grid of anchors:

```blade
<section aria-label="Perlu tindakan">
    <h2>Perlu tindakan</h2>
    <div class="mt-3 grid grid-cols-2 gap-3">
        @foreach ($operationalQueues as $queue)
            <a href="{{ $queue['url'] }}" data-operational-queue="{{ $queue['key'] }}" class="admin-dashboard-action min-h-24 rounded-[1rem] border-[3px] border-neo-ink p-3 shadow-[3px_3px_0_0_#050505]">
                <span>{{ $queue['label'] }}</span>
                <strong>{{ $queue['count'] }}</strong>
                <span>{{ $queue['hint'] }}</span>
            </a>
        @endforeach
    </div>
</section>
```

Remove the generic `$statsCards` and status-card sections from Ringkasan. Keep Link toko and earnings after the queue.

- [ ] **Step 4: De-emphasize settings and expose product filters**

Wrap the existing payment-control card in a closed `<details>` whose summary reads `Pengaturan pembayaran & dukungan`. Render product filter pills above product cards, preserving the current active filter in pagination through `withQueryString()`.

- [ ] **Step 5: Run feature and focused browser tests and verify GREEN**

Run:

```bash
php artisan test tests/Feature/AdminDashboardOverviewTest.php tests/Feature/AdminDashboardSalesRenderingTest.php
npm run build && npx playwright test tests/Browser/admin-dashboard-responsive.spec.js -g "prioritizes operational work"
```

Expected: all tests pass with the new content order and links at both viewports.

---

### Task 3: Full Regression Verification

**Files:**
- Verify: `src/tests/Feature/AdminDashboardOverviewTest.php`
- Verify: `src/tests/Feature/AdminDashboardSalesRenderingTest.php`
- Verify: `src/tests/Browser/admin-dashboard-responsive.spec.js`

**Interfaces:**
- Consumes: completed operational data and UI changes from Tasks 1-2.
- Produces: final test evidence and a scoped diff review.

- [ ] **Step 1: Run browser and full focused verification**

Run:

```bash
php artisan test tests/Feature/AdminDashboardOverviewTest.php tests/Feature/AdminDashboardSalesRenderingTest.php
npm run test:browser:admin
```

Expected: feature and complete admin Playwright suites pass without overflow or accessibility regressions.

- [ ] **Step 2: Inspect the final diff**

Run: `git diff -- src/app src/resources/views/admin src/tests docs/superpowers`

Confirm no unrelated Camouproxy or generated files are included.
