# Admin Dashboard Sidebar Cutover

Status: implemented in the working tree; production deployment is not authorized by issues #600 or #601.

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

## Historical #600 verification record

These historical counts do not establish #601 regression coverage or authorize deployment. Commands were run from `src/`:

- `php artisan test --filter=AdminDashboard` — passed, 139 tests / 1,747 assertions.
- `npm run test:js` — passed, 56 tests.
- `npm run build` — passed with Vite production assets.
- `npm run test:browser:admin` — passed, 87 Playwright tests.
- `npm run test:browser:buymail` — passed, 3 Playwright tests.

## Rollback

No database reset or data rollback is required. If the presentation release is rejected, deploy the prior known-good application image/commit through `.github/workflows/deploy-production.yml` from `main`. Record both the released commit and prior image/commit in the GitHub Actions release evidence before deployment. Do not deploy this cutover until the named verification commands and the real-client checklist are complete.
