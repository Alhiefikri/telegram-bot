# Admin Dashboard Blade-only Cutover

The admin surface is Blade-only. `/admin` is handled by `App\Http\Controllers\Admin\AdminDashboardController`, and `/admin/login` is handled by the pure Laravel session login controller.

## Current Routing

- `/admin` renders the neobrutalist Blade/Alpine dashboard.
- `/admin/login` renders the Blade login form.
- `/admin/products`, `/admin/products/{product}`, and `/admin/products/{product}/stock` are Blade dashboard mutation endpoints for create, update, soft-delete, and stock add flows.
- Payment mode and broadcast mutations stay on the same Blade dashboard.
- The frontend stack is intentionally small: Blade templates, Alpine.js for modal/state behavior, and Tailwind CSS v4 through the Vite plugin.

## Removed UI And Runtime Surface

- Filament is not installed as a Composer dependency.
- No Filament panel provider is registered in `bootstrap/providers.php`.
- Filament resources, pages, widgets, published views, and published assets were removed.
- Product create/edit/stock/delete actions stay in dashboard modals or inline Blade forms and return to the `produk` tab.
- Product cards use compact neobrutalist action buttons with icon labels; desktop can show product cards in two columns while Telegram Mini App width stays one column with fixed bottom navigation.

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
- Dashboard render and tabs: `AdminDashboardTest` covers the Blade shell, stats/products/sales tab content, desktop/mobile nav markers, and absence of Filament/sidebar runtime surface.
- Product modal: `AdminDashboardTest` covers create/edit validation and mutations; `admin-dashboard-responsive.spec.js` opens and closes the visible modal in a browser.
- Stock modal: `AdminDashboardTest` covers add/manage/delete stock behavior and secret visibility rules; `admin-dashboard-responsive.spec.js` exercises add/manage stock modals at desktop and Telegram Mini App widths.
- Payment mode: `AdminDashboardTest` covers owner/admin toggles and non-manager denial; `admin-dashboard-responsive.spec.js` checks the visible mode controls.
- Store link: `AdminDashboardTest` covers the storefront URL and copy/share handlers; `admin-dashboard-responsive.spec.js` checks visible share actions at compact width.

## Rollback

There is no supported partial rollback that keeps Filament resources beside the Blade dashboard. Reintroducing Filament would be a new migration: add the Composer package, restore a panel provider, restore resources/assets from version control, and decide which routes own `/admin/*` before deploying.
