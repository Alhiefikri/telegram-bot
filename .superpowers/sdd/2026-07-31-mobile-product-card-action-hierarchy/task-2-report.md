# Task 2 Report: Mobile product card action hierarchy

## Scope

Implemented Task 2 for issue 176 in `/home/alhiefikri/telegram-bot/.worktrees/issue-176-mobile-product-actions`.

## RED evidence

1. Test-first edit added the required Playwright assertions and strict touch target helper to `src/tests/Browser/admin-dashboard-responsive.spec.js` before any production changes.
2. Initial requested RED command:

```bash
cd src
npm run build
npx playwright test tests/Browser/admin-dashboard-responsive.spec.js -g "keeps tabs, cards, and the stock modal usable at Telegram mini app width" --reporter=line
```

Failed before assertions due to missing Node dependencies:

```text
sh: baris 1: vite: command not found
Error: Cannot find package '@playwright/test' imported from .../src/tests/Browser/admin-dashboard-responsive.spec.js
Error: No tests found.
```

3. After `cd src && npm install`, the same requested command reached the new assertion and failed as expected:

```text
Error: expect(locator).toBeVisible() failed
Locator: locator('article:visible').filter({ hasText: 'Browser QA Produk A' }).locator('[data-product-actions]').locator('[data-product-primary-action]').getByRole('button', { name: 'Stok', exact: true })
Expected: visible
Error: element(s) not found
```

The Playwright snapshot showed the rendered actions were accessible as emoji-prefixed labels:

```text
button "➕ Stok"
button "✏️ Ubah"
button "📦 Kelola"
button "Aksi lainnya"
```

4. After the accessible-name fix, the focused test reached the hierarchy geometry assertions and exposed ordering-helper instability:

```text
Error: expect(received).toBeLessThanOrEqual(expected)
Expected: <= 2347.296875
Received:    2356.296875
```

A temporary diagnostic run measured the same product action group in one browser evaluation and showed the intended order was correct:

```json
[
  {"selector":"[data-product-primary-action]","text":"➕ Stok","y":1185.296875,"height":44,"bottom":1229.296875},
  {"selector":"[data-product-primary-action] button","text":"➕ Stok","y":1185.296875,"height":44,"bottom":1229.296875},
  {"selector":"[data-product-secondary-actions]","text":"✏️ Ubah\n    \n                                    \n        📦 Kelola","y":1237.296875,"height":44,"bottom":1281.296875},
  {"selector":"[data-product-more-trigger]","text":"Aksi lainnya\n                                    +","y":1289.296875,"height":44,"bottom":1333.296875}
]
```

## Environment diagnosis

- The earlier login timeout did not reproduce in the final runs.
- The first RED attempt failed before login because `src/node_modules` was missing. Installing project dependencies resolved `vite` and `@playwright/test` availability.
- `npm install` did not leave tracked dependency file changes.
- The strict touch-target check did not reveal any button below 44 CSS pixels, so `src/resources/views/components/admin-dashboard/button.blade.php` was intentionally not changed.

## Production changes made

- `src/resources/views/admin/dashboard/product-card.blade.php`
  - Added exact accessible names for product-card actions: `Stok`, `Ubah`, `Kelola`, `Bagikan`, and `Arsipkan`.
  - Marked decorative emoji spans `aria-hidden="true"`, preserving visible emoji while making button role names match the required hierarchy assertions.
  - Added `class="flex"` to the primary action hook wrapper so its measured geometry matches the button block.

## Test changes made

- `src/tests/Browser/admin-dashboard-responsive.spec.js`
  - Added focused product action hierarchy assertions to the 375x900 admin dashboard test.
  - Added the strict `expectStrictTouchTargetsAtLeast44px(locator)` helper validating width and height without rounding.
  - Removed the existing single-locator height ceiling tolerance and now checks raw width and height directly.
  - Scoped product action hierarchy locators to one product card/action group for stable browser measurements.
  - Stabilized `expectBefore` by sampling both bounding boxes in the same async turn with `Promise.all`.

## GREEN evidence

Final required verification command:

```bash
cd src
php artisan test tests/Feature/AdminDashboardStockFlowsTest.php --filter=test_produk_tab_renders_compact_product_cards_actions_and_stock_modal
npm run build
npx playwright test tests/Browser/admin-dashboard-responsive.spec.js -g "keeps tabs, cards, and the stock modal usable at Telegram mini app width" --reporter=line
```

Final output summary:

```text
PASS  Tests\Feature\AdminDashboardStockFlowsTest
✓ produk tab renders compact product cards actions and stock modal
Tests: 1 passed (29 assertions)

> build
> vite build
✓ built

[1/1] tests/Browser/admin-dashboard-responsive.spec.js:376:1 › keeps tabs, cards, and the stock modal usable at Telegram mini app width
1 passed
```

Exit code: 0.

## Files changed

- `src/tests/Browser/admin-dashboard-responsive.spec.js`
- `src/resources/views/admin/dashboard/product-card.blade.php`
- `.superpowers/sdd/2026-07-31-mobile-product-card-action-hierarchy/task-2-report.md`

Not changed:

- `src/resources/views/components/admin-dashboard/button.blade.php`

## Commits

- `6635316` - `test: verify mobile product action hierarchy`

## Self-review

- Confirmed the test was edited before production changes.
- Confirmed the required assertions and strict helper behavior are present.
- Confirmed no shared button component change was made because no raw strict target below 44 CSS pixels was observed.
- Confirmed no temporary diagnostic `console.log` remains in the Playwright spec.
- Confirmed final focused feature, build, and browser verification passed from fresh command output.

## Concerns

- Because Blade markup and built frontend assets are affected, Docker must be refreshed for a running local stack to pick up the change.
- `npm install` reported one low-severity vulnerability in the existing dependency tree. I did not run `npm audit fix` because that would be unrelated dependency churn.
