# Admin Dashboard Sidebar Migration — Variant A

## Status

- **Status:** Proposed implementation specification.
- **Design question settled:** The user selected Variant A. This specification authorizes planning a Blade/Alpine presentation migration, not claims that the unimplemented production layout is already safe in Telegram.
- **Decision:** Persistent grouped sidebar at >=1024px; compact topbar and accessible drawer below 1024px. Keep existing feature ownership, full-page route navigation, and domain behavior. Compatibility and feature parity are release gates.
- **Primary source:** `src/resources/views/admin/dashboard-prototype/` (throwaway visual prototype, variants A/B/C). Variant A is the selected direction.
- **Parent:** [#591](https://github.com/Alhiefikri/telegram-bot/issues/591). [Published full specification](https://github.com/Alhiefikri/telegram-bot/issues/591#issuecomment-6078578976). Child links and dependency graph are recorded in section 15.

## 1. Problem statement

The current dashboard is a single vertical shell with a large summary header, a desktop grid of tab buttons, and a mobile bottom navigation plus "Lainnya" sheet. The dashboard currently exposes Ringkasan, Produk, Supplier, Pesanan, Funnel, Siaran, Store Channel, Riwayat KlikQRIS, Kompensasi top-up, Withdraw, and product creation actions. As the number of operational surfaces grows, the horizontal tab strip becomes a poor information architecture: labels compete for width, secondary features are hidden behind a mobile utility menu, and the summary hero consumes space before the operator reaches the active work surface.

The migration must make the dashboard compact and easier to scan while retaining every current feature, server-side authorization, POST flow, redirect/return-target behavior, Alpine modal workflow, Mini App compatibility, and no-JavaScript form fallback.

## 2. Goals

1. Introduce the Variant A visual hierarchy: clean compact topbar, grouped sidebar, clear active item, operational content area, and restrained status/action styling.
2. Keep the existing `admin.dashboard` route and `?tab=` contract.
3. Keep Laravel controllers and domain services authoritative for data, permission, validation, mutations, and redirects.
4. Keep Alpine.js as the only owner of dashboard browser state; do not add a second navigation state machine.
5. Preserve all existing dashboard features, including product/category/family CRUD, stock intake and management, payment mode and maintenance controls, balance policy/readiness surfaces, order/payment operations, supplier operations, broadcasts, Store Channel, QRIS history, withdraw, funnel, balance issues, and all related modals/forms.
6. Make the shell usable in desktop browsers and Telegram Mini App WebViews, including safe-area spacing, dynamic viewport sizing, touch targets, keyboard navigation, focus restoration, and scroll locking.
7. Reuse existing `x-admin-dashboard.*` components and add only narrowly scoped reusable shell primitives where repeated structure justifies them.
8. Roll out incrementally so every intermediate commit keeps the existing dashboard working.

## 3. Non-goals

- No new route, database table, migration, ENV variable, provider, business rule, payment behavior, authorization rule, or API contract.
- No rewrite of `AdminDashboardController`, `AdminDashboardData`, tab data services, product/stock services, payment services, supplier services, or reporting adapters unless a presentation-only contract correction is required.
- No conversion to Filament, Livewire, React, Vue, a new CSS framework, or a new Alpine plugin.
- No removal of the existing mobile navigation until its replacement has equivalent or better coverage.
- No changing of labels or domain vocabulary merely for visual reasons; use established dashboard/domain terminology.
- No prototype code, switcher, fictional data, or prototype-only CSS enters production.
- No settings migration out of the existing Ringkasan surface in this phase unless a separate ticket is approved; the first migration changes structure and hierarchy, not feature ownership.

## 4. Current implementation baseline

The production entry point is `admin.dashboard`, rendered by one Blade page. `AdminDashboardController` resolves the requested `tab`, enforces special access gates, asks `AdminDashboardData` for the active-tab view model, builds `dashboardState` for Alpine, adds readiness/status data, and renders `admin.dashboard`.

The Blade page currently mounts `x-data="adminDashboard(@js($dashboardState))"`, renders either the full or compact header, desktop navigation, status flash, exactly one active tab subtree, tab-specific modal partials, and mobile navigation. The Alpine root currently owns tab selection, URL navigation, copy state, modal state, focus restoration, form submission guards, product/family/category/stock state, payment/maintenance confirmation state, and other existing workflows. That ownership must remain single-root after migration.

Existing reusable component primitives include card, button, badge, notice, stat-card, mini-stat, section-heading, action-row, action-link, modal-backdrop, modal-shell, form controls, and status-badge. New shell components must compose these primitives rather than duplicate their markup or create a second visual language.

Existing coverage includes Laravel feature tests for access, rendering, data boundaries, products, stock flows, order/payment operations, reporting, Store Channel, return targets, payment controls, Blade components, and Playwright responsive dashboard coverage. Delete obsolete tests of literal wording, incidental CSS classes, source text, and old implementation structure; do not re-pin them to new classes. Preserve behavioral checks and adapt interactions to accessible links/drawer semantics.

## 5. Target information architecture

### 5.1 Desktop sidebar groups

The exact visibility remains permission-driven. A visible item is a presentation of an already authorized destination; the server remains authoritative.

- **Utama**
  - Ringkasan (`stats`)
- **Operasional**
  - Pesanan (`penjualan`)
  - Produk (`produk`)
  - Supplier (`suppliers`, only when currently allowed)
- **Keuangan**
  - Laporan saldo (`admin.store-balance.reports.index`, gated by existing report capability)
  - Kelola saldo (`admin.store-balance.adjustments.index`, Owner and existing readiness gate)
  - Review deposit USDT (`admin.store-balance.usdt-review.index`, existing Owner gate)
  - Kompensasi top-up (`balance-issues`, Owner capability)
  - Riwayat KlikQRIS (`riwayat`)
  - Withdraw (`withdraw`, existing capability)
- **Komunikasi**
  - Siaran (`broadcast`)
  - Store Channel (`telegram-channel`)
- **Insight & sistem**
  - Funnel (`funnel`, existing capability)
  - Pengaturan pembayaran (`admin.dashboard?tab=stats#admin-payment-settings`), an anchor shortcut, not a new tab or route

There are exactly ten existing tab keys: `stats`, `produk`, `penjualan`, `suppliers`, `broadcast`, `telegram-channel`, `riwayat`, `withdraw`, `funnel`, `balance-issues`. `Tambah Produk` remains a contextual Products action and preserves `product_form=create`, not a navigation tab. `stats` is current for all stats anchors; the settings shortcut is not independently marked `aria-current=page`. Separate admin pages retain their existing routes and own current item/parent context.

### 5.2 Topbar

Every active tab receives the same shell topbar:

- mobile menu trigger on small screens;
- breadcrumb/context label based on the canonical active tab;
- current page title;
- store status and checkout maintenance status using existing server-provided values;
- theme toggle using the existing component;
- existing high-value action(s), such as Buka Toko, without duplicating Share/Copy actions in both hero and content unless the active view needs it;
- admin identity/profile presentation without exposing secrets.

The topbar is not a new data source. Labels must be derived from the server-provided navigation/view model or a shared allowlisted map, not arbitrary query-string text.

### 5.3 Ringkasan content hierarchy

Variant A Ringkasan should be reordered as:

1. compact page intro and primary store action;
2. existing summary metrics;
3. operational attention/status blocks;
4. existing earnings and order/payment/status reporting;
5. existing configuration and control sections, including all forms and warnings;
6. existing balance/funding/receiver/payment sections and links.

The migration may reduce decorative hero treatment and duplicate actions, but it must not remove or silently relocate a feature. Any content relocation must retain stable `id` return targets and accessible headings.

## 6. Laravel and Blade design

### 6.1 Shell composition

Refactor the production page toward a shell composition equivalent to:

- `admin.dashboard.shell` or an equivalent shared Blade partial/component for page frame;
- `admin.dashboard.sidebar` for grouped authorized navigation;
- `admin.dashboard.topbar` for context/status/actions;
- one content slot for the existing active tab;
- one mobile drawer/backdrop rendered by the shell;
- existing tab and modal partials unchanged initially.

Use anonymous Blade components for `shell`, `topbar`, `navigation`, and `navigation-item`, with explicit props, named action/status slots, and a content slot. The drawer is part of shell navigation, not a second duplicated menu. Extend the existing navigation payload into canonical groups; all renderers consume this one permission-filtered model. Do not create a shell subclass/service layer or an abstract navigation framework.

### 6.2 Navigation view model

Add a presentation-only navigation builder or extend the existing dashboard navigation payload. It must return grouped items with:

- stable tab/route key;
- label and short label if needed;
- icon identifier from an allowlisted internal icon map;
- active matching rule;
- visibility/capability result calculated from the same server-side role/capability rules;
- optional badge/count only when the value is already available without a new unbounded query;
- destination URL generated through Laravel route/query helpers, preserving existing filters where required;
- optional action metadata for existing product-create behavior.

Do not accept a client-supplied permission or render arbitrary icon HTML. Avoid loading inactive-tab reporting/provider data merely to render navigation.

### 6.3 Route and tab compatibility

Existing query navigation remains canonical during this migration. `goToTab()` must continue to preserve relevant query state and return-target behavior. Direct URLs such as `/admin?tab=produk`, `/admin?tab=penjualan`, `/admin?tab=riwayat`, `/admin?tab=withdraw`, `/admin?tab=funnel`, `/admin?tab=balance-issues`, and `/admin?tab=suppliers` must continue to render the same authorized feature.

A menu item hidden in the UI remains forbidden server-side when unauthorized. Navigation must not turn a hidden item into a client-only security boundary.

### 6.4 Existing forms and modals

All existing POST forms, CSRF tokens, error bags, named error bags, `old()` values, validation messages, redirects, stable return targets, and modal-open query/session conventions remain unchanged. The shell may change spacing and opener placement, but must preserve modal opener references and `x-ref`/focus behavior.

## 7. Alpine.js design

### 7.1 One root, one navigation owner

Keep `adminDashboard(initialState)` as the root owner of business UI state and compose a focused `dashboardNavigation` module into it using the existing account-input composition pattern. Register the same focused module as the sole shell state owner on linked standalone pages that have no `adminDashboard` root. These are separate page instances, not competing owners.

The module owns only `sidebarOpen`, the visible opener reference, breakpoint/scroll-lock lifecycle, and `openSidebar()` / `closeSidebar(restoreFocus = true)` / `initNavigation()` / `destroyNavigation()`. `activeTab` remains server-provided and owned by the existing root. Navigation links use server-built `href`; normal page navigation does not require Alpine.

The root explicitly calls `initNavigation()` before its existing next-tick modal initialization and `destroyNavigation()` from its lifecycle. Spreading a helper must not override the existing root `init()` or modal state. Use `$nextTick` for DOM-dependent focus, cancel stale focus work, keep modal focus helpers centralized, and never leave drawer and a feature modal open as two competing focus traps. Close drawer before launching a product-create action.

No persisted collapsed-sidebar preference, icon-only desktop mode, extra router, or new dependency is needed for the selected A design.

### 7.2 Progressive enhancement

Server-rendered links/buttons must remain meaningful before Alpine initializes. `x-cloak` must prevent only intended interactive overlays from flashing; no-JavaScript navigation must still work through normal links or form submissions. Alpine enhances transitions, drawer state, focus, and URL navigation; it is not the authority for access or mutation.

### 7.3 Drawer behavior

On small screens:

- menu trigger has an accessible name and `aria-expanded`/`aria-controls`;
- drawer has a label and modal/drawer semantics appropriate to the chosen implementation;
- backdrop closes the drawer;
- Escape closes it;
- Tab/Shift+Tab remains inside the drawer while open;
- body scroll is locked only while this component owns the lock;
- closing restores focus to a still-visible trigger;
- navigating to a destination closes the drawer;
- resizing to desktop closes the drawer and releases the lock;
- `destroy()` removes listeners, timers, media-query handlers, and stale focus work.

### 7.4 URL and history

`goToTab()` currently constructs a URL from `window.location.href`, changes `tab`, sets/deletes `product_form`, preserves the other query parameters and hash, then calls `window.location.assign()`. Preserve this full-page navigation contract and browser back/forward behavior; do not replace it with `pushState`, `replaceState`, AJAX tab rendering, or a client router. Server-generated anchor fallback URLs must preserve relevant existing query state and hashes without echoing arbitrary query values into HTML unsafely.

## 8. CSS and responsive design

Use the existing Tailwind/Vite pipeline and existing admin design tokens/components. Add shell-specific classes only where utility composition becomes unreadable or repeated. Do not copy the prototype stylesheet into production.

Target behavior:

- **Desktop (>=1024px):** 228–240px persistent sidebar with full labels; one document content scroll, sticky sidebar/topbar as needed. Sidebar may independently scroll its long navigation. No bottom navigation.
- **Tablet/mobile (<1024px):** topbar plus drawer at every width; no tablet icon rail or accidental wrap. Drawer width `min(320px, calc(100vw - 32px))`; close button and backdrop always reachable.
- **Mini App:** CSS `100vh` fallback then `100dvh`; consume available Telegram safe/content-safe-area CSS variables as well as `env(safe-area-inset-*)`, and avoid double-padding overlapping insets. CSS env alone does not cover Telegram chrome. Preserve browser operation when SDK/variables are absent.
- **Dark/theme mode:** existing dashboard explicit/system theme preference remains authoritative. Do not introduce competing Telegram-driven theme state. If a Telegram bridge is necessary for measured inset/viewport gaps, it only adapts layout and cleans up listeners; it never authenticates or changes business state.
- **Touch/accessibility:** >=44px interactive touch targets, visible focus, reduced-motion support, WCAG AA text contrast (4.5:1 normal, 3:1 large), labeled icons, no color-only status.

## 9. Component plan

Reuse:

- `x-admin-dashboard.card`, `button`, `badge`, `notice`, `stat-card`, `section-heading`, `action-row`, `action-link`, `modal-backdrop`, `modal-shell`, and form primitives.

Potential new reusable components:

1. `x-admin-dashboard.shell` — page frame and responsive layout slots.
2. `x-admin-dashboard.sidebar` — grouped navigation rendering a canonical item collection.
3. `x-admin-dashboard.navigation-item` — active/disabled/badge/accessible item styling.
4. `x-admin-dashboard.topbar` — context/status/action layout.
5. `x-admin-dashboard.mobile-drawer` — only if the existing modal/drawer primitives do not already cover the required semantics.

Do not create a component solely to wrap one line of markup. New components must have a stable attribute merge contract and be covered by component rendering tests where their behavior is nontrivial.

## 10. Feature preservation matrix

| Existing feature | Target treatment | Preservation gate |
|---|---|---|
| Ringkasan metrics, earnings, order status | Reorder into Variant A content hierarchy | Same server values and headings remain available |
| Store link/share/copy | Deduplicate presentation carefully; keep working actions | Copy/share behavior and fallback remain covered |
| Payment mode/provider readiness | Keep existing controls and named errors | Owner/Admin authorization and POST behavior unchanged |
| Checkout maintenance | Keep confirmation modal and state | Confirmation text, CSRF, redirect, and state unchanged |
| Balance/funding/USDT controls | Keep existing sections/readiness links | No data or unit changes; inactive data not eagerly loaded |
| Product CRUD | Existing tab/modal inside new shell | CRUD, validation, image/icon/family/category behavior unchanged |
| Product Family/category management | Existing modals/openers preserved | Focus restoration and errors unchanged |
| Stock input/structured account input | Existing modal workflows preserved | Intake, secrets redaction, revision safety unchanged |
| Stock item management | Existing modal and endpoint preserved | Selection, delete, lock/error behavior unchanged |
| Pesanan/payment operations | Existing sales tab | Filters, order/payment actions, pagination unchanged |
| Supplier | Existing supplier tab and capability gate | Role gate and data states unchanged |
| Broadcast | Existing broadcast tab/form | Named errors, warning, no secret leakage unchanged |
| Store Channel | Existing channel tab | Config/test/status behavior unchanged |
| Riwayat KlikQRIS | Existing reporting tab | Provider read-only/mismatch states unchanged |
| Withdraw | Existing capability-gated tab | Filters/pagination/safe proof state unchanged |
| Funnel | Existing capability-gated tab | Filters/report data and query boundaries unchanged |
| Balance issues/compensation | Existing Owner-only tab | Readiness/financial mutation safeguards unchanged |
| Mobile “Lainnya” | Replaced by drawer grouped navigation | Every formerly reachable destination remains reachable |
| Theme toggle/status flash | Move into shared shell | Theme and flash semantics unchanged |

## 11. Security and data boundaries

- Keep server authorization checks in the controller/routes/policies; sidebar visibility is not authorization.
- Never put stock credentials, provider secrets, raw Telegram tokens, or management-only payloads into navigation or shell data.
- Navigation counts/badges must use bounded existing data or be omitted; do not add N+1 queries or unbounded reporting calls to every dashboard request.
- Keep `admin.no-cache` and Mini App WebView cache behavior intact.
- Preserve CSRF, named error bags, and server validation.
- Do not add presentation configuration to ENV. Admin-editable presentation remains database/admin controlled per repository policy.

## 12. Rollout and commit sequence

Every step leaves a working dashboard:

1. Add/adjust navigation view-model tests and document the target mapping without changing rendering.
2. Add reusable shell/navigation component contracts and component tests, initially behind the existing markup or unused in production.
3. Add the new desktop shell/sidebar behind a development-only or testable feature seam if needed; render existing tab partials unchanged.
4. Migrate topbar/header and active-tab content wrapper; preserve all existing IDs and return targets.
5. Migrate Ringkasan hierarchy and remove only confirmed duplicate decoration/actions.
6. Migrate Products and its modals, then Sales/Orders and Supplier.
7. Migrate Communications, Finance/Reporting, Funnel, and Balance Issues, preserving role gates.
8. Replace mobile bottom navigation with the drawer after browser coverage proves parity.
9. Remove old desktop/mobile navigation partials and stale CSS/classes/tests; do not leave aliases or dead branches.
10. Run focused Laravel tests, JavaScript tests, Vite build, responsive Playwright lane, and Mini App-like viewport smoke. Update `docs/agents/codebase.md` and add a cutover note when shipped.

Recommended rollout is a clean cutover within the dashboard route after parity is proven, not a long-lived dual-navigation mode.

## 13. Verification strategy

### Laravel feature/component tests

- Shell renders for Owner/Admin and does not render unauthorized navigation items.
- Every canonical tab renders from a direct URL with the same active content and status flash.
- Existing POST workflows still redirect to the same `admin.dashboard?tab=...` and stable return target.
- Component attributes, accessible labels, merged classes, dark mode tokens, and minimum touch targets render correctly.
- Stats request does not load inactive reporting/provider data or introduce unexpected query families.
- No secrets are present in shell/navigation response content.

### JavaScript unit tests

Following the existing Node test pattern:

- initial active tab and navigation model;
- open/close drawer and focus restoration;
- Escape and backdrop behavior;
- Tab containment;
- scroll-lock ownership and cleanup;
- resize-to-desktop cleanup;
- navigation closes drawer and preserves URL/filter parameters;
- stale/destroyed components do not restore focus or mutate state.

### Playwright responsive tests

At minimum:

- desktop 1280×900: sidebar visible, bottom navigation absent, active item correct;
- tablet boundary widths: no overlap or horizontal overflow;
- mobile 375×900: trigger/drawer visible, drawer navigation reaches every permitted destination, fixed controls do not cover content;
- direct URL for every tab has the correct active sidebar item;
- browser back/forward preserves active tab and relevant filters;
- product modal, stock modal, payment confirmation, more complex forms, and error return focus correctly;
- no-JavaScript/server links remain usable where existing contracts require it;
- Mini App-like safe-area/dynamic viewport smoke using the browser lane's supported emulation or CSS assertions.

### Manual visual review

Compare Variant A against the existing dashboard with representative Owner and Admin accounts. Review dense product/order tables, long errors, empty states, dark mode, large font/zoom, Telegram WebView-like narrow widths, and all gated menu variants.

## 14. Acceptance criteria for the parent migration

- Variant A shell is live on the existing admin dashboard route.
- All current dashboard features remain available and authorized exactly as before.
- No business/domain/API/database/ENV behavior changes are introduced.
- Desktop uses a grouped sidebar; mobile uses an accessible drawer or equivalent compact navigation.
- Existing Alpine workflows remain single-owner, keyboard-safe, focus-safe, and cleanup-safe.
- No horizontal overflow or fixed-control occlusion at supported desktop/tablet/mobile widths.
- Existing focused Laravel, JavaScript, Vite, and Playwright checks pass; new shell tests cover the changed behavior.
- Prototype-only files remain outside production rendering and are clearly marked throwaway.
- Old navigation code and stale CSS/tests are removed after cutover.
- Documentation points to the final shell ownership, navigation model, and test map.

## 15. Child tickets

The following tickets are intentionally small and ordered. Each ticket must keep the dashboard working and must not expand scope without updating this spec. GitHub dependency edges are added so a child is not considered ready until its blocker is closed.

1. [#592 — Navigation contract and feature matrix](https://github.com/Alhiefikri/telegram-bot/issues/592) — canonical grouped view model, permission mapping, tab compatibility, tests. Blocker for shell.
2. [#593 — Reusable dashboard shell primitives](https://github.com/Alhiefikri/telegram-bot/issues/593) — shell/sidebar/topbar/navigation-item components and component contracts. Blocked by #592.
3. [#594 — Desktop Variant A shell](https://github.com/Alhiefikri/telegram-bot/issues/594) — persistent sidebar, compact topbar, content frame, existing tabs mounted unchanged. Blocked by #593.
4. [#595 — Alpine navigation drawer](https://github.com/Alhiefikri/telegram-bot/issues/595) — mobile drawer, focus, Escape, scroll lock, breakpoint cleanup, URL navigation. Blocked by #593 and #594.
5. [#596 — Ringkasan Variant A content migration](https://github.com/Alhiefikri/telegram-bot/issues/596) — compact hierarchy, metrics, attention, earnings, controls, no feature loss. Blocked by #594.
6. [#597 — Operational tab migration](https://github.com/Alhiefikri/telegram-bot/issues/597) — Products/stock/family/category, Sales/Orders, Supplier inside the shell. Blocked by #594 and #595.
7. [#598 — Communication, finance, reporting, and insight tab migration](https://github.com/Alhiefikri/telegram-bot/issues/598) — Broadcast, Store Channel, QRIS history, Withdraw, Funnel, Balance Issues and gated links. Blocked by #594 and #595.
8. [#599 — Theme, Mini App, accessibility, and responsive hardening](https://github.com/Alhiefikri/telegram-bot/issues/599) — safe areas, dynamic viewport, dark mode, touch/keyboard/overflow. Blocked by #595–#598.
9. [#600 — Regression verification and clean cutover](https://github.com/Alhiefikri/telegram-bot/issues/600) — focused Laravel/JS/Playwright tests, removal of legacy navigation/dead CSS, docs, and release evidence. Blocked by #596–#599.

## 16. Implementation handoff: source map and resolved decisions

### 16.1 Read set

- Root/controller/view models: `src/resources/views/admin/dashboard.blade.php`, `src/app/Http/Controllers/Admin/AdminDashboardController.php`, `src/app/Services/Admin/AdminDashboardData.php`, `AdminDashboardStatsData.php`, `AdminDashboardProductsData.php`, `AdminDashboardSalesData.php`, `AdminSupplierDashboardData.php`, `AdminProductDashboardState.php`, `AdminDashboardRedirector.php`.
- Shell to replace: `src/resources/views/admin/dashboard/header.blade.php`, `compact-header.blade.php`, `desktop-navigation.blade.php`, `mobile-navigation.blade.php`. Tab partials remain in that same directory.
- Browser ownership: `src/resources/js/app.js`, `src/resources/js/admin-dashboard.js`, and the existing `admin-dashboard/account-input-workflow.js` composition pattern. One Admin entrypoint and one `Alpine.start()`; keep Storefront entry separate.
- Presentation: `src/resources/views/components/admin-dashboard/`, `src/resources/css/app.css`, existing theme initialization/toggle.
- Test map: `src/tests/Feature/AdminDashboardAccessTest.php`, `AdminDashboardOverviewTest.php`, `AdminDashboardBladeComponentsTest.php`, `AdminDashboardReturnTargetsTest.php`, `AdminDashboardPaymentModeTest.php`, `AdminDashboardProductFormsTest.php`, `AdminDashboardStockFlowsTest.php`, `AdminDashboardSalesRenderingTest.php`, `AdminDashboardReportingTest.php`, `AdminOrderPaymentOperationsTest.php`, `AdminStoreChannelTest.php`, `AdminBalanceAdjustmentDashboardTest.php`; `src/tests/JavaScript/`; `src/tests/Browser/admin-dashboard-responsive.spec.js`, `buymail-warranty.spec.js`.

The existing `navigation.tabs` also serves tab normalization. Extend/replace its presentation without accidentally changing unknown-tab fallback to `stats`, special controller gates, or `AdminProductDashboardState` inputs. Do not replace `forActiveTab()` with the eager `forAdmin()` builder to populate the sidebar.

### 16.2 Modern visual contract

Variant A is a structure and density reference, not fake business data to reproduce. Keep existing fonts/build pipeline. Use an Admin-scoped neutral background, white/dark surfaces, 1px separators, 8–12px corners, little/no hard-offset shadow, regular case labels, medium-weight metadata, and one muted violet primary accent. Success/warning/danger remain semantic colors with text labels. Desktop spacing: 24–32px content inset, 16–24px section gaps. Mobile: 16px inset, >=14px body/form text where readable and >=44px controls. Small metadata may be smaller but never primary navigation/control text solely to fit.

Restyle existing Admin primitives rather than layering a second `modern=true` component family or adding CSS specificity battles. #593 owns primitive defaults and theme tokens; #596–#598 own raw legacy tab markup that bypasses primitives. Replace inline legacy borders/shadows/uppercase/palette classes locally while preserving behavioral attributes. Audit primitive consumers including login/password pages; avoid accidental public Storefront changes. No global `button`, `input`, or `.card` selector override.

Desktop content uses one document scroll (not a permanently fixed-height nested content pane). Tables may scroll locally horizontally when genuinely wider than the viewport; the page itself must not overflow. `min-width:0` on grid/flex children; sidebar has its own vertical overflow only when navigation exceeds height. Skip link targets the main content; sticky topbar must not cover hash/focused return targets. Z-order: shell < drawer/backdrop < existing feature modals; opening a modal closes drawer first.

Keep the four real existing summary values, existing day/earnings boundaries, and IDR/USDT separation. Do not implement the prototype's fabricated growth percentages, sample recent-order table, fake task counts, or demo supplier health. Attention blocks may only project existing authoritative data, explicitly distinguishing payment received, delivery pending, and review—not invent new queries or money authority.

### 16.3 Linked workflow pages

Feature parity includes existing linked workflow pages, not only ten tab bodies. Use the reusable shell on these authenticated pages while preserving route/middleware/forms/data scopes:

- Operational ownership (#597): `admin.orders.show`; Manual Fulfillment Review index/detail; Manual Delivery Review (`admin.telegram-outbound-effects`) index/detail; supplier catalog/import, product mapping/pricing, purchases/recovery, warranty/refund/replacement, and BuyMail deposit/history. Existing routes and controllers remain canonical; child pages activate Pesanan or Supplier context without claiming they are new tabs.
- Financial ownership (#598): balance reports (`admin.balance-reports.index` view), IDR adjustment/reversal (`admin.store-balance.adjustments` view), USDT review/incident (`admin.store-balance.usdt-review` view). Existing Owner/readiness/report gates remain separate and password-bound financial confirmations remain unchanged.
- Products (#597): storefront banners, Telegram welcome/banner upload and clear controls, default catalog image, localized product/family content and icons/custom-emoji metadata, quantity price tiers, grouping, stock secrets redaction, bulk stock delete, and structured/legacy intake are preserved. The prototype's simple product tiles do not replace these real controls.
- Auth login and password-confirm surfaces are not converted into an authenticated sidebar. Their forms, intended redirects, theme and shared primitive styling are checked for regression.

Shared navigation on child pages must be lightweight. Do not call the full dashboard builder merely to obtain page chrome. Existing child-page-only data must stay scoped and may not be leaked into serialized Alpine state.

### 16.4 No-JavaScript and permission details

Use real server-generated anchors for navigation; give drawer enhancement a no-JavaScript fallback (for example a nonmodal `<details>` navigation rendered from the same groups when Alpine is unavailable). Do not leave a hidden-only menu requiring JavaScript for basic destination links. This does not promise that existing JS-dependent stock/modal enhancements become fully no-JS workflows.

Current Owner/Admin dashboard-access rules, login allowlist narrowing, `admin.no-cache`, CSRF, sessions, and password-confirm remain unchanged. Finance/Support do not gain dashboard access because a financial route exists. Gates that depend on database readiness are reused, not approximated from role strings or inferred SDK identity. Exported JavaScript changes require references/caller inspection before changing their interface.

## 17. Per-ticket working commits and evidence

Each ticket maintains its own green state, rather than postponing all verification to #600:

| Ticket | Small commit sequence | Minimum evidence |
|---|---|---|
| #592 | Record tab/route/capability mapping; extend navigation payload; exercise allowed/disallowed destinations and lazy data loading | PHP behavioral checks for direct URLs/role gates and no inactive-provider fetch |
| #593 | Define neutral Admin tokens; migrate existing primitives; add shell/topbar/nav components; check all existing primitive consumers | Render real Blade pages/components and inspect actual browser light/dark controls, not class-name snapshots |
| #594 | Mount sidebar/frame; unify topbar; preserve content/modal/return-target DOM; replace desktop tab strip | Launch Laravel and navigate real desktop tabs, direct URLs and back/forward |
| #595 | Compose drawer lifecycle; add labeled trigger/backdrop/focus/scroll behavior; adapt mobile navigation tests; remove superseded More state at cutover | JS transition tests and real mobile drawer open/close/resize/product-create |
| #596 | Extract logical stats partials where helpful; reorder real summary/earnings; restyle every existing control; deduplicate store actions | Valid and invalid payment/maintenance/settings submissions in isolated fixtures; hash/error focus and units checked |
| #597 | Products/intake/modals; orders/reviews; supplier linked pages, in separate runnable commits | Existing feature tests plus browser representative CRUD/intake/error/recovery-navigation smoke |
| #598 | Communications; provider reports; balance/USDT linked pages, in separate runnable commits | Permission, filter, unavailable-provider, validation and password/readiness gate checks |
| #599 | Breakpoint/safe-area; theme/contrast; keyboard/zoom/overflow; real Telegram checks | Desktop/tablet/mobile browser screenshots plus actual Telegram Android and iOS checklist |
| #600 | Cross-ticket integration check; delete remaining obsolete markup/state/CSS; as-built documentation and evidence | Full named verification matrix and no old navigation owner or demo production artifact |

No development feature flag, new ENV, or dual production shell is necessary. #593 adds unused shell primitives safely; #594 swaps desktop while retaining existing mobile navigation; #595 swaps mobile in a working commit. A rollout does not depend on deploying a half-migrated layout.

### Verification commands

Run from `src/` after reading the existing browser fixture/environment setup:

- `php artisan test --filter=AdminDashboard` for dashboard behavior; include impacted order/payment, balance, supplier, warranty, channel, and auth feature classes rather than assuming this filter covers linked workflows.
- `npm run test:js` (Node built-in runner, existing Alpine module conventions).
- `npm run test:browser:admin` (already builds Vite and runs the responsive browser file).
- `npm run test:browser:buymail` for affected supplier/deposit/warranty browser workflows.
- `npm run build` when build evidence is needed independently; do not repeat the build immediately if the browser script already ran it.

Feature tests protect actual permission/validation/redirect/money-unit/secret boundaries. Permanent JS tests protect drawer state transitions/races/focus/cleanup. Browser tests protect consumer-visible navigation and feature operations, not exact border classes or changed copy. Remove old incidental/source-text tests instead of rewriting them around new implementation details.

### Telegram release checklist (human evidence required)

CSS viewport emulation is not proof of Telegram client compatibility. On real Android and iOS Telegram, record client/platform versions and check: launch existing admin entry; login/session; collapsed/expanded WebView; safe/content-safe insets; keyboard opening on form fields; scroll/focus to validation; drawer open/close/backdrop; rotation and viewport changes; theme; product modal/stock input; copy/share/open-store fallback; return from password confirmation and linked workflow pages. Never use real financial mutations for design verification: isolated/staging fixtures only.

Official Telegram supports `safeAreaInset`, `contentSafeAreaInset`, `viewportStableHeight`, and related change events; page CSS alone may not provide them when no SDK is loaded. Audit the current SDK/entry integration before adding an optional layout-only adapter. Use a single adapter with listener cleanup if required by measured gaps, test absence/old clients, and do not change launch URL, authentication, or introduce fullscreen/theme behavior just for this redesign. If real-client access is unavailable, leave #599/#600 release acceptance incomplete and record the exact missing evidence.

### Rollback / release

No deployment is authorized by this planning issue. Release via the existing GitHub Actions production workflow only after integration evidence and human Mini App acceptance. As a presentation-only release with no migrations, rollback targets the prior known-good app image/commit through the existing release mechanism; record that image/commit in #600. Do not reset user work or change database state to roll back a layout.

## 18. References and planning verification

- [Laravel 13 Blade](https://github.com/laravel/docs/blob/13.x/blade.md): anonymous components, `@props`, named slots, `$attributes->class/merge`, escaped attributes. Prefer `x-bind:*` or Blade's escaped colon syntax so Alpine expressions are not mistaken for PHP props; serialize via existing `@js`/Laravel JS escaping, not raw string concatenation.
- [Alpine.data](https://alpinejs.dev/globals/alpine-data), [lifecycle](https://alpinejs.dev/essentials/lifecycle): registration before one `Alpine.start()`, explicit composition lifecycle, `destroy()` cleanup, next-tick focus.
- [Telegram Mini Apps](https://core.telegram.org/bots/webapps#design-guidelines), [initialization/viewport](https://core.telegram.org/bots/webapps#initializing-mini-apps), [events](https://core.telegram.org/bots/webapps#events-available-for-mini-apps): device and Telegram content safe areas are distinct; dynamic viewport and real-client testing are required.
- Prior-art repository specification: `2026-09-25-alpine-ui-refactor-and-test-coverage.md`.

This deliverable is planning only: production runtime code has not been migrated, and no claim is made that implementation tests passed. Planning verification consists of the source inventory, framework documentation lookup, saved spec, and published GitHub issue/body/sub-issue/dependency checks. The canonical full specification is also attached to the parent issue so it is usable before the local spec is committed/pushed to `main`; a `blob/main` link alone is not publication evidence.
