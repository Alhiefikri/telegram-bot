# Admin Dashboard Sidebar Cutover

Status: implemented in the working tree; production deployment is not authorized by issues #600, #601 or #602.

## As-built ownership

- `src/resources/views/admin/dashboard.blade.php` owns the authenticated dashboard composition.
- `x-admin-dashboard.shell` owns the page frame, desktop sidebar slot, sticky topbar slot, content slot, and mobile drawer slot.
- `AdminDashboardData::navigation()` and `navigationFor()` remain the only server-built, permission-filtered navigation models.
- `src/resources/js/admin-dashboard/navigation.js` owns drawer state, focus restoration, scroll-lock ownership, topbar-height measurement, breakpoint cleanup, and lifecycle teardown. `activeTab` remains server-owned.
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

## Issue #601 repairs and smoke evidence

The Unknown outbound-effect detail no longer opens an unmatched outer card. The existing metadata redaction, resolve/resend forms, route authorization, and duplicate-risk acknowledgement are unchanged.

The controller still selects one authorized tab subtree. Active tab bodies no longer use `x-cloak` or `x-show` to become readable; interactive drawers and modals retain their cloak. This does not convert JavaScript-only stock or modal workflows into no-JavaScript forms.

Server-built navigation links preserve query/filter state, remove stale `product_form`, and expose explicit payment-settings and product-create destinations. The shared client navigation handler carries the current fragment only when the destination has none; it also covers ordinary and modified anchor clicks. `goToTab()` retains full-page `location.assign()`, query/create intent, and the current fragment. PHP does not invent a fragment it never receives.

The shared topbar stays sticky on dashboard and linked operational pages. A lifecycle-owned `ResizeObserver` publishes its actual height for native hash/focus scrolling, with a conservative CSS fallback when JavaScript is unavailable. Destruction disconnects the observer and restores the previous inline height only while the lifecycle still owns it. Return-target focus aligns section starts, not the center of oversized sections, below the topbar.

The no-JavaScript fallback uses font-relative height budgets of 18rem below 640px and 10rem from 640px, plus the safe-area inset. The larger mobile budget keeps wrapped chrome clear of native targets at 200% text size. Revenue rows wrap rather than overflow at the 320px boundary.

### Passing-after checks

Run from `src/`:

- `php artisan test --filter='AdminTelegramOutboundEffectReviewTest|AdminDashboard'`: 153 passed, 1,836 assertions, including all 14 outbound-review and 139 dashboard tests.
- `npm run test:js`: 57 passed.
- `npm run build`: Vite production build passed.
- `npx playwright test tests/Browser/admin-dashboard-responsive.spec.js -g 'shared topbars stay reachable'`: passed, including hash, skip-link, validation, focused control, scroll and light/dark checks.
- `npx playwright test tests/Browser/admin-dashboard-responsive.spec.js`: all 96 passed, including no-JavaScript Owner/Admin cases at 320, 375 and 1280px. The shared-topbar case writes dashboard/review light/dark screenshots at 375, 768 and 1280px under `src/test-results/`.

Manual browser smoke used disposable SQLite data, never production data:

- Owner opened an Unknown review, saw reduced metadata, explicitly created an acknowledged resend version, and resolved the original as sent with a recorded message ID.
- All ten Owner and nine Admin tabs rendered headings and content without Alpine. At 375px their active content was 343px wide, with nonzero height and zero document overflow. Native fallback payment-settings links remained usable.
- A native no-JavaScript broadcast POST rejected 3,501 characters and retained all 3,501 characters in the textarea; the empty broadcast queue also rendered its empty state.
- Ordinary sidebar/linked-page navigation preserved `sales_filter=waiting` and `#admin-section-sales`; back/forward restored the full URLs. Destruction cleared the owned `69px` topbar-height property.
- Dashboard and Unknown-detail topbars remained at y=0 after scroll at 375, 768 and 1280px in light/dark. Dashboard heights were 159/69/69px; linked detail heights were 125/69/69px. Document overflow was zero.
- At a 600px viewport height, the focused payment-settings section began at y=191/101/101px below topbars ending at y=159/69/69px. Its 1,605px mobile height no longer caused its heading to be centered above the viewport.
- At 200% root text size and 375x900px, the topbar remained 375px wide without clipped title text; the focused broadcast textarea was below the 421px header and inside the viewport.
- At 375x480px after scrolling, native reverse-Tab focused the broadcast textarea at y=304..470px below the 159px header, with the whole control inside the viewport and zero overflow.
- With JavaScript disabled and 200% root text size at 320/375/640/768/1280px, native payment-settings anchors left their heading at y=640/640/384/384/384px below headers ending at y=525/421/315/315/315px. Every target remained inside the 900px viewport, with zero horizontal overflow.
- Current keyboard smoke kept the validation message at y=339..359px below the 159px header. The skip link focused the main content at y=159px. The drawer stayed above the header at z=50 versus z=20, owned the body scroll lock while open, and restored the visible opener and prior overflow style on Escape.

