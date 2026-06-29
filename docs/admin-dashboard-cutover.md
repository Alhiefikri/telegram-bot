# Admin Dashboard Cutover

Issue #36 makes `/admin` the primary manual Blade/Alpine admin dashboard. Filament stays installed for deeper CRUD resources such as products, orders, payments, categories, customers, audit logs, and manual payment settings.

## Current Routing

- `/admin` is handled by `App\Http\Controllers\Admin\AdminDashboardController`.
- `/admin/products`, `/admin/orders`, and the other Filament resource routes remain registered by `App\Providers\Filament\AdminPanelProvider`.
- The manual dashboard uses `resources/css/app.css` and `resources/js/app.js`.

## Removed Or Bypassed UI

- `App\Filament\Pages\Dashboard` is no longer registered or present.
- Dashboard-only Filament widgets under `App\Filament\Widgets` were removed.
- Dashboard-only widget Blade views and `x-lhie.*` dashboard helper components were removed.
- `resources/css/filament/admin/theme.css` is no longer part of the Vite build or Filament panel registration.

## Rollback

If the manual dashboard must be rolled back, restore the removed Filament dashboard files from the commit before issue #36, then:

1. Restore `App\Filament\Pages\Dashboard`.
2. Restore `App\Filament\Widgets` and the matching `resources/views/filament/widgets` views.
3. Restore `resources/views/components/lhie` if the widgets still use those components.
4. Restore `resources/css/filament/admin/theme.css`.
5. Add `->viteTheme('resources/css/filament/admin/theme.css')` back to `App\Providers\Filament\AdminPanelProvider`.
6. Add the theme entry back to `vite.config.js`.
7. Remove or rename the manual `Route::get('/admin', AdminDashboardController::class)` route if Filament should own `/admin` again.

Keep the Filament resource discovery in place during rollback so existing CRUD URLs remain stable.
