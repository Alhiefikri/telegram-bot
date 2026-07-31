# Admin Siaran Navigation Restoration Design

## Context

The admin dashboard already supports `tab=broadcast`, renders `broadcast-tab.blade.php`, and initializes Alpine with `activeTab: broadcast`. The regression is that both visible navigation variants are hardcoded and omit the Siaran control. Existing browser coverage exercises Produk, Pesanan, and Channel but not Siaran.

## Goal

Authorized admins can discover and open Siaran from desktop and mobile navigation. Opening `/admin?tab=broadcast` shows the Siaran form and recent status while the Siaran navigation control has the active style.

## Design

1. Add one `type="button"` Siaran control to `desktop-navigation.blade.php`.
2. Add the equivalent control to `mobile-navigation.blade.php`.
3. Both controls set `activeTab = 'broadcast'` and bind the existing `bottom-nav-active` class when `activeTab === 'broadcast'`.
4. Reuse the current Alpine state and broadcast view. Do not add routes, change authorization, or modify broadcast delivery behavior.
5. Use the visible Indonesian label `Siaran`, matching the tab heading and issue terminology.

## Testing

- Feature rendering test asserts `/admin?tab=broadcast` emits `activeTab: broadcast`, the Siaran form, status section, and navigation controls.
- Playwright verifies the direct URL at desktop and mobile widths.
- Existing desktop/mobile navigation click coverage clicks Siaran and confirms the heading and active control.
- Run focused feature tests, the admin Playwright suite, and relevant frontend build.

## Non-goals

- Redesigning the navigation system.
- Changing broadcast permissions or queue behavior.
- Adding unsupported placeholder tabs.
- Refactoring all tabs to data-driven rendering.