### Visual decision and antislop Delivery Gate

Design Read, declared before edits: operational Admin Dashboard, canonical Variant A, ENERGY 1 / RHYTHM 2 / MOTION 1. Neutral surfaces and a restrained violet accent retain the existing identity. Sticky context/actions avoid returning to the page top; actions occupy a separate mobile row so linked-page titles are not squeezed. Measured scroll clearance keeps real headings, validation and focus visible without creating another navigation or visual component family.

Gate scope is the #601 render/navigation/topbar repair, not dashboard-wide accessibility or release approval. Pre-existing contrast/upload work, complete tab/modal restyling, and real Telegram Android/iOS release evidence remain owned by the other follow-up issues named in the #601 brief. Fixture records are test evidence, not production/customer claims.

Each row records a passing answer to the mandatory gate for this scope:

| Gate item | Result and evidence |
| --- | --- |
| R-02 punctuation | PASS: no new UI copy; repair notes use plain punctuation. |
| R-03 mobile layout | PASS: normal-width and 200% no-JavaScript checks at 320/375/640/768/1280px recorded zero overflow; mobile title/actions use separate rows and revenue rows wrap. |
| R-17 sourced statistics | PASS: no statistics added; rendered values come from the disposable application database. |
| R-18 testimonials | PASS: no testimonials introduced. |
| R-23 assets/navigation authority | PASS: no assets added; existing authorized navigation groups and product identity retained. |
| R-24 real destinations | PASS: direct tab URLs, sidebar/drawer/fallback links, explicit settings links and linked-page returns were exercised. |
| R-25 changed text contrast | PASS: contrast tool measured light text/context at 14.07:1/5.51:1 and dark text/context at 16.72:1/8.74:1; focus colors measured 4.83:1/9.71:1. |
| R-26 functional controls | PASS: tab/create navigation, theme, drawer and existing resolve/resend paths perform their real actions; no dead controls added. |
| R-27 states | PASS: empty broadcast queue and server validation with retained input were observed; navigation remains native full-page loading. |
| R-28 FAQ relevance | PASS: no FAQ added or changed. |
| R-32 keyboard/focus | PASS: Tab/Enter/Escape, visible skip link, drawer opener restoration, focused field and section clearance were exercised. |
| R-33 source implementation | PASS: Blade, CSS and JavaScript implement the repair directly; no external source-patching script. |
| R-34 themes | PASS: dashboard and linked review topbars were inspected in light and dark at all three widths. |
| R-35 executed surface | PASS: Vite build, real review mutations, all allowed no-JavaScript tabs and shared-topbar browser click-through were run. |
| R-36 honest claims | PASS: no security/performance/customer claims added; production deployment and real-device qualification are explicitly excluded. |
| R-37 direction | PASS: canonical Variant A and ENERGY 1 / RHYTHM 2 / MOTION 1 were declared before implementation. |
| R-38 real content | PASS: production labels are unchanged; seeded review/catalog records are confined to the isolated fixture. |
| R-01 gradients/glow | PASS: none introduced by this repair. |
| R-04 icon purpose | PASS: existing menu/theme and domain navigation icons retained; no decorative icon set added. |
| R-06 typography purpose | PASS: existing operational typography retained; no large monospace or tracked label treatment added. |
| R-07 background patterns | PASS: no grid, blueprint or dot background introduced. |
| R-08 decorative arrows | PASS: no decorative arrows added. |
| R-09 badges | PASS: existing store/checkout status badges report application state; no promotional badges added. |
| R-10 glass | PASS: sticky topbar retains an opaque neutral surface; no glass layers introduced. |
| R-12 elevation | PASS: existing restrained surface treatment retained; sticky stacking separates context from content and remains below overlays. |
| R-13 glow everywhere | PASS: no glow added. |
| R-14 card hierarchy | PASS: redundant detail wrapper removed; existing order/effect/actions groups follow their actual content. |
| R-19 motion purpose | PASS: no animation added; native navigation and existing reduced-motion handling retained. |
| R-22 illustration purpose | PASS: no illustrations added. |
| Liveliness: explicit dials | PASS: ENERGY 1 / RHYTHM 2 / MOTION 1 recorded in the Design Read. |
| Liveliness: dial consistency | PASS: low-energy operational shell retained; the repair changes access and clearance, not visual spectacle. |
| Liveliness: focal point | PASS: current group/title and the real active content or review record remain identifiable in browser screenshots. |
| Liveliness: structural whitespace | PASS: mobile actions have their own row; measured scroll clearance separates the sticky context from targets. |
| Liveliness: deliberate accent | PASS: existing muted violet selection/focus accent retained without a second palette. |
| Liveliness: identity motif | PASS: LHIE STORE identity and repeated group/title/navigation hierarchy remain shared across dashboard and linked pages. |
| Liveliness: prior Design Read | PASS: Variant A and its operational purpose were declared before UI edits. |
| C-1 intentionality | PASS: sticky positioning, measured clearance and separate mobile action row each have the reason written above. |
| C-2 completeness | PASS: real navigation and review actions were exercised; no placeholder destinations or no-op actions added. |
| C-3 content-driven composition | PASS: one server-selected tab and meaningful review groups; unmatched redundant card removed. |
| C-4 resilience | PASS: no-JavaScript Owner/Admin tabs, both themes, three widths, keyboard targets, empty/error states and enlarged text were exercised. |
| C-5 evidence | PASS: local browser measurements and observed application outcomes support the claims; no production sign-off implied. |
| R-05 non-template layout | PASS: existing operational grouped sidebar/context/content hierarchy retained; no marketing section template added. |
| R-11 radius variation | PASS: existing component radii retained; no pill-shaped replacement component family. |
| R-15 concrete actions | PASS: existing labels such as Pesanan, Produk, Buka Toko and review actions identify their destination/action. |
| R-16 language | PASS: no marketing buzzwords or new promotional UI prose added. |
| R-20 product identity | PASS: real catalog, delivery review, store state and LHIE STORE identity retained. |
| R-21 theme choice | PASS: existing user-controlled light/dark preference retained and exercised; no forced dark default. |
| R-29 palette | PASS: existing neutral/violet Admin tokens retained; no new palette introduced. |
| R-30 originality | PASS: canonical repo-specific Variant A retained, not replaced by another product's layout. |
| R-31 written reasons | PASS: visual changes and their operational reasons are documented in the Design Read above. |

