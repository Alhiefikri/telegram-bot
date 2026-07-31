# Mobile Product Card Action Hierarchy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Simplify admin product-card actions into one primary action, two direct secondary actions, and an accessible disclosure for uncommon actions, with strict 44×44 CSS-pixel verification at 375×900.

**Architecture:** Keep all behavior inside the existing Blade product-card partial and its per-card Alpine state. Use an in-flow disclosed action panel, existing admin button primitives, and existing routes/payloads. Lock behavior with one server-rendered feature test and focused Playwright assertions.

**Tech Stack:** Laravel Blade, Alpine.js, Tailwind CSS v4, PHPUnit, Playwright.

## Global Constraints

- Show exactly one visually dominant primary action.
- Show no more than two secondary actions directly.
- Keep uncommon actions discoverable through one consistent disclosure control.
- Use `Arsipkan` consistently for the existing archive/delete route.
- Every visible interactive control must render at least 44×44 CSS pixels with no rounding tolerance.
- Verify hierarchy, disclosure reachability, and target sizing at a 375×900 viewport.
- Preserve existing product routes, authorization, Alpine payloads, and backend behavior.
- Do not add dependencies or redesign unrelated dashboard surfaces.

---

### Task 1: Lock the server-rendered hierarchy and terminology

**Files:**
- Modify: `src/tests/Feature/AdminDashboardStockFlowsTest.php:29-70`
- Modify: `src/resources/views/admin/dashboard/product-card.blade.php:1-126`

**Interfaces:**
- Consumes: existing `$product` keys `id`, `edit_payload`, `stock_payload`, `share_url`, `visibility_url`, `visibility_action_label`, and `delete_url`.
- Produces: markup hooks `[data-product-actions]`, `[data-product-primary-action]`, `[data-product-secondary-actions]`, `[data-product-more-trigger]`, and `[data-product-more-panel]` for browser coverage.

- [ ] **Step 1: Replace the six-peer feature assertion with desired hierarchy assertions**

Update `test_produk_tab_renders_compact_product_cards_actions_and_stock_modal()` so the response asserts:

```php
->assertSeeInOrder(['Stok', 'Ubah', 'Kelola', 'Aksi lainnya'])
->assertSee('data-product-actions', false)
->assertSee('data-product-primary-action', false)
->assertSee('data-product-secondary-actions', false)
->assertSee('data-product-more-trigger', false)
->assertSee('data-product-more-panel', false)
->assertSee('aria-expanded="false"', false)
->assertSee('aria-controls="product-more-actions-'.$product->id.'"', false)
->assertSeeInOrder(['Bagikan', 'Pause', 'Arsipkan'])
->assertDontSee('🗑️ Hapus');
```

Keep the existing stock-modal and styling assertions unchanged.

- [ ] **Step 2: Run the feature test and verify RED**

Run:

```bash
cd src
php artisan test tests/Feature/AdminDashboardStockFlowsTest.php --filter=test_produk_tab_renders_compact_product_cards_actions_and_stock_modal
```

Expected: FAIL because the new data hooks, disclosure label, action order, and `Arsipkan` copy do not exist.

- [ ] **Step 3: Implement minimal Blade hierarchy and disclosure**

Extend the card Alpine state with:

```blade
moreActionsOpen: false,
```

Replace the six-column peer-action grid with:

```blade
<div class="mt-4 space-y-2" data-product-actions>
    <div data-product-primary-action>
        {{-- existing Stok button, full width, primary variant --}}
    </div>

    <div class="grid grid-cols-2 gap-2" data-product-secondary-actions>
        {{-- existing Ubah button --}}
        {{-- existing Kelola button --}}
    </div>

    <x-admin-dashboard.button
        type="button"
        variant="neutral"
        size="sm"
        class="w-full"
        data-product-more-trigger
        id="product-more-actions-trigger-{{ $product['id'] }}"
        aria-controls="product-more-actions-{{ $product['id'] }}"
        x-bind:aria-expanded="moreActionsOpen.toString()"
        x-on:click="moreActionsOpen = ! moreActionsOpen"
        x-on:keydown.escape.prevent.stop="moreActionsOpen = false; $nextTick(() => $el.focus())"
    >
        <span>Aksi lainnya</span>
        <span aria-hidden="true" x-text="moreActionsOpen ? '−' : '+'"></span>
    </x-admin-dashboard.button>

    <div
        x-cloak
        x-show="moreActionsOpen"
        id="product-more-actions-{{ $product['id'] }}"
        role="region"
        aria-labelledby="product-more-actions-trigger-{{ $product['id'] }}"
        class="grid grid-cols-1 gap-2 sm:grid-cols-3"
        data-product-more-panel
        x-on:keydown.escape.prevent.stop="moreActionsOpen = false; $nextTick(() => document.getElementById('product-more-actions-trigger-{{ $product['id'] }}')?.focus())"
    >
        {{-- Bagikan button --}}
        {{-- visibility form/button --}}
        {{-- Arsipkan form/button --}}
    </div>
</div>
```

