# Admin Dashboard Blade-only Cutover

The admin surface is Blade-only. `/admin` is handled by `App\Http\Controllers\Admin\AdminDashboardController`, and `/admin/login` is handled by the pure Laravel session login controller.

## Current Routing

- `/admin` renders the server-selected Blade dashboard tab inside the shared Variant A sidebar/topbar shell.
- `/admin/login` renders the Blade login form.
- `/admin/products`, `/admin/products/{product}`, and `/admin/products/{product}/stock` are Blade dashboard mutation endpoints for create, update, soft-delete, and stock add flows.
- `/admin/products/{product}/workspace` is the Owner/Admin-only product editor. Five single-open accordions own detail, local stock, supplier sources, pricing, and appearance. GET JSON exposes the same private projection without account credentials or API keys.
- Payment mode and broadcast mutations stay on the same Blade dashboard.
- The frontend stack is intentionally small: Blade templates, Alpine.js for modal/state behavior, and Tailwind CSS v4 through the Vite plugin.

## Removed UI And Runtime Surface

- Filament is not installed as a Composer dependency.
- No Filament panel provider is registered in `bootstrap/providers.php`.
- Filament resources, pages, widgets, published views, and published assets were removed.
- Product creation and stock shortcuts remain dashboard dialogs; editing opens the canonical workspace. Each form saves through existing domain services without navigating away. Section fingerprints reject stale detail/price/appearance writes while unrelated drafts remain in page memory. Supplier mapping and policy retain their version guards.
- Product cards retain their existing tab styling. Navigation uses the shared persistent desktop sidebar, mobile drawer and native no-JavaScript fallback; the shared topbar remains reachable after scrolling.
- Workspace colors are semantic: violet for the active function/actions, cyan for explanatory information, green for active/success, amber for unsaved/stale data, and red for failures/destructive actions. Text accompanies every state; the existing light/dark shell and fonts remain. Predictable accordion rows reduce scanning, spacing separates tasks, and no expansion animation competes with data entry (ENERGY 2 / RHYTHM 1 / MOTION 1).

## Production Build

- `vite.config.js` loads `@tailwindcss/vite`; `resources/css/app.css` owns Tailwind v4 source/theme declarations.
- The Docker image has a dedicated Node build stage that runs `npm ci` and `npm run build`, then copies `public/build` into the PHP runtime image.
- Docker validation must not wipe or reseed a persistent Postgres volume. Use config/build checks by default; use disposable volumes for any boot test that requires database setup.


## Dashboard Split Safety Net

Before splitting `src/resources/views/admin/dashboard.blade.php`, run the targeted dashboard checks from `src/`:

```bash
php artisan test --filter=AdminDashboardTest
npm run test:browser:admin
```

Coverage baseline for the split:

- Admin access: `AdminDashboardTest` covers guest redirects, non-admin forbiddance, and the manual Blade `/admin` route.
- Dashboard render and tabs: `AdminDashboardTest` covers the Blade shell, stats/products/sales tab content, desktop/mobile nav markers, and absence of Filament runtime surface. Active tab content is server-selected and readable without Alpine; the current shell and regression evidence are recorded in [the sidebar cutover notes](agents/admin-dashboard-sidebar-cutover.md).
- Workspace: `AdminProductWorkspaceTest` covers access, secret-free payloads, partial-field ownership, stale writes, tier invariants, catalog scoping, supplier changes and stock JSON actions. `product-workspace.test.js` covers retained drafts/files, serialization, errors, late responses, percentage boundaries and stock callbacks. Browser scenarios cover independent saves, locale copy, mobile tiers and supplier import/mapping.
- Stock modal: `AdminDashboardTest` covers add/manage/delete stock behavior and secret visibility rules; `admin-dashboard-responsive.spec.js` exercises add/manage stock modals at desktop and Telegram Mini App widths.
- Payment mode: `AdminDashboardTest` covers owner/admin toggles and non-manager denial; `admin-dashboard-responsive.spec.js` checks the visible mode controls.
- Store link: `AdminDashboardTest` covers the storefront URL and copy/share handlers; `admin-dashboard-responsive.spec.js` checks visible share actions at compact width.

### Product workspace verification (2026-10-15)