## Issue #602 repairs and smoke evidence

Follow-up to #593/#599: shared Admin controls and uploads still failed accessibility in browser-light/dark measurements. Presentation-only. Routes, POST/CSRF contracts, validation, named error bags, `old()` input, redirects, return targets, modal ownership, business rules, Storefront surfaces and ENV are unchanged.

### Accent role tokens

`--admin-accent` (`#6d5bd0` light / `#b8a9ff` dark) still fills accent controls. Three role tokens replace reusing that one value as fill, text and hover:

| Token | Light | Dark | Role |
| --- | --- | --- | --- |
| `--admin-accent-ink` | `#ffffff` | `#121722` | Foreground on accent fills; replaces the hardcoded `text-white` |
| `--admin-accent-strong` | `#5b48c2` | `#b8a9ff` | Accent text/icons on paper, surface and `accent-soft` (active navigation, active tabs and filter pills, purple badges, accent links, fallback drawer create link) |
| `--admin-accent-hover` | `#5b48c2` | `#9d86f8` | Hover fill for accent-filled controls |

Reason: the old light pair `#6d5bd0` on `#eeeaff` measured 4.40:1 (AA needs 4.5:1 for 14px/500 navigation text), and white on the dark accent `#b8a9ff` measured 2.07:1.

`x-admin-dashboard.button` now declares `hover:*` on each variant. Before, one shared `hover:bg-admin-accent-soft` outranked the variant's `hover:bg-admin-accent` in the compiled stylesheet, so hovering any accent-filled product/purple/pink button painted `#eeeaff` under unchanged white text (1.05:1 in light). Semantic `warning`/`destructive` variants keep their own colors and only gained an explicit `hover` fill.

