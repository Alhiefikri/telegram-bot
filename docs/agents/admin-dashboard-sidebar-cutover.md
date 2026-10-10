# Admin Dashboard Sidebar Cutover

Status: implemented in the working tree; deployment is not authorized by issue #600.

## As-built ownership

- `src/resources/views/admin/dashboard.blade.php` owns the authenticated dashboard composition.
- `x-admin-dashboard.shell` owns the page frame, desktop sidebar slot, topbar slot, content slot, and mobile drawer slot.
- `AdminDashboardData::navigation()` and `navigationFor()` remain the only server-built, permission-filtered navigation models.
- `src/resources/js/admin-dashboard/navigation.js` owns drawer state, focus restoration, scroll-lock ownership, breakpoint cleanup, and lifecycle teardown. `activeTab` remains server-owned.
- The dashboard and linked operational shell render the same grouped navigation for desktop, Alpine-enhanced mobile, and the no-JavaScript `<details>` fallback. There is no second dashboard navigation partial or bottom-navigation/More owner.

## Feature parity matrix

| Surface | Canonical destination | Evidence owner |
| --- | --- | --- |
| Ringkasan | `/admin?tab=stats` | dashboard navigation contract and responsive admin browser lane |
| Produk and product create | `/admin?tab=produk` plus `product_form=create` | product forms/stock flows and responsive admin browser lane |
| Pesanan | `/admin?tab=penjualan` | dashboard navigation contract and sales tests |
| Supplier | `/admin?tab=suppliers` | supplier feature/concurrency tests and linked-shell browser coverage |
| Siaran | `/admin?tab=broadcast` | dashboard direct-tab browser coverage |
| Store Channel | `/admin?tab=telegram-channel` | channel feature coverage and direct-tab browser coverage |
| Riwayat KlikQRIS | `/admin?tab=riwayat` | reporting feature coverage and direct-tab browser coverage |
| Withdraw | `/admin?tab=withdraw` | reporting feature coverage and direct-tab browser coverage |
| Funnel | `/admin?tab=funnel` | funnel feature coverage and direct-tab browser coverage |
| Kompensasi top-up | `/admin?tab=balance-issues` | balance adjustment/compensation feature coverage and direct-tab browser coverage |
| Linked operational and finance pages | Existing named routes | linked-shell component and responsive admin browser coverage |

Navigation is presentation-only. Existing controller authorization, route gates, form validation, CSRF, password confirmation, return targets, query filters, and business/domain/API/database/ENV behavior are unchanged.

## Verification record

Run from `src/`:

- `php artisan test --filter=AdminDashboard` — passed, 139 tests / 1,747 assertions.
- `npm run test:js` — passed, 56 tests.
- `npm run build` — passed with Vite production assets.
- `npm run test:browser:admin` — passed, 87 Playwright tests.
- `npm run test:browser:buymail` — passed, 3 Playwright tests.

## Rollback

No database reset or data rollback is required. If the presentation release is rejected, deploy the prior known-good application image/commit through `.github/workflows/deploy-production.yml` from `main`. Record both the released commit and prior image/commit in the GitHub Actions release evidence before deployment. Do not deploy this cutover until the named verification commands and the real-client checklist are complete.
