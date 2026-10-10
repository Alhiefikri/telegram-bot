# Admin Dashboard Sidebar Cutover

Status: presentation implemented; release acceptance is blocked. Issues #600–#605 do not authorize deployment. Current candidate-bound checks, missing scenarios, device requirements and rollback identity are recorded in [the #605 release parity audit](admin-dashboard-release-parity.md).

## As-built ownership

- `src/resources/views/admin/dashboard.blade.php` owns the authenticated dashboard composition.
- `x-admin-dashboard.shell` owns the page frame, desktop sidebar slot, sticky topbar slot, content slot, and mobile drawer slot.
- `AdminDashboardData::navigation()` and `navigationFor()` remain the only server-built, permission-filtered navigation models.
- `src/resources/js/admin-dashboard/navigation.js` owns drawer state, focus restoration, scroll-lock ownership, topbar-height measurement, breakpoint cleanup, and lifecycle teardown. `activeTab` remains server-owned.
- The dashboard and linked operational shell render the same grouped navigation for desktop, Alpine-enhanced mobile, and the no-JavaScript `<details>` fallback. There is no second dashboard navigation partial or bottom-navigation/More owner.

## Feature parity matrix

The [#605 release parity inventory](admin-dashboard-release-parity.md) is the evidence ledger for all ten tabs, linked operational/financial routes and cross-cutting contracts. It distinguishes server-feature coverage from browser click-through and explicitly blocks closure on untested mandatory rows. The implementation map below does not imply release PASS.

| Surface | Canonical destination | Test ownership |
| --- | --- | --- |
| Ringkasan | `/admin?tab=stats` | overview, payment, maintenance, funding/receiver policy and browser surfaces |
| Produk | `/admin?tab=produk`, `product_form=create` | product/family/category, image/banner, stock/account input and bulk delete |
| Pesanan | `/admin?tab=penjualan` | sales, Order/Payment operations and linked review queues |
| Supplier | `/admin?tab=suppliers` | connections, catalog/import/mapping/pricing, purchases/recovery, warranty and BuyMail |
| Siaran | `/admin?tab=broadcast` | broadcast forms, validation, queue and outbound behavior |
| Store Channel | `/admin?tab=telegram-channel` | configuration, test and fake proof actions |
| Riwayat KlikQRIS | `/admin?tab=riwayat` | reporting/filter/provider mismatch and unavailable state |
| Withdraw | `/admin?tab=withdraw` | reporting filters/pagination and safe proof |
| Funnel | `/admin?tab=funnel` | reporting aggregates and bounded date filters |
| Kompensasi top-up | `/admin?tab=balance-issues` | Owner/password/readiness compensation |
| Linked financial pages | Existing balance report, IDR adjustment/reversal and USDT review routes | separate financial role/readiness/password and exact-money boundaries |

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

## Issue #603 operational restyle

Follow-up to #597, after the #601/#602 blocker repairs. Presentation only: routes, `method`/`@csrf`/`@method`, every input `name`, hidden fields, named error bags, `@error`/`old()`, redirects and return targets, permission gates/`@can`, Alpine roots and every `x-*` binding, modal ownership and Indonesian copy are byte-identical to the pre-restyle views. Every touched view was diff-verified (`route(...)` calls, `@csrf` counts, `name="..."` sets and `@if/@foreach/@can/@error` inventories are unchanged).

### Visual decisions and the reason for each

- **Neutral surfaces and 1px line separators** replace the legacy `border-2`/`border-[3px] border-neo-ink` + `shadow-[Npx_Npx_0_…]` hard-offset treatment, because the operational tabs mixed two visual languages: the shell/primitives from #593 already used `--admin-line` separators and `--admin-radius`. Raw tab/modal markup now composes the same primitives instead of overriding them, so the dashboard has one surface language.
- **Regular case, medium weight** replaces `uppercase`/`tracking-wide`/`font-black` operational labels. Uppercase + wide tracking made identifier-heavy rows (SKUs, provider refs, wallet addresses) harder to scan in the tables operators actually read.
- **The legacy per-modal palette overrides were deleted** (`!bg-[#7BD10A]`, `!bg-[#fee648]`, `!bg-[#d9c8ff]`, `!bg-[#fff3a3]`). A modal's fill carried no meaning, and it defeated the shared `card` tone contract inside the modal. Modal title/description and the semantic `notice` tones still separate danger/warning/success states by color *and* text.
- **Data-like values use `font-mono text-xs break-all`** only where the value is machine-readable (order/provider references, hashes, receiver addresses, SKUs, credential payloads). Everything else stays in the regular UI type scale so the mono style keeps its meaning.
- **One accent, role-split** (`--admin-accent` fill, `--admin-accent-ink` on fill, `--admin-accent-strong` for accent text, `--admin-accent-hover` fill hover) is reused unchanged from #602. Nothing in this restyle introduces a second palette; semantic success/warning/danger boxes keep their tokens and their text labels.
- **Emoji removed from headings only** (`📦 Produk Saya`, `🧩 Kelola Product Family`, `⚠️ Replacement & Refund Policy`, …). The emoji carried no state and competed with the real status badges; action-button emoji that name an action (`✏️`, `🗑️`) were kept or replaced by the plain label so the accessible name stays stable.
- **The custom `supplier-*` CSS family was deleted** (~165 CSS lines) after all four consumer views moved onto the shared primitives. It duplicated the button/badge/card primitives with different borders, hard shadows, uppercase eyebrows and a form-control reset, which is exactly the second visual language the ticket forbids.
- **Linked supplier pages gained their own page context in the shared topbar** (`admin/suppliers/layout.blade.php` now passes the child's `@section('title')` to `linked-shell`) and a content-level heading, because the topbar previously said only "Supplier" for five different pages and the warranty/purchases/BuyMail pages had no heading at all. This follows spec §5.2 (context label + current page title) and adds no route or tab.

### Browser-lane and test changes

- `admin-dashboard-responsive.spec.js`: the linked-pages lane now also walks `admin.orders.show`, the purchases queue and the warranty page; the seed exposes `adminOrderPath`; the supplier workspace is located by `data-supplier-workspace` instead of the deleted `.supplier-workspace` rule; a new lane walks every touched operational surface at 375/768/1280px in light and dark, asserting the shared topbar, a content heading below it, and zero document overflow, with screenshots per surface/width/theme.
- `buymail-warranty.spec.js`: the warranty panel assertion now expects the page's own topbar title.

### Exercised states (browser lanes, disposable SQLite fixtures)

| Surface | Valid | Invalid / error | Empty / long content |
| --- | --- | --- | --- |
| Produk catalog, product card | create/edit product, name/icon edits, locale copy tabs | server validation reopens the modal and focuses the field; catalog-default upload rejects a non-image | long description, no-tier state |
| Product quantity tiers | add/remove up to five tiers, save and reopen | per-tier schedule/price errors | "Tidak ada tingkat harga" |
| Product Family / Kategori | open, create, edit, delete, focus return | family/category error notices | empty family list |
| Stock intake | quick legacy lines, structured account cards, server review then commit | duplicate-identity review, identity-readiness gate | 1–20 account cards, multiline secrets |
| Stock management | single edit, bulk selection, delete, reload | stale/allocated selection rejected; load error retry | >25 accounts truncation notice |
| Pesanan | filter pills, pagination, order cards, detail link | payment-attention notice | no orders |
| Order detail | payment/delivery/purchase blocks, manual approve/reject, reconcile | order error notice, Binance/BSC review blocks | orders without supplier purchase |
| Manual Fulfillment Review | resolve fulfill/hand-off/refund/reject | `review` error, refund reference required | empty queue |
| Manual Delivery Review | reduced metadata, mark sent, explicit resend | `effect` error, duplicate-risk acknowledgement, Unknown state | empty queue |
| Supplier connections | create/edit with password confirmation, sync, catalog/deposit links | wrong password keeps the unsaved API key, lossy edit rejected | no connections |
| Supplier catalog/import/mapping | import draft, map to existing product, move, detach | disabled mapping when no product is available, manual-input alert | empty catalog |
| Supplier product pricing | mode/rounding/thresholds, preview, save | preview without a price | no sources |
| Purchases/recovery | history reconciliation, confirm debit, no-debit evidence, handoff | ambiguous/unknown purchase actions, expired candidate | empty fulfillment and purchase history |
| Warranty/deposit | claim panel EN/ID, refund/replacement, deposit create/refresh/cancel | recovery code, expired/uncertain transfer states | no claims, no deposits |

Nothing in the lanes asserts CSS class names, exact markup or copy wording beyond the operator-facing sentences under test.

### Passing-after checks (final tree)

Run from `src/`:

- `php artisan test --filter='AdminDashboard|AdminCategoryCrudTest|AdminTelegramWelcomeBannerTest|AdminStockBulkDeletionTest|AdminOrderPaymentOperationsTest|ManualFulfillmentReviewTest|AdminTelegramOutboundEffectReviewTest|AdminStoreChannelTest|AdminCatalogPolicyAndStockAuditTest|ProductStockIntakeTest|ProductStockDeletionFakeSalesProofTest|StructuredStockSecurityAndRolloutTest'` — passed, 278 tests / 2,912 assertions.
- `php artisan test --filter='SupplierAdminTest|SupplierDepositTest|SupplierPricingClarityTest|WarrantyAdminTest|WarrantyWebTest|BuyMailFulfillmentTest|SupplierIndependentRegressionTest|AdminDashboardAccessTest|ProductionReleaseHardeningTest|StorefrontLandingPageTest|AdminBalanceAdjustmentDashboardTest|AdminUsdtTopUpReviewTest|AdminTelegramWelcomeBannerTest|AdminStoreChannelTest'` — passed, 257 tests / 2,287 assertions.
- `npm run test:js` — passed, 57 tests.
- `npm run build` — Vite production build passed (run by both browser lanes).
- `npm run test:browser:admin` — passed, 102 tests (12.8m), including the new multi-breakpoint operational lane and the extended linked-pages lane.
- `npm run test:browser:buymail` — passed, 3 tests, including the new supplier-catalog click-through.
- `php artisan view:cache` plus `php -l` over every compiled view — all views compile; this caught two real defects during the work (a bare `{{ }}` in attribute position left the component tag uncompiled, and an escaped `@section('title')` string reaching the topbar double-escaped).
- Token-inventory diff of every changed view (`route(...)`, `name="..."`, `@csrf`, `@method`, `@can`, `@error`, `@if/@foreach/@forelse/@empty/@endif`) — identical before and after.

Screenshots for the record: `src/test-results/**/{produk,pesanan,supplier,supplier-purchases,supplier-warranty,order-detail,manual-fulfillment-review,manual-delivery-review}-{375,768,1280}-{light,dark}.png` written by the operational lane (ignored by git).

### Delivery Gate (issue #603 scope)

Design Read, declared before edits: admin operational surfaces (Produk, Pesanan, Supplier and their linked pages) for store operators running real catalog, stock, order-review and supplier work, in the canonical Variant A language: dial **ENERGY 1 / RHYTHM 1 / MOTION 1**. ENERGY 1 because these are dense operator surfaces where a single accent marks the primary action; RHYTHM 1 because the same card/heading/fact-pair rhythm must repeat across tabs so operators recognize structure at a glance; MOTION 1 because nothing here should move (only existing hover/transition feedback remains).

| Gate item | Result and evidence |
| --- | --- |
| R-02 punctuation | PASS: no new prose; the pre-existing em dashes in pinned operator copy were restored (`Belum ada harga — sinkronkan katalog supplier.`, coverage placeholder, error-code placeholder). |
| R-03 mobile layout | PASS: the new multi-breakpoint lane asserts zero document overflow on every touched surface at 375/768/1280px in both themes; the linked-pages lane asserts it for order detail, purchases and warranty. |
| R-17 sourced statistics | PASS: no statistics added; all rendered values come from the fixture database. |
| R-18 testimonials | PASS: none. |
| R-23 assets/navigation authority | PASS: no assets; existing authorized navigation and product identity retained; no new navigation item. |
| R-24 real destinations | PASS: every rendered link/action keeps its `route(...)`; verified by route-inventory diff per file. |
| R-25 text contrast | PASS: measured pairs below. |
| R-26 functional controls | PASS: every action is still a real form/post, link or Alpine handler; the browser lanes submit them. |
| R-27 states | PASS: empty states (warranty/purchases/monitor queues, product family, stock), validation errors (product form, import form, account input review, catalog upload) and long content (product description, long provider references) are rendered from real fixture data and exercised. |
| R-28 FAQ relevance | PASS: no FAQ touched. |
| R-32 keyboard/focus | PASS: the shared primitives keep 44px targets and visible focus; modal focus traps and opener restoration are unchanged and covered by the existing browser cases. |
| R-33 source implementation | PASS: Blade/CSS source only; no runtime style patching. |
| R-34 themes | PASS: every touched surface rendered in light and dark in the new lane; contrast measured for both. |
| R-35 executed surface | PASS: feature suites, JS tests, Vite build and the browser lanes ran on the final tree. |
| R-36 honest claims | PASS: no security/performance/customer claims; no production deployment. |
| R-37 direction | PASS: Variant A and ENERGY 1 / RHYTHM 1 / MOTION 1 declared before the first edit. |
| R-38 real content | PASS: production labels unchanged; fixtures confined to the disposable SQLite browser database. |
| R-01 gradients/glow | PASS: the legacy `bg-[#…]` modal fills and gradient hero band were removed; no gradient or glow added. |
| R-04 icon purpose | PASS: heading emoji removed; remaining icons name their action. |
| R-06 typography purpose | PASS: `font-display`/uppercase/wide-tracking operational labels removed in favour of the shared title/label scale; mono restricted to data-like values. |
| R-07 background patterns | PASS: no grid/dot pattern added. |
| R-08 decorative arrows | PASS: existing `→`/`↗` action glyphs retained on buttons that leave the page; no new decorative arrows. |
| R-09 badges | PASS: badges report real state (order status, stock status, connection active, review state) with a text label, never color alone. |
| R-10 glass | PASS: no blur/backdrop effect added. |
| R-12 elevation | PASS: the legacy per-element hard-offset shadows are gone; only `shadow-admin-sm` on cards and `shadow-admin-lg` on modals remain, as elevation markers. |
| R-13 glow everywhere | PASS: no glow. |
| R-14 card hierarchy | PASS: cards follow the content (list row, group header, detail section) instead of one uniform tile; nested wells use a muted surface rather than a second border style. |
| R-19 motion purpose | PASS: no animation added; only existing color transitions on hover remain. |
| R-22 illustration purpose | PASS: none. |
| Liveliness: explicit dials | PASS: ENERGY 1 / RHYTHM 1 / MOTION 1 in the Design Read. |
| Liveliness: dial consistency | PASS: uniform, repeatable structure across tabs is the deliberate choice for operator scanning. |
| Liveliness: focal point | PASS: each surface keeps one accent-filled primary action (Tambah Produk, Simpan default, Buat supplier, Catat bukti transfer) with everything else neutral. |
| Liveliness: structural whitespace | PASS: section gaps (`space-y-4`/`space-y-5`, 24-32px desktop inset) separate operational groups instead of decorative separators. |
| Liveliness: deliberate accent | PASS: one muted violet accent, semantic colors only for real status. |
| Liveliness: identity motif | PASS: LHIE STORE, the grouped sidebar, and the repeated eyebrow/title/fact-pair rhythm identify the product. |
| Liveliness: prior Design Read | PASS: declared before the first edit. |
| C-1 intentionality | PASS: each decision above carries its one-line reason. |
| C-2 completeness | PASS: no control was left decorative; the removed palette overrides and emoji carried no function. |
| C-3 content-driven composition | PASS: no section added or removed except the newly added page-context headings, which state the page's own purpose. |
| C-4 resilience | PASS: light/dark, three breakpoints, empty/error/long-content states and keyboard paths exercised. |
| C-5 evidence | PASS: contrast from the contrast tool on the token pairs; rendered-surface evidence from the browser lanes. |
| R-05 non-template layout | PASS: existing operational hierarchy retained; no marketing template introduced. |
| R-11 radius variation | PASS: `--admin-radius` (12px) for cards/modals, smaller radii for nested wells and controls; nothing became pill-shaped. |
| R-15 concrete actions | PASS: labels name the operation (Kelola Product Family, Kelola Kategori, Arsipkan, Catat bukti transfer). |
| R-16 language | PASS: no buzzwords; operator copy unchanged. |
| R-20 product identity | PASS: real catalog/order/supplier data and LHIE STORE identity retained. |
| R-21 theme choice | PASS: user-controlled light/dark retained and exercised on every touched surface. |
| R-29 palette | PASS: existing neutral surfaces + one violet accent; no new color. |
| R-30 originality | PASS: repo-specific Variant A retained. |
| R-31 written reasons | PASS: recorded above. |

### Measured contrast (light / dark)

| Pair | Ratio |
| --- | --- |
| `--admin-muted` on `--admin-surface` | 5.9:1 light / 7.79:1 dark |
| `--admin-muted` on `--admin-surface-muted` | 5.17:1 light / 6.78:1 dark |
| `--admin-accent-strong` on surface | 6.67:1 light / 8.67:1 dark |
| `--admin-accent-ink` on `--admin-accent` | 5.18:1 light / 8.67:1 dark |
| semantic success/warning/info/danger text on their soft fills | 6.68:1 / 6.6:1 / 4.69:1 / 6.8:1 light, 9.74:1 / 8.93:1 / 9.88:1 / 9.47:1 dark |

All pairs clear WCAG AA for normal text (4.5:1); every semantic state also carries a text label, so no status is conveyed by color alone.

### Known limits recorded, not fixed here

- Real Telegram Android/iOS release evidence remains the #599/#600 human gate; browser-viewport emulation does not qualify it.
- Pre-existing, unchanged: `AdminAccountInputWorkflow.accountInputErrorClass()` returns `border-[#ef4444]`, but Tailwind emits that arbitrary border-color rule before the themed `border-*` utilities, so an invalid account row keeps the neutral border and signals the error through its `ring-2` accent plus the field message and `aria-invalid`. Fixing the border would change an existing JS error-state contract, which these presentation-only tickets exclude.

## Issue #604 summary, communications and finance restyle

Follow-up to #596/#598 after the #601/#602/#603 blockers. Presentation only:
routes, `method`/`@csrf`/`@method`, every input `name`, hidden fields, named
error bags, `@error`/`old()`, redirects, stable return targets, permission
gates, Alpine roots and every `x-*` binding, modal ownership, and Indonesian
copy are unchanged. Each rewritten view was diffed with class attributes
stripped (structural diff) plus a token inventory (`route(...)`, `name="..."`,
`@csrf`, `@method`, `@foreach`, `@forelse`, `@error`, `@if`, `@selected`,
`id="..."`, `aria-label`, `x-data`, `x-on:`): counts are identical to the
pre-restyle files except for the deliberate additions listed below.

### Visual decisions and the reason for each

- **Summary surface owns one card language.** The payment/provider control,
  BSC receiver panel, IDR and USDT funding policy, balance-operations panel,
  the balance-report/adjustment shortcuts, and checkout maintenance now use
  the shared `card`/`badge`/`button`/`input`/`select`/`textarea`/`field-error`
  primitives with `border-admin-line` separators and `--admin-radius` corners,
  and they lost the legacy `tone="purple|blue|rose"` fills: a card fill carried
  no meaning once the section heading and status badge already named the state.
- **Server tones are aliased inside `x-admin-dashboard.badge`.** The view models
  describe state as `success`/`warning`/`danger`/`info`
  (`StoreBalanceOwnerCompensationService::STATUS_TONES`) while the primitive
  named its palettes emerald/amber/rose/blue, so compensation rows rendered a
  muted default badge. The alias keeps one primitive and one palette while
  state colors reach the screen again.
- **`regular case`, medium weight** replaces the uppercase/tracked labels in
  the stats controls, Riwayat/Withdraw/Funnel headings, and the compensation
  modal. Identifier-heavy queue rows (provider references, order IDs, wallet
  addresses) are faster to scan without wide tracking, and the semantic
  success/warning/danger boxes keep their own tokens and text labels.
- **Machine values use `font-mono text-xs break-all`** (KlikQRIS order IDs,
  withdraw codes, BSC addresses, provider references, USDT raw units, balance
  metric keys, Telegram IDs). Prose keeps the regular UI scale so monospace
  keeps its meaning.
- **Tables keep normal word wrapping inside their cells.** The shell sets
  `overflow-wrap: anywhere` so a long token never widens the document, but that
  also let auto table layout collapse a status column to badge width, which
  broke `Success` into one character per line and inflated a withdraw row to
  344px. `.admin-dashboard-shell :where(th, td) { overflow-wrap: normal; }`
  (base layer, so an explicit `break-*` on a cell still wins) restores
  content-driven columns; the same withdraw row now measures 84px and the
  1100px table scrolls inside its own `overflow-x-auto` wrapper.
  `x-admin-dashboard.badge` and `x-admin-dashboard.button` also declare
  `[overflow-wrap:normal]` so their labels never break mid-word.
- **Both confirmation dialogs now match the shell** (`bg-admin-surface`,
  `border-admin-line`, `--admin-radius`, `shadow-admin-lg`, 44px close target).
  The Alpine open/close/Escape/focus contract and the form they submit are
  untouched.
- **Emoji left headings and buttons only** (`📢 Siaran`, `📤 Kirim Siaran`,
  `🎉 Kirim Info Pembelian Fake`). The remaining emoji in these surfaces name a
  real action or status; heading emoji competed with the real status badges.
- **`admin-dashboard-input` and `earnings-bar-*` CSS were deleted** after their
  last consumers moved to `x-admin-dashboard.input` and the accent-filled
  earnings track. `.neo-input` stays because the Storefront still uses it.

### Exercised states (browser lane, disposable SQLite fixtures)

| Surface | Valid | Invalid / error | Empty / stale / gated |
| --- | --- | --- | --- |
| Ringkasan | metrics, earnings bars, payment mode, provider readiness, Binance toggle, TTL form, Support WhatsApp, BSC receiver, IDR/USDT policy, balance operations, checkout maintenance | server validation keeps `old()` and reopens the section; maintenance confirmation rejects an unconfirmed submit | provider not-ready reason, closed USDT rails, paused scopes, open restore gate, zero open alerts, `Belum ada BSC Receiving Address` |
| Siaran | message form, queue counters | over-length message and empty message return named `broadcast` errors with retained text | empty queue state |
| Store Channel | channel settings, test action, fake sales proof | named `storeChannel` errors; setup warning | fallback banner, `Standby` state |
| Riwayat KlikQRIS | table + mobile cards, pagination | — | mismatch notice, stale marker, provider-unavailable notice |
| Withdraw | filter form, table + mobile cards, proof link | invalid filter notice | empty filter state, pagination |
| Funnel | ten filters, summary, product/family tables, rails/outcomes/support, source table | `state === 'error'` empty state | empty product/family/source states, 31-day bound copy |
| Kompensasi top-up | filter form, queue, release dialog | `balanceCompensation` error, required provider reference/reason | not-eligible row, empty queue |
| Laporan saldo | section/currency pills, filter form, rows, cursor link | — | `Tidak ada data untuk filter ini`, custody unavailable badge |
| Kelola saldo | exact Telegram-ID lookup, amount/reason form, preview, password confirm, history, positive balances | wrong password notice, reversal without eligibility | preview expired/unavailable states |
| Review deposit USDT | target table, evidence, decision form, recovery forms, observations, incidents | required hash/amount/reason | `Belum ada target top-up`, `Belum ada insiden reorg` |

### Passing-after checks (final tree)

Run from `src/`:

- `php artisan test --filter=AdminDashboard` — passed, 139 tests / 1,750 assertions.
- `php artisan test --filter='AdminBalanceAdjustmentDashboardTest|AdminBalanceReportTest|AdminUsdtTopUpReviewTest|AdminStoreChannelTest|StoreBalanceOwnerCompensationTest|AdminBalanceOperationsPanelTest|CheckoutMaintenanceTest|AdminTelegramBroadcastQueueTest|AdminDashboardPaymentModeTest|UsdtFundingPolicyTest|BalanceOperationsMetricsTest|BalanceRestoreGateTest|BalanceReleaseReadinessReportTest|BalanceReceiptDeliveryPauseTest|ProductionReleaseHardeningTest|StorefrontLandingPageTest|AdminCategoryCrudTest'` — passed, 234 tests / 1,916 assertions.
- `php artisan test --filter='KlikQrisReportingTest|StorefrontFunnelReportTest'` — passed, 23 tests / 133 assertions.
- `npm run test:js` — passed, 57 tests.
- `npm run build` — Vite production build passed (also rebuilt by the browser lane).
- `npm run test:browser:admin` — passed, 106 tests (10.7m), including the new financial/communication lane, the Ringkasan metrics/earnings lane, the payment-switch/maintenance dialog workflow, the badge-readability assertion, and the 200% text table-overflow check.
- `php artisan view:cache` — all rewritten views compile.

Screenshots for the record: `src/test-results/**/{ringkasan,broadcast,store-channel,riwayat,withdraw,funnel,balance-issues,balance-reports,balance-adjustments,usdt-review}-{375,768,1280}-{light,dark}.png` written by the new lane (ignored by git).

### Delivery Gate (issue #604 scope)

Design Read, declared before edits: admin summary, communication, reporting and
finance surfaces for the store Owner/Admin running real payment, balance,
provider-report and compensation work, in the canonical Variant A language:
dial **ENERGY 1 / RHYTHM 2 / MOTION 1**. ENERGY 1 because these are dense
operational surfaces where one accent marks the primary action; RHYTHM 2
because section groups repeat but a table, a form, a queue and a read-only
report each need their own shape; MOTION 1 because nothing here should move
beyond the existing hover/transition feedback.

| Gate item | Result and evidence |
| --- | --- |
| R-02 punctuation | PASS: no em dash introduced; the pre-existing `'—'` placeholders in rendered server values and the pinned operator copy are untouched. |
| R-03 mobile layout | PASS: the new lane asserts zero document overflow on all ten surfaces at 375/768/1280px in both themes; the no-JavaScript owner/admin 320/375/1280px cases (including 200% text) still pass, and the 200% text check keeps the withdraw/riwayat/kompensasi tables scrolling inside their own wrappers. |
| R-17 sourced statistics | PASS: every rendered number comes from the fixture database (GMV, Saldo, order counts, earnings bars, funnel aggregates, balances). |
| R-18 testimonials | PASS: none. |
| R-23 assets/navigation authority | PASS: no assets or navigation items added; existing authorized groups retained. |
| R-24 real destinations | PASS: every link/action keeps its `route(...)`; route-inventory diff per file is identical. |
| R-25 text contrast | PASS: measured pairs below, all >= 4.5:1. |
| R-26 functional controls | PASS: every control is still a real form post, link or Alpine handler; the browser lane submits the real navigation, filters, uploads and dialog workflows. |
| R-27 states | PASS: empty, stale, provider-unavailable, gated and validation states rendered from real fixture data and exercised. |
| R-28 FAQ relevance | PASS: no FAQ touched. |
| R-32 keyboard/focus | PASS: the payment-switch and maintenance dialogs focus their confirm action, close with Escape and restore the opener; tables, filters and the release dialog keep 44px targets and visible focus. |
| R-33 source implementation | PASS: Blade/CSS/spec source only; no runtime style patching. |
| R-34 themes | PASS: every surface rendered in light and dark at three widths; tokens measured in both themes. |
| R-35 executed surface | PASS: feature suites, JS tests, Vite build and the 105-case browser lane ran on the final tree. |
| R-36 honest claims | PASS: no security/performance/customer claims; no production deployment; numbers are local measurements. |
| R-37 direction | PASS: Variant A and ENERGY 1 / RHYTHM 2 / MOTION 1 declared before the first edit. |
| R-38 real content | PASS: production labels unchanged; fixtures stay inside the disposable browser database. |
| R-01 gradients/glow | PASS: the gradient earnings bar was replaced by a flat accent fill; the gradient `store-link-section` styling is no longer rendered on the dashboard. |
| R-04 icon purpose | PASS: heading emoji removed; the remaining emoji name an action. |
| R-06 typography purpose | PASS: uppercase/tracked operational labels replaced by the shared title/label scale; mono restricted to data-like values. |
| R-07 background patterns | PASS: no grid/dot pattern added. |
| R-08 decorative arrows | PASS: existing `←`/`→` glyphs on navigation and not-yet-available states remain; no new decorative arrows. |
| R-09 badges | PASS: badges report real state (queue status, provider readiness, rail availability, custody, pause state) with a text label, never color alone. |
| R-10 glass | PASS: no blur/backdrop effect added. |
| R-12 elevation | PASS: cards use `shadow-admin-sm`, dialogs `shadow-admin-lg`; the legacy hard-offset shadows are gone. |
| R-13 glow everywhere | PASS: no glow. |
| R-14 card hierarchy | PASS: cards follow the content (section, queue row, metric row, report row) instead of one uniform tile. |
| R-19 motion purpose | PASS: no animation added; only existing color transitions remain. |
| R-22 illustration purpose | PASS: none. |
| Liveliness: explicit dials | PASS: ENERGY 1 / RHYTHM 2 / MOTION 1 recorded in the Design Read. |
| Liveliness: dial consistency | PASS: dense operator rhythm with per-surface shape; the lane screenshots show the same card/heading/fact-pair language across all ten surfaces. |
| Liveliness: focal point | PASS: each surface keeps one accent-filled primary action (Terapkan filter, Kirim Siaran, Simpan kebijakan, Release saldo, Konfirmasi resolusi). |
| Liveliness: structural whitespace | PASS: `space-y-4`/`space-y-5` groups and 16-24px section gaps separate operational blocks. |
| Liveliness: deliberate accent | PASS: one muted violet accent; semantic colors only for real state. |
| Liveliness: identity motif | PASS: LHIE STORE, the grouped sidebar, and the repeated eyebrow/title/fact-pair rhythm identify the product. |
| Liveliness: prior Design Read | PASS: declared before the first edit. |
| C-1 intentionality | PASS: every decision above carries its one-line reason. |
| C-2 completeness | PASS: no control was left decorative; the removed emoji and card fills carried no function. |
| C-3 content-driven composition | PASS: no section added or removed; the Ringkasan order is unchanged. |
| C-4 resilience | PASS: light/dark, three breakpoints, 200% text, keyboard paths, and empty/error/stale/gated states exercised. |
| C-5 evidence | PASS: contrast from the contrast tool on the rendered token pairs; rendered-surface evidence from the browser lane and screenshots. |
| R-05 non-template layout | PASS: operational hierarchy retained; no marketing template introduced. |
| R-11 radius variation | PASS: `--admin-radius` for cards/dialogs, smaller radii for nested wells and controls; nothing became pill-shaped except the existing status badges and filter pills. |
| R-15 concrete actions | PASS: labels name the operation (Terapkan filter, Release saldo, Buat preview resolusi, Otorisasi case recovery). |
| R-16 language | PASS: no buzzwords; operator copy unchanged. |
| R-20 product identity | PASS: real catalog, order, provider and balance data with LHIE STORE identity retained. |
| R-21 theme choice | PASS: user-controlled light/dark retained and exercised on every touched surface. |
| R-29 palette | PASS: existing neutral surfaces plus one violet accent plus semantic state colors. |
| R-30 originality | PASS: repo-specific Variant A retained. |
| R-31 written reasons | PASS: recorded above. |

### Measured contrast (light / dark)

These are the token pairs the restyle reuses; values were verified with the
contrast tool for this ticket.

| Pair | Ratio |
| --- | --- |
| `--admin-muted` on `--admin-surface` | 5.9:1 light / 7.79:1 dark |
| `--admin-muted` on `--admin-surface-muted` | 5.17:1 light / 6.78:1 dark |
| `--admin-accent-ink` on `--admin-accent` | 5.18:1 light / 8.67:1 dark |
| `--admin-accent-strong` on surface | 6.67:1 light / 8.67:1 dark |
| semantic success / warning / info / danger text on their soft fills | 6.68:1 / 6.6:1 / 4.69:1 / 6.8:1 light, 9.74:1 / 8.93:1 / 9.88:1 / 9.47:1 dark |

Every pair clears WCAG AA for normal text (4.5:1), and each semantic state also
carries a text label, so no status is conveyed by color alone.

## Historical #600 verification record

The earlier #600 note listed 139 PHP tests / 1,747 assertions, 56 JS tests, a Vite build, 87 admin browser tests and 3 BuyMail browser tests as passing. It did not retain a commit-bound command report here. Those historical claims are not current verification, do not supersede the later review failures, and do not authorize release. Use the [#605 command/outcome record](admin-dashboard-release-parity.md#commands-and-outcomes), including failed and not-run cases, instead of carrying these counts forward.

## Rollback

No database reset or data rollback is required for this presentation change. The [#605 rollback record](admin-dashboard-release-parity.md#release-note-and-rollback-evidence) identifies the historical known-good commit and immutable image from successful Actions run 37860180743. Before an eventual approved release, `.github/workflows/deploy-production.yml` must capture the then-running previous commit/image again. Use its existing gated release/rollback mechanism, never a mutable image tag or database reset. No deployment is authorized until current verification, real Telegram client evidence and human sign-off are complete.
