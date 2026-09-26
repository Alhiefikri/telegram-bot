# Alpine.js UI Refactor and JavaScript Regression Coverage

## Status

- **Status:** As-built design record. The refactor shipped, and this document records the delivered state. It authorizes no new behavior and proposes no follow-up implementation.
- **Specification date referenced by the issue family:** 2026-09-25.
- **As-built commit:** `2c5dab53` (`feat(storefront): cut over initializers to Alpine and verify UI bundles`) on `main`.
- **Parent issue:** [#503 — Alpine.js UI refactor and JavaScript regression coverage](https://github.com/Alhiefikri/telegram-bot/issues/503).
- **Child tickets:** [#504](https://github.com/Alhiefikri/telegram-bot/issues/504) (entrypoint split), [#505](https://github.com/Alhiefikri/telegram-bot/issues/505) (Family quick-buy), [#506](https://github.com/Alhiefikri/telegram-bot/issues/506) (checkout review), [#507](https://github.com/Alhiefikri/telegram-bot/issues/507) (Admin Account Input and reviewed defects), [#508](https://github.com/Alhiefikri/telegram-bot/issues/508) (mobile navigation), [#509](https://github.com/Alhiefikri/telegram-bot/issues/509) (cutover and verification).
- **Delivery order that shipped:** #504 first; #505, #506, #507, #508 on top of it; #509 last.
- **Baseline for this record:** [CONTEXT.md](../../../CONTEXT.md), [docs/agents/codebase.md](../../agents/codebase.md), the Vite build in [`src/vite.config.js`](../../../src/vite.config.js), and the CI workflow [`.github/workflows/ci.yml`](../../../.github/workflows/ci.yml).
- **Audience:** reviewers of #503–#509 who need the canonical as-built record that the closed child tickets reference.

Repository convention: this file is documentation only. Later changes to the browser modules must follow [docs/agents/codebase.md](../../agents/codebase.md) and the invariants in section 3; they are not pre-authorized here.

## 1. Goal and outcome

The goal was to give each browser-side UI surface exactly one state owner, separate the Admin and Storefront bundles, and add durable JavaScript regression coverage that runs without a browser.

Delivered outcome:

1. `src/resources/js/app.js` is the Admin entry. It no longer imports or boots the Storefront controller.
2. `src/resources/js/storefront-entry.js` is the single Storefront entry for the public homepage, internal Storefront pages, and Storefront error pages.
3. Family quick-buy, checkout review, and mobile navigation state moved into focused Alpine components registered through one Storefront registry; the superseded imperative initializers were deleted from `storefront.js`.
4. The Admin Account Input draft, review, and submission workflow moved into a testable module composed into the existing Alpine root, and the three reviewed Admin defects plus the Family keyboard gap were fixed with regression tests.
5. `npm run test:js` runs 37 Node unit tests over the Alpine contracts and is enforced in CI.
6. The existing focused Admin and customer Playwright lanes and the Vite build still pass, and `docs/agents/codebase.md` documents the new test map and the retained imperative services.

## 2. Scope

### 2.1 Delivered in scope

- Entrypoint split and Storefront Alpine registry (#504).
- Family quick-buy Alpine module with keyboard navigation (#505).
- Storefront checkout review Alpine module with sticky-action lifecycle cleanup (#506).
- Admin Account Input workflow module plus the reviewed defect fixes (#507).
- Storefront mobile navigation Alpine module (#508).
- Initializer cutover, test-map documentation, and integration verification (#509).

### 2.2 Non-goals that were preserved

- No CSS redesign, route change, database change, migration, ENV variable, provider behavior, payment contract, or business rule.
- No new framework or dependency. `alpinejs` (`^3.15.12`) was already installed, and the Node unit lane uses the built-in `node --test` runner, so `src/package.json` only gained a script.
- No rewrite of polling, event transport, analytics, observer, or timer services that legitimately need imperative browser APIs.
- No change to `POST /shop/checkout`, Cart, Product, Family, Admin stock, or Stock Intake server contracts.
- No production deploy was part of the tickets; deployment stays owned by the approved release workflow from `main`.

The issue-family diff (`6999983c..2c5dab53`) touches 33 files and contains no `routes/`, `database/`, `config/`, `.env`, or `composer.*` change; the only `src/package.json` change is the added `test:js` script.

## 3. Invariants

These are the properties later changes must not break.

1. **One registry, one `Alpine.start()` per entry.** `src/resources/js/storefront/alpine-components.js` exports `registerStorefrontComponents(Alpine)` and registers `familyQuickBuy`, `checkoutReview`, and `mobileMenu`. Registration happens before the single `Alpine.start()` call in each entry (`app.js`, `storefront-entry.js`).
2. **No duplicate state owner.** Family, checkout review, and mobile navigation each have exactly one Alpine component. No legacy selector/listener initializer for those surfaces remains in `storefront.js`, and `storefront.js` does not import the Alpine modules.
3. **No-JavaScript forms and links stay intact.** Server-rendered submit labels, `form="checkout-form"` associations, radio inputs, and Family/Product links remain functional without JavaScript. Alpine only owns enhanced presentation.
4. **Checkout totals are advisory; the server is authoritative.** `checkoutReview` renders a client-side quote from server-provided tier data. `OrderCheckoutService` and `CheckoutPricingSnapshot` remain the canonical checkout transaction; browser-supplied price or quote values never become POST authority.
5. **Imperative services stay isolated with cleanup.** Async browser services in `src/resources/js/storefront.js` keep their own boot/cleanup discipline, and the Alpine components that attach listeners, observers, timers, or frames release them in `destroy()`.
6. **No new framework, route, database, ENV, provider, or business rule.** The refactor stays inside the browser bundle, Blade presentation, tests, CI, and agent docs.

## 4. Pre-implementation review evidence

The review that produced #503 found:

- `app.js` imported both the Admin controller (46.8 KB) and the Storefront controller (56.2 KB), and internal Storefront pages and Admin pages both loaded it; the public homepage used a separate entry without Alpine.
- Family and checkout browser state was imperative inside a 1,963-line initializer; Admin state lived in a 1,574-line Alpine controller.
- No JavaScript unit-test command existed.

Review defects confirmed at that point:

1. Product create/edit reset the Telegram image filename but kept the previous file input value.
2. An Account Input review response could certify a newer draft edited while the old request was pending.
3. Editing a Product Family inside the already-open manager overwrote the original focus opener with a hidden control.
4. Family option anchors claimed radio-group semantics but lacked arrow-key navigation.

## 5. Bundle split and registration order

### 5.1 Admin entry — `src/resources/js/app.js`

Imports `alpinejs`, `adminDashboard` + `salesFilter` from `./admin-dashboard`, and `./theme`. It assigns `window.Alpine`, initializes the theme, registers `adminDashboard`, `salesFilter`, and `passwordReveal`, then calls `Alpine.start()` once. It contains no Storefront import.

Admin Blade views (`welcome`, `admin/*`, `auth/*`) request `resources/js/app.js` through `@vite`.

### 5.2 Storefront entry — `src/resources/js/storefront-entry.js`

Imports `alpinejs`, `registerStorefrontComponents` from `./storefront/alpine-components`, `initStorefront` from `./storefront`, and `./theme`. It assigns `window.Alpine`, registers the Storefront components, initializes the theme, calls `Alpine.start()` once, and then boots the retained imperative services through `initStorefront()`.

Storefront and error pages request `resources/js/storefront-entry.js`:

- `src/resources/views/storefront/partials/head.blade.php` loads `resources/css/storefront.css` on the public homepage and `resources/css/app.css` elsewhere, always with `resources/js/storefront-entry.js`;
- `src/resources/views/errors/partials/storefront-page.blade.php` loads `resources/css/app.css` with `resources/js/storefront-entry.js`, preserving the error-page theme behavior.

No cross-surface imports remain in either direction: the Admin entry never imports a Storefront module, and the Storefront entry never imports the Admin controller.

## 6. Alpine module contracts

### 6.1 Storefront registry — `src/resources/js/storefront/alpine-components.js`

```text
registerStorefrontComponents(Alpine)
  Alpine.data("familyQuickBuy", familyQuickBuy)
  Alpine.data("checkoutReview", checkoutReview)
  Alpine.data("mobileMenu", mobileMenu)
```

The registry is covered by `tests/JavaScript/storefront-components.test.js`, which asserts the exact three component names and that each factory is a function.

### 6.2 `familyQuickBuy(initialOptions)` — `src/resources/js/storefront/family-quick-buy.js`

DOM-light Alpine data module for Family option selection and the quick-buy bar.

- **Normalization.** Options are coerced to `{ id, name, priceLabel, stock, selected, productUrl, checkoutUrl }`; stock is floored and clamped at zero.
- **Initialization.** Prefers the server-selected option with stock, then the first option with stock, then the server-selected option, then the first option.
- **Selection.** `selectOption(productId, announce)` synchronizes `selectedProductId`, clamps quantity to the new maximum, and writes an `announcement` string for screen readers when the selection changed.
- **Quantity bounds.** `quantityMaximum` is `min(20, stock)` while available and `0` when sold out; `setQuantity()`/`adjustQuantity()` clamp to `1..maximum`.
- **Checkout projection.** `checkoutHref` preserves path, query, and hash of the server-rendered checkout URL while setting `quantity`; sold-out options point at that Product's detail URL instead. `checkoutLabel`/`checkoutAriaLabel` switch between buy and detail wording.
- **Keyboard navigation.** `handleOptionKeydown(event, productId, refs)` handles ArrowLeft/ArrowRight (wrapping), Home/End, Space, and Enter, moves focus to `family-option-button-{id}` in `$refs`, and announces the new selection. The Blade radiogroup supplies the roving tab stop via `x-bind:tabindex` (selected `0`, others `-1`) and `x-bind:aria-checked`.
- **Sold-out rule.** A sold-out option stays selectable for detail/waitlist but cannot be bought from the quick bar.
- **No-JavaScript fallback.** The static Family option links remain usable when Alpine is absent.

Guarded by `tests/JavaScript/family-quick-buy.test.js` (6 tests): first-available initialization, server-selected preservation, option change presentation and checkout URL, quantity bounds, sold-out behavior, and keyboard navigation.

### 6.3 `checkoutReview(initialConfig)` — `src/resources/js/storefront/checkout-review.js`

Client-only review state for the shared Cart and direct Product checkout page.

- **Quote.** Rejects a malformed configuration or tier schedule rather than showing a partial quote: at most five tiers, minimum quantity `2..20`, strictly decreasing IDR amounts, and USDT completeness/ordering when the Product has a base USDT price. Integer-safe arithmetic only; unsafe values render as `—` instead of a false quote.
- **Quantity.** `stepQuantity()`/`announceQuantity()` and the summary getters keep quantity, tier note, IDR/USDT unit and subtotal values coherent under existing inputs.
- **Payment projection.** Payment options hydrate from server-rendered `[data-checkout-payment-option]` attributes; `selectedPaymentOption` drives labels, descriptions, amount kind, contact requirement/help text, and native form validity (`refreshFormValidity()`).
- **Sticky action.** `observeStickyAction()` measures `$refs.stickyAction`, tracks a `(max-width: 767px)` media query, and attaches a passive window resize listener plus a `ResizeObserver`; `stickySubmitDisabled`/`stickySubmitDescription` project the server-rendered submit state.
- **Form association.** The sticky and in-panel submit buttons stay associated with `#checkout-form` through the `form` attribute and remain server-rendered, so no-JavaScript submission keeps an accessible name and submits once.
- **Cleanup.** `destroy()` disconnects the observer, removes the resize listener, and cancels the pending error-summary focus frame.

Guarded by `tests/JavaScript/checkout-review.test.js` (7 tests): tier transitions, base/no-tier and missing-USDT values, invalid schedules, quantity bounds and unsafe arithmetic, payment/contact/validity projection, server-attribute hydration, and sticky listener cleanup on destroy.

### 6.4 `mobileMenu(environment = globalThis)` — `src/resources/js/storefront/mobile-menu.js`

Drawer state for the Storefront header; the factory accepts an injected environment so state and focus transitions can be unit-tested without a browser.

- **State.** `isOpen`, `isVisible`, `isAnimating`; `init()` resolves `#mobile-menu`, `#mobile-menu-btn`, `#mobile-menu-close`, and `#mobile-menu-backdrop` and stays inert when the header lacks them.
- **Open/close.** `open()` records the opener, locks body scroll (`overflow-hidden`, only when the component owns the lock), focuses the close button after Alpine's update, and starts the transition on the next frame. `close(restoreFocus = true)` releases the lock, restores the still-visible opener (falling back to the menu button), and unmounts the drawer after the 300 ms transition. `closeOnLink(event)` closes on anchor activation without restoring focus.
- **Keyboard.** Escape closes and restores focus; Tab/Shift+Tab are contained using a focusable-element list filtered to rendered elements, with the close button as the fallback.
- **Breakpoint.** A `(min-width: 1024px)` media-query listener closes the menu and unlocks scrolling without restoring focus.
- **Cleanup.** `destroy()` removes the document keydown and media-query listeners, clears the close timer and queued frame, invalidates pending focus work, releases the scroll lock, and resets state.

Guarded by `tests/JavaScript/mobile-menu.test.js` (14 tests): open/close focus and scroll lock, Escape, fallback focus, link activation, Tab containment, empty drawer, desktop breakpoint, reopen during the closing transition, queued-focus cancellation, pre-existing scroll locks, destroy cleanup, and inert headers.

### 6.5 Admin Account Input workflow — `src/resources/js/admin-dashboard/account-input-workflow.js`

`accountInputWorkflow({ createOperationKey, fetchRequest, getCsrfToken, confirm })` returns the Account Input draft/review/submission slice composed into the existing Alpine root in `admin-dashboard.js` (`...accountInputWorkflow({ createOperationKey: newOperationKey })`). `emptyAccountInputState()` is exported for the root's initial state.

- **Revision safety.** Every draft edit (`accountInputChanged()`, card add/remove, method/locale/field change, stock-modal reset) increments a monotonic `accountInputDraftRevision` and clears the reviewed state, review payload, errors, and message.
- **Fail-closed review.** A server review response — including errors and rejections — is applied only to the exact submitted revision; a stale response cannot mark a newer draft reviewed or replace its errors, and a current rejection never leaves Account Input committable.
- **Authority.** Server validation and Stock Intake remain authoritative; the module only projects review results and enables commit for a valid current revision.
- **Dependencies.** `fetchRequest`, `getCsrfToken`, and `confirm` are injectable so the workflow is unit-testable without a browser, matching the environment-injection style of `mobileMenu`.

Guarded by `tests/JavaScript/admin-account-input-workflow.test.js` (9 tests), which also covers the image-reset and Family focus-opener fixes in section 7.

### 6.6 Alpine practices applied

The implementation follows the Alpine.js documentation for build-step registration and lifecycle:

- Components are registered with `Alpine.data()` before a single `Alpine.start()` per entry ([globals/alpine-data](https://alpinejs.dev/globals/alpine-data)).
- `init()` runs automatically for `Alpine.data` components, and `destroy()` is the documented place to clear intervals, disconnect observers, and detach event handlers ([essentials/lifecycle](https://alpinejs.dev/essentials/lifecycle), [globals/alpine-data](https://alpinejs.dev/globals/alpine-data)); both behaviours are directly asserted in the unit tests.
- Focus that must happen after Alpine updates the DOM uses `$nextTick` (with a synchronous fallback in `mobileMenu.runAfterUpdate`) ([directives/init](https://alpinejs.dev/directives/init)).
- Focus containment for the mobile drawer is implemented inside the module instead of the optional `@alpinejs/focus` plugin, because the drawer must skip non-rendered elements and the refactor may not add a dependency. The plugin's `x-trap` behaviour ([plugins/focus](https://alpinejs.dev/plugins/focus)) is the reference for the semantics, not a new package.
- Component factories take injectable configuration/environment so contracts stay testable in Node without a browser (see also section 10).

## 7. Reviewed defects and their fixes

| #   | Reviewed defect                                                                                                                                                 | Shipped fix                                                                                                                                                                                                                                                                          | Commit     | Guarding test                                                                                                                                                                                                                                                           |
| --- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ---------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Product create/edit reset the Telegram image filename but retained the previous file input value, so a stale upload could be submitted.                         | The file input is now `x-ref="telegramProductImage"` and change handling goes through `updateTelegramProductImageFileName($event)`; opening create/edit clears `input.value` and resets the label to "Tidak ada file yang dipilih" without touching an existing saved Product image. | `0cd1ca08` | `admin-account-input-workflow.test.js` — "Product record changes clear the Telegram upload and keep saved images"                                                                                                                                                       |
| 2   | An Account Input review response could certify a draft edited while the request was pending.                                                                    | Monotonic `accountInputDraftRevision`; review responses apply only to the exact submitted revision, including errors, and stale results fail closed.                                                                                                                                 | `0cd1ca08` | "every draft edit invalidates a pending review revision", "stale server validation errors cannot replace changed-draft errors", "current server rejection cannot leave Account Input committable", "stock modal resets advance revision before pending reviews resolve" |
| 3   | Editing a Product Family inside the already-open manager overwrote the stored focus opener with a hidden control, so closing restored focus behind the manager. | `openProductFamilyEditModal()` captures `document.activeElement` only when the manager is closed (`if (!this.familyModalOpen)`); direct Family edit still captures its own opener, and `closeProductFamilyModal()` restores it through `restoreModalFocus()`.                        | `0cd1ca08` | "Family editing retains the visible manager opener and direct opener"                                                                                                                                                                                                   |
| 4   | Family option anchors claimed radio-group semantics but had no arrow-key navigation and no roving tab stop.                                                     | The Family options became a real radiogroup: `role="radiogroup"`/`role="radio"`, `x-bind:tabindex` (selected `0`, others `-1`), `x-bind:aria-checked`, and `handleOptionKeydown()` for ArrowLeft/ArrowRight/Home/End/Space/Enter with focus and announcement updates.                | `af8a18e3` | `family-quick-buy.test.js` — "radio keyboard navigation wraps, selects, announces, and focuses options"                                                                                                                                                                 |

Related stabilization recorded during the same family:

- `ff4b2925` keeps checkout submit labels server-rendered, so a JavaScript-disabled checkout keeps an accessible submit name (found by the no-JavaScript browser contract).
- `2c5dab53` switches Storefront shell wrappers from `overflow-x-hidden` to `overflow-x-clip` so the sticky header keeps sticking (pre-existing regression surfaced by the Admin browser lane) and makes the quantity-tier browser assertion order-independent for the shared Product fixture.
- `6d0d6b47` stabilizes the Storefront browser coverage by waiting for the smooth scroll to settle, asserting the contact disclosure through its `open` property, and pointing the mobile-navigation focus assertion at the drawer's current last focusable control.

## 8. Retained imperative services

`src/resources/js/storefront.js` keeps the services where imperative browser APIs are the right seam, and the cutover deleted only the superseded Family, checkout-review, and mobile-menu initializers (858 lines). Retained services:

- analytics event transport (`trackStorefrontEvent`);
- scroll progress (`initScrollProgress`), reveal stagger (`initRevealStagger`), and header elevation (`initHeaderElevation`);
- media frames (`initMediaFrames`), FAQ accordion (`initFaqAccordion`), and invoice follow-up details (`initInvoiceFollowupDetails`);
- verified-purchase activity polling (`initVerifiedPurchaseActivity`);
- native QRIS image fallback (`initNativeQrisImageFallback`);
- payment countdowns (`initPaymentCountdowns`), payment actions (`initPaymentActions`), and shop payment status polling (`initShopPaymentStatus`);
- catalog filter focus (`initCatalogFilterFocus`) and support funnel listeners (`initSupportFunnelEvents`).

`initStorefront()` boots these after `Alpine.start()`; it does not own Family quick-buy, checkout review, or mobile navigation state.

## 9. No-JavaScript and server-rendered guarantees

- Family option detail links and checkout links remain server-rendered; Alpine only enhances selection and the quick bar.
- Checkout radio forms, consent, disclosures, submit buttons, and fallback copy remain server-rendered; the sticky submit uses `form="checkout-form"` and `x-text` labels have server-rendered equivalents.
- Header navigation remains usable without JavaScript; the drawer's enhanced state, focus containment, and scroll lock are progressive.
- The customer Playwright lane explicitly covers the JavaScript-disabled checkout contract.

## 10. Test map

### 10.1 Node unit lane — `npm run test:js`

`src/package.json` defines `"test:js": "node --test tests/JavaScript/*.test.js"`. CI runs it in the "Application, browser, and PostgreSQL tests" job of [`.github/workflows/ci.yml`](../../../.github/workflows/ci.yml) immediately after `npm ci`, before the Vite/asset smoke step. Node 24 is the lane's runtime; no test package was added.

| File                                                    | Tests  | Contract                                                                                               |
| ------------------------------------------------------- | ------ | ------------------------------------------------------------------------------------------------------ |
| `tests/JavaScript/family-quick-buy.test.js`             | 6      | Family selection, quantity bounds, checkout href, sold-out behavior, keyboard navigation               |
| `tests/JavaScript/checkout-review.test.js`              | 7      | Tier quotes, invalid schedules, quantity/arithmetic safety, payment/contact projection, sticky cleanup |
| `tests/JavaScript/mobile-menu.test.js`                  | 14     | Drawer focus, scroll lock, Escape/backdrop/link, Tab containment, breakpoint, destroy cleanup          |
| `tests/JavaScript/admin-account-input-workflow.test.js` | 9      | Draft revision races, malformed responses, local validation, image reset, Family focus opener          |
| `tests/JavaScript/storefront-components.test.js`        | 1      | Registry registers exactly `familyQuickBuy`, `checkoutReview`, `mobileMenu`                            |
| **Total**                                               | **37** |                                                                                                        |

The tests import the module factories and assert consumer-visible behavior rather than pinning entrypoint implementation text.

### 10.2 Playwright lanes

Both lanes rebuild assets first and run `tests/Browser/admin-dashboard-responsive.spec.js`:

- `npm run test:browser:admin` — the full Admin/responsive lane;
- `npm run test:browser:customer` — the focused Storefront lane selecting the `customer checkout` cases, including the `320×568`, `390×844`, `768×900`, `1280×900`, `1440×900`, and `1920×1080` viewport matrix and the JavaScript-disabled flow.

CI runs the customer slice ("Run customer browser smoke without rebuilding assets"); the full Admin lane is the local/verification lane for Admin behavior, reporting navigation, and responsive checks.

### 10.3 `docs/agents/codebase.md`

The codebase guide's test map documents the new lane and the retained services:

- `tests/JavaScript/*.test.js` — "Node unit lane (`npm run test:js`, enforced in CI) for the consumer-visible Alpine module contracts: Family quick-buy selection/quantity/checkout href, checkout review quotes/sticky action/form validity, mobile navigation focus/scroll lock/Escape, and Admin Account Input revision safety. Tests import the module factories and assert behavior rather than pinning entrypoint implementation text."
- `src/resources/js/storefront.js` — "retained imperative services only: analytics event transport (`trackStorefrontEvent`), scroll progress/reveal/header-elevation observers, media frames, FAQ accordion, verified-purchase activity polling, native QRIS image fallback, payment countdown/status polling and actions, catalog filter focus, invoice follow-up details, and support funnel listeners. Family quick-buy, checkout review, and mobile navigation state are owned solely by the Alpine modules in `src/resources/js/storefront/` (`familyQuickBuy`, `checkoutReview`, `mobileMenu`)."

That section is the maintained test map; this specification is the historical design record.

## 11. Closure evidence

Recorded for the family at `2c5dab53` and re-verified on 2026-09-26 (CI rows cite run [36209789852](https://github.com/Alhiefikri/telegram-bot/actions/runs/36209789852); local rows were rerun at `2c5dab53`):

| Evidence                                                                                                | Result                                                                                                                                            | Source                                                         |
| ------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------- |
| CI run [36209789852](https://github.com/Alhiefikri/telegram-bot/actions/runs/36209789852) at `2c5dab53` | success (`Workflow validation and dependency audit`, `Application, browser, and PostgreSQL tests`, `Build, verify, and publish production image`) | `gh run view 36209789852`                                      |
| `npm run test:js`                                                                                       | 37 passed, 0 failed, 0 skipped                                                                                                                    | CI step "Run JavaScript unit tests"; reproduced locally        |
| `npm run test:browser:admin` (full lane)                                                                | 76 passed                                                                                                                                         | full Admin/responsive lane                                     |
| `npm run test:browser:customer` (customer slice)                                                        | 21 passed                                                                                                                                         | CI step "Run customer browser smoke without rebuilding assets" |
| Storefront PHP suite (`php artisan test --filter=Storefront`)                                           | 305 passed, 1 skipped (3003 assertions)                                                                                                           | focused verification run                                       |
| `ProductionReleaseHardeningTest`                                                                        | 39 passed (398 assertions)                                                                                                                        | focused verification run                                       |
| `AdminDashboard` tests                                                                                  | 128 passed (1315 assertions)                                                                                                                      | focused verification run                                       |
| Parallel SQLite PHPUnit suite                                                                           | 2661 tests, 19605 assertions, 79 skipped                                                                                                          | CI step "Run parallel SQLite PHPUnit suite"                    |

## 12. References

- Parent issue: [#503 — Alpine.js UI refactor and JavaScript regression coverage](https://github.com/Alhiefikri/telegram-bot/issues/503).
- Child tickets: [#504](https://github.com/Alhiefikri/telegram-bot/issues/504), [#505](https://github.com/Alhiefikri/telegram-bot/issues/505), [#506](https://github.com/Alhiefikri/telegram-bot/issues/506), [#507](https://github.com/Alhiefikri/telegram-bot/issues/507), [#508](https://github.com/Alhiefikri/telegram-bot/issues/508), [#509](https://github.com/Alhiefikri/telegram-bot/issues/509).
- Missing-spec remediation: [#510 — Commit the missing source specification for the Alpine UI refactor](https://github.com/Alhiefikri/telegram-bot/issues/510).
- Implementation map and test map: [docs/agents/codebase.md](../../agents/codebase.md).
- Domain language: [CONTEXT.md](../../../CONTEXT.md).
- Alpine.js documentation: [Alpine.data](https://alpinejs.dev/globals/alpine-data), [lifecycle](https://alpinejs.dev/essentials/lifecycle), [x-init / $nextTick](https://alpinejs.dev/directives/init), [Focus plugin](https://alpinejs.dev/plugins/focus).