### Upload controls

`x-admin-dashboard.file-upload` replaces seven duplicated `sr-only` upload rows: the default catalog image, the three product-modal images (storefront card, Telegram detail, Store Channel poster), the two product-family images, and the Telegram `/start` banner. The native `<input type="file">` stays the control, so Tab/Space/Enter still open the native picker and `name`, `accept`, `required`, `x-ref` and `x-on:change` handlers are unchanged. It is visually replaced by a visible label of at least 44x44 CSS px (measured 119x44 for the family storefront label at 100% text) that now carries hover and a 2px `#8758de` focus outline, with the filename feedback and the existing error bags, previews and hints beside it.

### Audit results on the remaining upload patterns

- Category management modal: no upload control exists in the form, so nothing required repair.
- Storefront banner inputs (`storefront-banner-image-{0..4}`): measured height was 40px with only the 1px UA focus ring, and the dark theme rendered their `file:` button as white text on the light `brand-bg` fill. All three were fixed in place (`min-h-11`, `file:min-h-11`, admin focus outline, `file:text-brand-slate-dark`) without changing the native input, its other fields, or the `remove` checkbox.
- Login and Owner password confirmation consume the same accent and button primitives; both were measured in browser light/dark and pass.

### Recorded measurements (computed browser colors)

Evidence JSON: `artifacts/issue-602/accent-contrast.json` and `artifacts/issue-602/auth-contrast.json` (written by the browser tests; ignored by git). Screenshots of the focused upload in light and dark, the dark banner upload, the dark family modal, the light product modal, the 320px/200% text state, the dark drawer and admin login sit in the same ignored directory.

| Control | Light | Dark |
| --- | --- | --- |
| Produk primary/secondary action (11px/600) | `#fff` on `#6d5bd0` = 5.18:1 | `#121722` on `#b8a9ff` = 8.67:1 |
| ... same control hovered | `#fff` on `#5b48c2` = 6.67:1 | `#121722` on `#9d86f8` = 6.15:1 |
| Active navigation item (14px/500) | `#5b48c2` on `#eeeaff` = 5.66:1 | `#b8a9ff` on `#302a4f` = 6.47:1 |
| Active sales filter pill (14px/500) | `#5b48c2` on `#eeeaff` = 5.66:1 | `#b8a9ff` on `#302a4f` = 6.47:1 |
| Mobile drawer create action (14px/500) | - | `#121722` on `#b8a9ff` = 8.67:1 |
| Admin login and Owner password confirm (14px/600) | `#fff` on `#6d5bd0` = 5.18:1 | `#121722` on `#b8a9ff` = 8.67:1 |
| Linked-shell back link (`/admin/telegram-outbound-effects`, 14px/600) | `#5b48c2` on `#f6f7fb` = 6.23:1 | `#b8a9ff` on `#121722` = 8.67:1 |
| Upload focus ring on its white row | `#8758de` on `#fff` = 4.69:1 (non-text, needs 3:1) | same row colors, 4.69:1 |

Upload smoke in the real browser (390px and 320px, disposable SQLite fixtures): Tab reaches every upload input, Shift+Tab leaves it and Tab returns, the visible label shows the focus outline while the input holds focus, Enter opens the native picker (`filechooser`), the chosen filename replaces the empty-state text, re-selecting reports the replacement name, clearing the selection restores the empty-state text, cancelling the picker keeps the previous selection (and that file is what the form still submits), closing and reopening the family modal resets the chosen filename, and the catalog-default form round-trips a real PNG (`Gambar default katalog berhasil diperbarui.`, badge flips to "Default custom aktif") before rejecting a non-image with `File harus berupa gambar yang valid.`. At 200% root text size and 320px width the family-modal upload label stays >=44x44, keeps its text inside the row, and the page keeps zero horizontal overflow.