- Local PHP regression filter `AdminProductWorkspace|AdminDashboard|SupplierAdmin|SupplierPricingClarity|ProductFamily|ProductQuantity|ProductStock`: 348 passed, 9 skipped, 3555 assertions. SQLite coverage does not prove PostgreSQL concurrency.
- All JavaScript tests: 143 passed. Production Vite build passed.
- Selected Playwright scenarios (`workspace|supplier catalog forms|paid stock credentials|bulk`): 6 passed.
- Disposable Laravel browser smoke exercised real independent section saves, quick intake, structured-account review/201 creation, mapping, retained detail draft during stock intake, and JSON 422 field feedback without navigation. No provider purchases or production writes were performed.
- UI gate PASS: all five sections checked at 320, 390, 768 and 1365px in light/dark with no document overflow; accordion Enter and supplier dialog Escape exercised. Semantic text contrast measured above AA (information 6.59/9.42, warning 8.15/9.66, success 6.49/10.10 for light/dark).
- Purpose/liveliness gate PASS: existing shell/fonts retained, task-specific accordion hierarchy, semantic colors with visible labels, no decorative motion or invented production content. Temporary prototype HTML/CSS/JS removed after cutover.
- Supplier picker positioning fix: Tailwind Preflight resets the native dialog margin to zero, so `.workspace-dialog` explicitly restores `margin: auto`. Browser regression first failed with a 448px horizontal centering error, then passed at 1568×768, 390×844, and 320×568 after the fix; it also checks viewport containment and Escape focus restoration. Actual desktop/mobile preview screenshots confirmed the correction; production assets rebuilt.

### Workspace query and integrity audit

- Workspace source projections eager-load selected public supplier/catalog columns; encrypted API keys and private catalog descriptions are not hydrated. Quantity-price tiers are read once per projection instead of three times. Source truncation uses a bounded 101-row read rather than a separate count.
- Category/family choices are rendered with the initial HTML, not re-read or returned on every JSON refresh/save. The nested stock component receives only its required payload. Catalog search no longer duplicates each item's catalog projection.
- Disposable SQLite runtime measurement: one source and 25 sources across distinct suppliers both used eight projection queries. With 500 categories and 500 families, the initial projection was 54,745 bytes and the refresh projection 23,141 bytes. These are synthetic local measurements, not production latency guarantees; the catalog endpoint also has query-scaling regression coverage.
- Successful stock writes merge their returned workspace snapshot instead of issuing another workspace GET. Refreshes are single-flight, and a shared mutation gate prevents concurrent stock/form writes. Bulk deletion still reloads manager data and the workspace because its response contains only a deletion count.
- Catalog filter changes, closed/reopened dialogs, debounced searches and late responses cannot reuse an obsolete selection. Price previews are invalidated when their inputs change. Component teardown fences pending responses and clears credential/review/file state; redirected structured-account responses are not treated as successful writes.
- Stock editing validates against the persisted credential format, not the caller's requested format. The locked legacy writer rejects structured items and empty secrets. Regressions cover mismatched formats, encrypted-data preservation, secret-free validation/audits, stale callers and allocated/retired items.
- Explicitly clearing a category no longer falls back to its previous value during family compatibility checks. Assignment checks deleted/missing categories under the authoritative lock. JSON grouping saves require a 64-character `workspace_version` membership fingerprint, checked against locked state; unrelated section edits do not conflict. Service validation errors return JSON 422 rather than a redirect to JSON callers.
- Verification: focused workspace/family/stock-integrity PHP regression passed 78 tests and 556 assertions; all 161 JavaScript tests passed; Vite production build passed; seven selected Playwright regressions passed. A separate real-browser smoke toggled the five accordions twice with zero fetches, opened/closed the centered supplier picker, and observed no document overflow or browser errors. A real HTTP stale-grouping write returned 409 without changing membership, and the refreshed JSON omitted category/family choices.
- Concurrency boundary: category-deletion interleaving and stale-write regressions cover application behavior; SQLite does not prove PostgreSQL lock scheduling. No production traffic, provider purchase, deployment or CI run was exercised for this audit.


## Rollback

There is no supported partial rollback that keeps Filament resources beside the Blade dashboard. Reintroducing Filament would be a new migration: add the Composer package, restore a panel provider, restore resources/assets from version control, and decide which routes own `/admin/*` before deploying.