Use `Bagikan` instead of `Share`. Change only the destructive button label from `🗑️ Hapus` to `🗃️ Arsipkan`; retain the existing archive confirmation and delete route. Add `class="w-full"` to each direct and disclosed button, and `class="min-w-0"` to form wrappers if needed.

- [ ] **Step 4: Run the feature test and verify GREEN**

Run the same focused PHPUnit command. Expected: PASS.

- [ ] **Step 5: Commit the server-rendered slice**

```bash
git add src/tests/Feature/AdminDashboardStockFlowsTest.php src/resources/views/admin/dashboard/product-card.blade.php
git commit -m "feat: simplify mobile product card actions"
```

---

### Task 2: Lock mobile hierarchy, disclosure reachability, and strict target sizing

**Files:**
- Modify: `src/tests/Browser/admin-dashboard-responsive.spec.js:376-427,661-687`
- Modify: `src/resources/views/components/admin-dashboard/button.blade.php:8-14` only if the RED run proves rendered geometry below 44 pixels.

**Interfaces:**
- Consumes: product-card data hooks produced by Task 1.
- Produces: reusable `expectStrictTouchTargetsAtLeast44px(locator)` browser helper that validates both width and height without rounding.

- [ ] **Step 1: Add focused Playwright assertions to the 375×900 test**

After locating `productCard`, add:

```js
const productActions = productCard.locator('[data-product-actions]');
const primaryAction = productActions.locator('[data-product-primary-action]');
const secondaryActions = productActions.locator('[data-product-secondary-actions]');
const moreTrigger = productActions.locator('[data-product-more-trigger]');
const morePanel = productActions.locator('[data-product-more-panel]');

await expect(primaryAction.getByRole('button', { name: 'Stok', exact: true })).toBeVisible();
await expect(secondaryActions.getByRole('button', { name: 'Ubah', exact: true })).toBeVisible();
await expect(secondaryActions.getByRole('button', { name: 'Kelola', exact: true })).toBeVisible();
await expect(productActions.getByRole('button', { name: 'Bagikan', exact: true })).toBeHidden();
await expect(productActions.getByRole('button', { name: /Pause|Aktifkan/ })).toBeHidden();
await expect(productActions.getByRole('button', { name: 'Arsipkan', exact: true })).toBeHidden();
await expect(moreTrigger).toHaveAttribute('aria-expanded', 'false');

await expectBefore(primaryAction, secondaryActions);
await expectBefore(secondaryActions, moreTrigger);
await expectStrictTouchTargetsAtLeast44px(productActions);

await moreTrigger.click();
await expect(moreTrigger).toHaveAttribute('aria-expanded', 'true');
await expect(morePanel).toBeVisible();
await expect(morePanel.getByRole('button', { name: 'Bagikan', exact: true })).toBeVisible();
await expect(morePanel.getByRole('button', { name: /Pause|Aktifkan/ })).toBeVisible();
await expect(morePanel.getByRole('button', { name: 'Arsipkan', exact: true })).toBeVisible();
await expectStrictTouchTargetsAtLeast44px(productActions);
```

Use the existing `Kelola` click after these assertions so current modal behavior stays covered.

- [ ] **Step 2: Add a strict target helper and remove ceiling tolerance**

Add:

