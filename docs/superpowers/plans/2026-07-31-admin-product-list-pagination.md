# Admin Product List Pagination Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ensure every non-deleted product remains manageable from the admin dashboard through simple 20-item server-side pagination.

**Architecture:** Pass a dedicated `products_page` query parameter from the dashboard controller into the dashboard data façade and product data service. Paginate the existing product query before loading page-scoped credential summaries, return presentation-ready pagination metadata, and render compact navigation beneath the unchanged card grid.

**Tech Stack:** PHP 8.4, Laravel, Eloquent paginator, Blade, PHPUnit feature tests.

## Global Constraints

- Show 20 products per page, newest first.
- Include active, paused, available, and sold-out products.
- Preserve all existing product-card actions and stock-management payloads.
- Do not change database schema, storefront behavior, inventory calculations, or checkout flows.
- Preserve other dashboard query parameters in pagination URLs.

---

### Task 1: Add failing pagination coverage

**Files:**
- Modify: `src/tests/Feature/AdminDashboardOverviewTest.php`

**Interfaces:**
- Consumes: `GET /admin?tab=produk&products_page={page}` and the existing authenticated admin test helpers.
- Produces: Feature expectations for page-one/page-two visibility and pagination controls.

- [ ] **Step 1: Write the failing feature test**

Create 21 products with deterministic creation times. Mark the oldest product inactive and sold out. Request the products tab and assert the newest product appears while the oldest does not. Request `products_page=2` and assert the oldest product appears with its management controls and a link back to page one.

- [ ] **Step 2: Run the focused test and verify failure**

Run: `cd src && php artisan test tests/Feature/AdminDashboardOverviewTest.php --filter=product_pagination`

Expected: FAIL because the current query always returns only five products and no product pagination exists.

### Task 2: Implement page-aware product data

**Files:**
- Modify: `src/app/Http/Controllers/Admin/AdminDashboardController.php:19-23`
- Modify: `src/app/Services/Admin/AdminDashboardData.php:37-50`
- Modify: `src/app/Services/Admin/AdminDashboardProductsData.php:20-61`

**Interfaces:**
- Consumes: `int $productsPage` from the `products_page` query parameter.
- Produces: `productsTab['products']` as the existing list payload and `productsTab['pagination']` with `currentPage`, `lastPage`, `previousUrl`, `nextUrl`, and page-link entries.

- [ ] **Step 1: Thread the page parameter through the controller and façade**

Read `products_page` as a positive integer with a default of 1. Add `int $productsPage = 1` to `AdminDashboardData::forAdmin()` and `AdminDashboardProductsData::forProducts()`.

- [ ] **Step 2: Replace the fixed limit with pagination**

Replace `latest()->limit(5)->get()` with `latest()->paginate(perPage: 20, pageName: 'products_page', page: max(1, $productsPage))->withQueryString()`. Continue transforming only `$paginator->getCollection()` so stock-item summary queries stay bounded to current-page product IDs.

- [ ] **Step 3: Return compact pagination metadata**

Return the transformed product list plus navigation metadata. Build page links from the paginator URL methods and retain the current query string so `tab=produk` and unrelated dashboard state survive navigation.

- [ ] **Step 4: Run the focused feature test**

Run: `cd src && php artisan test tests/Feature/AdminDashboardOverviewTest.php --filter=product_pagination`

Expected: The data assertions pass, while view-control assertions may remain failing until Task 3.

### Task 3: Render minimal pagination controls

**Files:**
- Modify: `src/resources/views/admin/dashboard.blade.php:22-25`
- Modify: `src/resources/views/admin/dashboard/products-tab.blade.php:60-69`

**Interfaces:**
- Consumes: `productsTab['pagination']` from Task 2.
- Produces: Accessible Previous, numbered page, and Next links below the product card grid.

- [ ] **Step 1: Expose pagination metadata to the partial**

Assign `$productsPagination = $productsTab['pagination'];` alongside `$products` in `dashboard.blade.php`.

- [ ] **Step 2: Add compact navigation markup**

Render navigation only when `lastPage > 1`. Disable unavailable directions using non-link text, mark the current page with `aria-current="page"`, and keep controls large enough for mobile touch without adding filters or extra admin concepts.

- [ ] **Step 3: Run the focused feature test**

Run: `cd src && php artisan test tests/Feature/AdminDashboardOverviewTest.php --filter=product_pagination`

Expected: PASS.

### Task 4: Regression verification and commit

**Files:**
- Verify all files modified by Tasks 1-3.

**Interfaces:**
- Consumes: Completed pagination implementation.
- Produces: Verified, committed change ready for Docker refresh and production release.

- [ ] **Step 1: Run related admin dashboard tests**

Run: `cd src && php artisan test tests/Feature/AdminDashboardOverviewTest.php tests/Feature/AdminDashboardProductFormsTest.php tests/Feature/AdminDashboardStockFlowsTest.php`

Expected: PASS.

- [ ] **Step 2: Run formatting verification**

Run: `cd src && ./vendor/bin/pint --test app/Http/Controllers/Admin/AdminDashboardController.php app/Services/Admin/AdminDashboardData.php app/Services/Admin/AdminDashboardProductsData.php tests/Feature/AdminDashboardOverviewTest.php`

Expected: PASS.

- [ ] **Step 3: Inspect the scoped diff**

Run: `git diff --check && git diff -- src/app/Http/Controllers/Admin/AdminDashboardController.php src/app/Services/Admin/AdminDashboardData.php src/app/Services/Admin/AdminDashboardProductsData.php src/resources/views/admin/dashboard.blade.php src/resources/views/admin/dashboard/products-tab.blade.php src/tests/Feature/AdminDashboardOverviewTest.php`

Expected: No whitespace errors and only pagination-related changes.

- [ ] **Step 4: Commit implementation**

```bash
git add src/app/Http/Controllers/Admin/AdminDashboardController.php \
  src/app/Services/Admin/AdminDashboardData.php \
  src/app/Services/Admin/AdminDashboardProductsData.php \
  src/resources/views/admin/dashboard.blade.php \
  src/resources/views/admin/dashboard/products-tab.blade.php \
  src/tests/Feature/AdminDashboardOverviewTest.php
git commit -m "fix: paginate admin products"
```