Known limitation in that same extreme state: the legacy family/product modals nest rem-scaled paddings (`p-4` shell, `p-3` cards, `p-3` upload wrapper, `p-2` row), which at 320px plus 200% text leave roughly 48px for the visible label, so its two-word caption wraps one or two characters per line and the row grows tall. Content stays complete, the target stays >=44px and nothing is clipped, and the pre-existing `Batal`/`Tambah family` buttons wrap the same way in that state. Fixing it means restyling the raw legacy modal spacing, which the ticket explicitly excludes.

### Passing-after checks

Run from `src/`:

- `php artisan test --filter='AdminDashboard|AdminCategoryCrudTest|AdminTelegramWelcomeBannerTest|AdminStockBulkDeletionTest|AdminDashboardProductFormsTest'`: 164 passed, 1,936 assertions.
- `php artisan test --filter='AdminBalanceAdjustmentDashboardTest|WarrantyAdminTest|SupplierAdminTest|ProductionReleaseHardeningTest|AdminDashboardAccessTest|StorefrontLandingPageTest'`: 147 passed, 1,348 assertions.
- `npm run test:js`: 57 passed.
- `npm run build`: Vite production build passed.
- `npx playwright test tests/Browser/admin-dashboard-responsive.spec.js -g 'accent controls|auth surfaces|upload controls|catalog placeholder'`: passed; adds the accent-contrast (theme, hover, drawer, filter-pill), auth-contrast (login, password confirmation, linked shell), upload-contract, 200%-text and real-upload tests.
- `npx playwright test tests/Browser/admin-dashboard-responsive.spec.js`: all 101 tests passed (12.2m) on the final tree, including the five new light/dark contrast, auth-surface, upload-contract, 200%-text and real-upload cases. No temporary case remains in the file.

### Visual decision and antislop Delivery Gate

Design Read, declared before edits: operational Admin Dashboard surfaces (Produk tab, product/family/category modal controls, shared navigation shell), for store operators, in the restrained Variant A language - dial ENERGY 1 / RHYTHM 2 / MOTION 1.

Reasons for the main decisions: the accent is split by role because one violet value cannot hold AA as both a fill and accent text; uploads keep the native input so keyboard activation and validation stay native, while the visible label carries the 44px target and the focus ring, because focus that lands on a hidden 1x1 control is invisible; the legacy banner input was only raised to the same target and focus outline instead of being restyled into the new primitive.

Gate scope is this contrast/upload repair, not dashboard-wide accessibility sign-off or release approval.

