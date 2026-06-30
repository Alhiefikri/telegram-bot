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

## Rollback

There is no supported partial rollback that keeps Filament resources beside the Blade dashboard. Reintroducing Filament would be a new migration: add the Composer package, restore a panel provider, restore resources/assets from version control, and decide which routes own `/admin/*` before deploying.