```js
async function expectStrictTouchTargetsAtLeast44px(locator) {
    const tooSmall = await locator.locator('button, a[href]').evaluateAll((elements) => elements
        .filter((element) => {
            const style = window.getComputedStyle(element);
            const rect = element.getBoundingClientRect();

            return style.display !== 'none'
                && style.visibility !== 'hidden'
                && rect.width > 0
                && rect.height > 0;
        })
        .map((element) => {
            const rect = element.getBoundingClientRect();

            return {
                label: (element.textContent || element.getAttribute('aria-label') || element.tagName).trim(),
                width: rect.width,
                height: rect.height,
            };
        })
        .filter(({ width, height }) => width < 44 || height < 44));

    expect(tooSmall, `touch targets below 44x44 CSS px: ${JSON.stringify(tooSmall)}`).toEqual([]);
}
```

Change the existing single-locator helper assertion from:

```js
expect(Math.ceil(box.height)).toBeGreaterThanOrEqual(44);
```

to:

```js
expect(box.width).toBeGreaterThanOrEqual(44);
expect(box.height).toBeGreaterThanOrEqual(44);
```

- [ ] **Step 3: Run the focused Playwright test and verify RED or environment evidence**

Run:

```bash
cd src
npm run build
npx playwright test tests/Browser/admin-dashboard-responsive.spec.js -g "keeps tabs, cards, and the stock modal usable at Telegram mini app width" --reporter=line
```

Expected before production adjustments: FAIL if any control renders below 44 CSS pixels. If the test instead fails before login, capture the application/server error, repair only the test environment issue if caused by the branch, and rerun until the hierarchy assertions execute.

- [ ] **Step 4: Make the smallest geometry correction if required**

If raw geometry is below 44 pixels, change the shared small button class from:

```php
'sm' => 'min-h-11 px-3 py-2 text-xs shadow-neo-sm hover:shadow-neo-md',
```

to a strict explicit minimum:

```php
'sm' => 'min-h-[44px] min-w-[44px] px-3 py-2 text-xs shadow-neo-sm hover:shadow-neo-md',
```

Do not modify the shared component if all strict measurements already pass.

- [ ] **Step 5: Run focused browser and feature tests and verify GREEN**

```bash
cd src
php artisan test tests/Feature/AdminDashboardStockFlowsTest.php --filter=test_produk_tab_renders_compact_product_cards_actions_and_stock_modal
npm run build
npx playwright test tests/Browser/admin-dashboard-responsive.spec.js -g "keeps tabs, cards, and the stock modal usable at Telegram mini app width" --reporter=line
```

Expected: both pass and Playwright reports no target below 44×44 CSS pixels.

- [ ] **Step 6: Commit mobile browser coverage**

```bash
git add src/tests/Browser/admin-dashboard-responsive.spec.js src/resources/views/components/admin-dashboard/button.blade.php
git commit -m "test: verify mobile product action hierarchy"
```

Omit the button component from `git add` if it was not changed.

---

### Task 3: Regression verification and delivery

**Files:**
- Verify: `src/resources/views/admin/dashboard/product-card.blade.php`
- Verify: `src/tests/Feature/AdminDashboardStockFlowsTest.php`
- Verify: `src/tests/Browser/admin-dashboard-responsive.spec.js`
- Verify: `src/resources/views/components/admin-dashboard/button.blade.php` if changed.

**Interfaces:**
- Consumes: completed Tasks 1 and 2.
- Produces: a clean, committed branch ready for review or merge.

- [ ] **Step 1: Run relevant PHP regression tests**

```bash
cd src
php artisan test tests/Feature/AdminDashboardStockFlowsTest.php tests/Feature/AdminDashboardBladeComponentsTest.php
```

Expected: PASS with no failures.

- [ ] **Step 2: Run the complete admin Playwright suite**

```bash
cd src
npm run test:browser:admin
```

Expected: PASS. Confirm desktop product-management modal coverage still finds `Stok` and `Kelola` directly.

- [ ] **Step 3: Inspect the branch diff for scope and copy**

```bash
git diff main...HEAD --check
git diff --stat main...HEAD
git grep -n "🗑️ Hapus" -- src/resources/views/admin/dashboard/product-card.blade.php
```

Expected: no whitespace errors, only planned files plus design/plan docs, and no `🗑️ Hapus` result in the product card.

- [ ] **Step 4: Confirm clean worktree and commit any final test-only correction**

```bash
git status --short
git log --oneline --decorate -5
```

Expected: clean status and separate documentation, implementation, and browser-test commits. If a final correction was required, commit only its exact files with a descriptive message.