| Gate item | Result and evidence |
| --- | --- |
| R-02 punctuation | PASS: no new UI copy; new notes use plain punctuation. |
| R-03 mobile layout | PASS: 320px at 200% text and 390px upload rows measured zero horizontal overflow and no clipped labels. |
| R-17 sourced statistics | PASS: no statistics added. |
| R-18 testimonials | PASS: none introduced. |
| R-23 assets/navigation authority | PASS: no assets or navigation structure added; existing labels untouched. |
| R-24 real destinations | PASS: existing forms, routes and return targets unchanged; exercised by feature and browser runs. |
| R-25 changed text contrast | PASS: measured 5.18:1/6.67:1 light and 8.67:1/6.15:1 dark for accent actions, 5.66:1/6.47:1 for active navigation and filter pills, 5.18:1/8.67:1 for auth surfaces; hover states included. |
| R-26 functional controls | PASS: every upload input is the native control and submits; valid and invalid catalog uploads were exercised end to end. |
| R-27 states | PASS: empty filename state, chosen filename, replacement filename, stored preview, server validation error and retained previous image were observed. |
| R-28 FAQ relevance | PASS: no FAQ changed. |
| R-32 keyboard/focus | PASS: Tab reaches each upload input, Shift+Tab leaves and Tab returns, the visible label shows a 2px outline at >=3:1, and Enter opens the native picker. |
| R-33 source implementation | PASS: Blade component, tokens and view edits only; no external patching script. |
| R-34 themes | PASS: light and dark measured for products actions, active navigation, drawer, filter pills, login and password confirmation. |
| R-35 executed surface | PASS: Vite build, feature suites, JS tests and browser runs executed; screenshots captured for the focused upload, both themes, the drawer and the 200% text state. |
| R-36 honest claims | PASS: no security/performance/customer claims; ratios are local computed measurements. |
| R-37 direction | PASS: Variant A and ENERGY 1 / RHYTHM 2 / MOTION 1 declared before edits. |
| R-38 real content | PASS: existing Indonesian labels unchanged; fixtures stay inside the disposable browser database. |
| R-01 gradients/glow | PASS: none added. |
| R-04 icon purpose | PASS: existing emoji/action labels unchanged. |
| R-06 typography purpose | PASS: no new type treatment. |
| R-07 background patterns | PASS: none added. |
| R-08 decorative arrows | PASS: none added. |
| R-09 badges | PASS: purple badge keeps its accent-soft pairing with the new readable accent text; no promotional badge added. |
| R-10 glass | PASS: none added. |
| R-12 elevation | PASS: the unified upload row keeps the existing 2px hard shadow; no new floating surfaces. |
| R-13 glow everywhere | PASS: none added. |
| R-14 card hierarchy | PASS: upload rows were consolidated onto one primitive rather than re-decorated. |
| R-19 motion purpose | PASS: no animation added; the upload trigger's transition is limited to its background so the focus ring appears immediately. |
| R-22 illustration purpose | PASS: none added. |
| Liveliness: explicit dials | PASS: ENERGY 1 / RHYTHM 2 / MOTION 1 recorded in the Design Read. |
| Liveliness: dial consistency | PASS: the repair changes control pairs and focus feedback, not the operational density. |
| Liveliness: focal point | PASS: primary create actions keep the single accent fill on each surface. |
| Liveliness: structural whitespace | PASS: upload rows keep their spacing; wrapping replaces clipping at 200% text. |
| Liveliness: deliberate accent | PASS: one violet accent with role-specific values; no second palette. |
| Liveliness: identity motif | PASS: neo-brutal upload rows, LHIE STORE navigation and user-visible Indonesian labels retained. |
| Liveliness: prior Design Read | PASS: declared before the first edit and recorded with the #601 direction. |
| C-1 intentionality | PASS: role tokens, per-variant hovers, the native-input upload and the banner target fix each have a one-line reason above. |
| C-2 completeness | PASS: real submissions, validation errors, picker activation and filename feedback observed; no dead control. |
| C-3 content-driven composition | PASS: no section added or removed. |
| C-4 resilience | PASS: both themes, hover/focus/selected states, empty/error states, 320-390px and 200% text were exercised. |
| C-5 evidence | PASS: ratios come from rendered colors recorded in `artifacts/issue-602`, not from planned tokens. |
| R-05 non-template layout | PASS: existing operational layout retained. |
| R-11 radius variation | PASS: existing radii retained; no pill-shaped replacement. |
| R-15 concrete actions | PASS: labels unchanged. |
| R-16 language | PASS: no marketing buzzwords added. |

## Historical #600 verification record

These historical counts do not establish #601 regression coverage or authorize deployment. Commands were run from `src/`:

- `php artisan test --filter=AdminDashboard` — passed, 139 tests / 1,747 assertions.
- `npm run test:js` — passed, 56 tests.
- `npm run build` — passed with Vite production assets.
- `npm run test:browser:admin` — passed, 87 Playwright tests.
- `npm run test:browser:buymail` — passed, 3 Playwright tests.

## Rollback

No database reset or data rollback is required. If the presentation release is rejected, deploy the prior known-good application image/commit through `.github/workflows/deploy-production.yml` from `main`. Record both the released commit and prior image/commit in the GitHub Actions release evidence before deployment. Do not deploy this cutover until the named verification commands and the real-client checklist are complete.
