# Admin Dashboard release parity (#605)

Status: **release acceptance blocked, not release-ready**. This record implements the verification inventory for [#605](https://github.com/Alhiefikri/telegram-bot/issues/605), not deployment authorization or human sign-off. [#591](https://github.com/Alhiefikri/telegram-bot/issues/591#issuecomment-6078578976) sections 10, 16.3 and 17 are the contract. #601, #602 and #603 were CLOSED when checked; #604 was OPEN. Owner decision on 2026-10-10: commit and push the verification work, then close #605 administratively. That closure does not mark missing evidence PASS or authorize deployment; the parent release gate remains blocked until every required row has current evidence.

## Candidate and safety

Verification date: 2026-10-10. Base commit: `51bbe6acaf41a9819fd1d1944f1054fb03a25ca5`, initially clean. The candidate is the commit containing this record and the #605 verification changes; the base SHA alone does not identify them. The retained source manifest records SHA-256 of the tested browser/PHP files. No application controller, view, CSS, business contract, migration or deployment setting changes in #605.

PHP checks use the existing SQLite/array/sync test configuration and provider/Telegram fakes. Browser lanes use disposable SQLite fixtures and `laravel-browser-router.php`, which rejects stray provider HTTP. Browser imports/mappings remain inactive, pricing remains manual, and no supplier purchase is made. Real-device work must use an isolated staging Mini App with fake outbound, not production balances, stock, supplier accounts or channels.

Artifacts are retained locally under `.slim/issue-605/`, outside Playwright's default output directory. They are not automatically published or durable CI artifacts. Upload this evidence directory with the eventual candidate-bound CI/review record before release; do not substitute historical screenshots or counts. JSON reports distinguish expected, unexpected, skipped and flaky results; serial-suite cases skipped after a failure are **not run**, not successful coverage. Initial failures remain retained even after a passing corrected run.

## Commands and outcomes

All commands below run from `src/`. The Vite build ran once via the initial browser smoke. Subsequent browser invocations reuse those unchanged runtime assets.

| ID | Command | Current evidence |
| --- | --- | --- |
| P1 | `php artisan test --filter='AdminDashboard\|AdminOrderPaymentOperations\|ManualFulfillmentReview\|AdminTelegramOutboundEffectReview\|AdminBalanceAdjustment\|AdminBalanceReport\|AdminBalanceOperationsPanel\|AdminUsdtTopUpReview\|SupplierAdmin\|SupplierDeposit\|WarrantyAdmin\|WarrantyWeb\|AdminStoreChannel\|AdminTelegramWelcomeBanner\|AdminCategoryCrud\|AdminCatalogPolicyAndStockAudit\|AdminStockBulkDeletion\|AdminTelegramBroadcast'` | Exit 0; 384 passed, 12 skipped, 3,740 assertions. The 12 PostgreSQL-only skips are not locking evidence. |
| P2 | `php artisan test --filter='BscReceiverConfigurationTest\|StoreBalanceFundingPolicyTest\|UsdtFundingPolicyTest\|CheckoutMaintenanceTest\|StorefrontBannersTest\|ProductFamilyCatalogTest\|CsxStoreHistoryRecoveryTest\|SupplierPricingClarityTest\|StoreBalanceOwnerCompensationTest\|StorefrontFunnelReportTest\|KlikQrisAdminObservabilityTest' --log-junit=../.slim/issue-605/linked-php-qualified.xml` | Exit 0; 188 passed, 0 skipped, 2,033 assertions. Initial exit 1: 187 passed, 2 failed, 2,027 assertions. Both failures matched literal old `text-brand-muted` markup. Removed those styling assertions and the dash-only test; retained sanitized data/secret checks and the actual reconciliation timestamp. |
| J1 | `npm run test:js` | Exit 0; 57 passed, 0 failed, 0 skipped. |
| B0 | `npm run test:browser:admin -- --grep 'financial and communication surfaces\|operational surfaces keep\|no-JavaScript owner tabs'` | Exit 0; Vite build passed, then 5 browser cases passed using the observational overflow helper. Diagnostic subset, not full release coverage. |
| B1 | `PLAYWRIGHT_JSON_OUTPUT_NAME=../.slim/issue-605/admin-complete-report.json npx playwright test tests/Browser/admin-dashboard-responsive.spec.js --output=../.slim/issue-605/admin-complete --reporter=line,json` | Exit 0; 106 passed, 0 failed, 0 skipped/not-run, 0 flaky. Initial full run: 17 passed, 1 timeout, 88 not run (`admin-report.json`). Later original-layout run: 42 passed, 1 accessibility-label measurement failure, 63 not run (`admin-qualified-report.json`); not full coverage. |
| B3 | `PLAYWRIGHT_JSON_OUTPUT_NAME=../.slim/issue-605/nojs-body-qualified-report.json npx playwright test tests/Browser/admin-dashboard-responsive.spec.js --grep 'no-JavaScript (owner\|admin) tabs' --output=../.slim/issue-605/nojs-body-qualified --reporter=line,json` | Exit 0; 6 passed, 0 failed, 0 skipped. Owner/Admin at 320/375/1280px: every permitted tab exposes a substantive native control or data row in the viewport, clear of the topbar, before original-layout overflow checks. The initial body-check run failed in the animation-frame scroll helper; no-JS body checks now use instant native scrolling and observed geometry. |

`admin-final-report.json` is an intermediate failed run, not release evidence: 0 passed, 1 setup timeout, 105 not run. The full lane now gives its `beforeAll` the existing 120-second setup budget, without weakening any scenario. It also excludes intentionally zero-area CSS `clip` / `clip-path` screen-reader text, not ordinary overflow clipping. `clipping-boundary-proof.json` records the current helper accepting accessible labels, rejecting ordinary clipped text and leaving root styles unchanged. Source snapshots and `source-manifest.json` bind each browser invocation to its exact test source; B1's current source is `admin-complete.source.js`, while B3's pre-exclusion source is `admin-nojs-qualified.source.js`.

Both browser files now have a 120-second scenario budget, retaining all behavior assertions; existing multi-viewport cases keep their larger explicit budgets. The initial admin timeout occurred in the Cart click after fixture preparation. A longer scenario budget is not a performance qualification and does not bypass stable/visible/enabled click checks. Browser measurement no longer writes `overflow-x: visible!important` to any production element. It reads original document/body widths and text rectangles, rejects unscrollable horizontal clipping, and allows genuine local horizontal scrollers and intentional ellipsis. It does not establish vertical clipping or real-device compatibility by itself.

The removed Ringkasan browser case tested heading strings and seven styled `div` nodes, not monetary values. Summary values/day boundaries remain covered by the dashboard PHP lane; no replacement styling snapshot was added. New supplier browser coverage protects invalid native import (422), browser back, valid inactive draft import, mapping, manual pricing, retained retail price, zero purchases and API-key redaction.

## Canonical tabs

Evidence references name the exact lane and test, not a claim that every control in that tab was clicked. B1 passed its complete 106-case file; unexercised obligations below still block release closure.

| Tab | Preserved behavior and boundaries | Automated evidence | Remaining release evidence |
| --- | --- | --- | --- |
| `stats` | Four real summary metrics, earnings/day boundaries, IDR/USDT separation; payment/provider, maintenance, funding, receiver and support controls | P1 overview/payment-mode/return-targets; P2 policy/receiver/maintenance; B1 direct tabs, financial surfaces, payment/maintenance modal | Browser valid/invalid submissions for every summary setting; full keyboard traversal; real Telegram |
| `produk` | CRUD, default image, localized copy/icon/custom emoji, tiers, family/category membership, banners, legacy/structured Stock Intake, redaction and bulk delete | P1 product/stock/category/banner; P2 family/banner; B1 upload contracts, product edit/locales/tiers, family/category/stock/account-input/bulk-delete cases | Real Telegram product/stock keyboard, validation, rotation and modal return |
| `penjualan` | Filters, pagination, Order/Payment operations; payment and delivery remain distinct | P1 sales rendering, Order operations, returns; B1 filter/history/direct tabs and order detail | Browser valid/invalid forms for each order mutation; real Telegram linked-page return |
| `suppliers` | Owner/Admin connections, currency/cost units, privacy, versioning, password confirmation | P1 SupplierAdmin; P2 CSx recovery/pricing; B1 operational surfaces; B2 create/edit/password secret-preservation | Complete browser recovery, history/deposit POST and error-return clicks; real Telegram |
| `broadcast` | Message/image form, named errors, queue feedback and fake outbound | P1 broadcast classes; B1 direct/mobile/no-JS invalid POST and retained textarea | Valid broadcast browser POST plus long/empty/loading/error visual review in both themes |
| `telegram-channel` | Configuration, test and fake Sales Proof Post controls; no production send | P1 AdminStoreChannel; B1 financial/communication surface and direct tabs | Browser valid/invalid/test/fake-proof POST, error return and keyboard traversal |
| `riwayat` | Read-only provider history, local mismatch, pagination and unavailable provider | P1 reporting; B1 reporting cases and financial surfaces | Browser provider-unavailable/error-state visual review and filter/pagination click-through |
| `withdraw` | Capability gate, filters/pagination and safe transfer-proof links | P1 reporting; B1 reporting cases, local table scrolling, financial surfaces | Browser invalid filter, pagination and unavailable-provider states; external proof opening in staging |
| `funnel` | Capability gate, filters/date range and bounded report reads | P1 reporting; P2 StorefrontFunnelReport; B1 no-JS GET date filters and responsive funnel | Full empty/error/long-state visual review; browser back/forward with report filters |
| `balance-issues` | Owner-only compensation, exact units, immutable target and password/readiness boundaries | P1 access/navigation; P2 StoreBalanceOwnerCompensation; B1 financial surfaces and local table scrolling | Browser compensation valid/invalid/password/error return and real Telegram |

## Linked operational and financial workflows

This is the #591 section 16.3 route inventory. Each mutation family includes its server role/validation/version/secret/money boundary; PHP evidence is not a browser click-through.

| Workflow / existing routes | HTTP/domain evidence | Browser evidence | Missing evidence |
| --- | --- | --- | --- |
| `admin.orders.show`; complete-manual, reject-manual, sales-proof.retry, cancel-release; payments.reconcile | P1 AdminOrderPaymentOperations; P2 sanitized KlikQRIS observability | B1 order detail and shared topbar | Each valid/invalid mutation and resulting return target in browser |
| Manual Fulfillment Review index/show/resolve | P1 ManualFulfillmentReview | B1 index layout | Detail fixture, successful/rejected resolve, validation/focus return in browser |
| Manual Delivery Review index/show/mark-sent/resend | P1 AdminTelegramOutboundEffectReview, including role and duplicate-risk acknowledgement | B1 index, Unknown detail redaction; manual local native password smoke is separate | Browser mark-sent/resend valid/invalid POST and focus return |
| Supplier catalog/import | P1 SupplierAdmin idempotent draft/copy/validation | B1 catalog layout, 422 native import/back, inactive draft with store-authored copy | No-JS native catalog form walkthrough in Owner/Admin, empty catalog/error visual review |
| Supplier mapping/map/move/detach and product pricing/preview | P1 SupplierAdmin version/equivalence/ranking/detach; P2 SupplierPricingClarity | B1 pricing layout, native map/manual price, unchanged retail and zero purchases | Browser stale/invalid mapping, move/detach confirmation/cancel, SKU preview/error return |
| Supplier connections/store/update/sync | P1 SupplierAdmin role, secret, CSRF, version and password checks | Historical unsaved-key create/edit, wrong password/cancel/confirmation and secret/session checks; maintained scenario is `supplier-connection.spec.js` | Sync success/backoff/error visual walkthrough with fake provider |
| Purchases/operate/recovery/history/history-confirm | P1 SupplierAdmin; P2 CsxStoreHistoryRecovery exact identity/quantity/cost/password/history boundary | B1 monitor layout | Populated ambiguous/review fixture, all applicable safe actions and rejected/error return in browser |
| Balance reports index/signed top-up proof | P1 AdminBalanceReport role, filtering, units and signed proof access | B1 linked report layout | Browser filter/pagination/proof access and forbidden/error states |
| IDR adjustment index/previews/confirm/cancel/reversals | P1 AdminBalanceAdjustmentDashboard and AdminBalanceAdjustment | B1 exact Telegram-ID preview and duplicate-confirm guard | Browser reversal/cancel/invalid/password/readiness return; real Telegram |
| USDT review index/previews/confirm/recovery-previews/recovery-confirm | P1 AdminUsdtTopUpReview | B1 linked review layout | Browser populated review/reorg cases, exact units, denied/stale/password return |
| Login/logout and password.confirm/store | P1 AdminDashboardAccess; P2 receiver/password-bound operations | B1 login/password visibility/bogus credentials/auth contrast; B2 inline password | Browser logout/session expiry, standalone intended-return flow; real Telegram login/session |

## Cross-cutting preservation contracts

| Contract | Evidence | Status / gap |
| --- | --- | --- |
| Owner/Admin allowed tabs; Guest/Finance/Support forbidden; hidden navigation is not authorization | P1 Access/NavigationContract; linked P1/P2 role cases | PHP verified; browser forbidden/readiness variants not fully exercised |
| Readiness and inactive-tab lazy loading | P1 Overview/NavigationContract/financial classes; P2 policies/BSC readiness | PHP verified; all browser unavailable variants not run |
| CSRF, named errors, old input, exact return targets, stable hashes | P1 ReturnTargets and linked POST cases; B1 product/broadcast validation | Representative browser coverage; exhaustive linked error returns not run |
| Drawer open/close/backdrop/Escape/Tab/Shift+Tab, resize, lock ownership and destruction | J1 navigation transitions; B1 drawer tests | Representative browser contracts verified; real Telegram not run |
| Product/family/category/stock modal open/close/focus and clean memory | J1 account-input/bulk-delete; B1 family/category/product/stock/account cases | Representative browser contracts verified; real Telegram not run |
| Query/hash/full-page navigation/back-forward; product-create intent | J1 navigation URL cases; P1 navigation contract; B1 ordinary links/filter/hash/history/drawer | Listed browser cases verified; exhaustive linked return flows remain missing |
| No-JS authorized tab bodies, fallback links and native POST/GET | B1/B3 Owner/Admin 320/375/1280 cases: substantive visible tab control/data clear of topbar, product family/default-save, retained invalid broadcast body, date-filter GET; desktop/mobile fallback | Listed browser cases verified; not a promise that JS-only stock/modals work without JS; complete linked-body/native-form review still not run |
| Original layout, not patched CSS | B1 observational helper on operational and financial surfaces at 320/390/768/1023/1024/1280, light/dark; clipping-boundary smoke | Listed browser cases verified; screenshots cover rendered first viewport, not every vertical content/state combination |
| Long/empty/error content | B1 long product/account data, empty/review panels, product/broadcast upload validation; P1/P2 provider/error data | Every linked loading/error/empty state in both themes still not run |
| 200% root text and local table scrolling | B1 upload/finance/no-JS/topbar cases | Text enlargement only. Native desktop browser 200% zoom/reflow is **not run**; do not call root font-size a zoom result |
| Keyboard-only, visible focus, touch targets and contrast | J1; B1 upload/native picker, product/stock/drawer/payment focus, rendered contrast cases | Representative contracts only; exhaustive keyboard/contrast audit of every linked control not run |
| Secret/money-unit boundaries | P1/P2 redaction, exact IDR/micro-USDT, versioned financial cases; B1 account privacy and supplier draft; B2 response/session/key checks | HTTP and representative browser evidence; no real-money QA |
| Copy/share/open-store fallback | B1 clipboard/share probes and topbar links | Emulated clipboard/share paths only; actual Telegram client fallback not run |

## Real Telegram device gate

Read-only device discovery found an attached Xiaomi `21061110AG` / `chopin`, Android **11**, official `org.telegram.messenger` **12.10.6**, versionCode **71122**. This metadata is not a successful Mini App scenario. No isolated HTTPS staging Mini App entry and authenticated fixture session were established for this device. `idevice_id -l` exited 255 (`Unable to retrieve device list`), so no iOS client/platform version was obtained. Do not expose ADB endpoints, login secrets or real Customer data in the published evidence.

| Required actual-client scenario | Android result | iOS result |
| --- | --- | --- |
| Launch existing staging admin entry; login/logout/session persistence/expiry | NOT RUN: staging entry/session missing | NOT RUN: device access missing |
| Collapsed and expanded WebView | NOT RUN | NOT RUN |
| Safe-area and content-safe-area insets; edge controls | NOT RUN | NOT RUN |
| Keyboard opening/closing on form fields | NOT RUN | NOT RUN |
| Validation scroll/focus with keyboard visible | NOT RUN | NOT RUN |
| Drawer open/close/backdrop/Escape or available keyboard equivalent | NOT RUN | NOT RUN |
| Rotation, viewport height and resize cleanup | NOT RUN | NOT RUN |
| Light/dark theme and persisted choice | NOT RUN | NOT RUN |
| Product modal, stock and structured intake modal | NOT RUN | NOT RUN |
| Copy/share/open-store fallback | NOT RUN | NOT RUN |
| Return from linked operational and financial workflows | NOT RUN | NOT RUN |
| Password confirmation and intended return | NOT RUN | NOT RUN |
| Sanitized screenshot/video, date, client/platform versions and human reviewer | Android metadata only; no scenario evidence/sign-off | Missing all evidence/sign-off |

CSS variable injection, headless viewport changes and Android Chrome cannot satisfy these rows. Required inputs for completion: isolated staging Telegram launch URL with fake outbound and authorized fixture session, accessible iOS Telegram device, retained recordings for both clients, and named human sign-off after the browser/HTTP gaps above are closed.

## Antislop Delivery Gate audit

This is a blocked release audit, **not an all-PASS UI delivery**. No UI redesign ships in #605. PASS below is limited to the cited evidence; NOT VERIFIED prevents release closure. Design Read: operational dashboard for store operators, canonical Variant A, ENERGY 1 / RHYTHM 2 / MOTION 1. Color separates primary actions from neutral operational facts; shared layout preserves known destinations; existing UI fonts remain for readability; whitespace separates task groups; cards group actual forms/queues; icons retain existing domain/action meaning. No asset or new navigation structure was invented.

| Item | Verdict and evidence |
| --- | --- |
| R-02 authored copy | PASS: new verification prose uses plain operational language, no em dash; existing UI-wide copy audit NOT VERIFIED |
| R-03 mobile | NOT VERIFIED release-wide: original-layout browser matrix passed; real-device/complete clipping gates still require evidence and review |
| R-17 factual numbers | PASS: counts come from executed commands, device values from read-only queries, rollback values from retained Actions evidence; fixtures explicitly local |
| R-18 testimonials | PASS: no testimonials added |
| R-23 assets | PASS: no visual assets or navigation structure created |
| R-24 destinations | NOT VERIFIED: direct/tab/linked representative tests exist; every linked return/action click remains required |
| R-25 contrast | NOT VERIFIED: rendered accent/auth samples are covered, not every state/control across all linked pages |
| R-26 real controls | NOT VERIFIED: click-through inventory explicitly lists unexercised controls |
| R-27 empty/loading/error | NOT VERIFIED: remaining state combinations listed above |
| R-28 FAQ | PASS: no Admin FAQ added |
| R-32 keyboard | NOT VERIFIED: representative traps/native inputs covered; full linked-page/device traversal missing |
| R-33 source edits | PASS: no runtime CSS/source patch script; observational measurement does not rewrite production CSS |
| R-34 themes | NOT VERIFIED release-wide: light/dark browser matrix passed; all device/state combinations remain missing |
| R-35 running/click-through | NOT VERIFIED release-wide: complete browser suite and native local password modal were exercised; exhaustive required interactions and real clients remain missing |
| R-36 honest claims | PASS: no deployment/release-ready/performance/security claim; initial failures and skips retained |
| R-37 direction | PASS: #591 section 16.2 and current DESIGN.md, declared before edits |
| R-38 honest content | PASS: existing production identity retained; new supplier data is isolated browser-fixture data |
| R-01 gradients/glow | PASS for #605: no visual technique added; source scope is verification/docs |
| R-04 icons | NOT VERIFIED dashboard-wide; no icons added in #605 |
| R-06 typography | PASS for #605: existing fonts preserved; exhaustive rendered readability still missing |
| R-07 background | PASS for #605: no background technique added |
| R-08 arrows | NOT VERIFIED dashboard-wide purpose audit; no arrow added in #605 |
| R-09 badges | NOT VERIFIED dashboard-wide purpose audit; no badge added in #605 |
| R-10 glass | PASS for #605: none added |
| R-12 elevation | PASS for #605: no shadows added; as-built neutral separators/elevation recorded in DESIGN.md |
| R-13 glow dose | PASS for #605: no glow added |
| R-14 cards | NOT VERIFIED dashboard-wide; operational cards unchanged |
| R-19 animation | NOT VERIFIED every reduced-motion/device path; no motion added |
| R-22 illustration | PASS for #605: none added |
| Liveliness: explicit dials | PASS: ENERGY 1 / RHYTHM 2 / MOTION 1 |
| Liveliness: consistency | NOT VERIFIED across every missing state/device |
| Liveliness: focal point | NOT VERIFIED across every linked state |
| Liveliness: whitespace | NOT VERIFIED across every linked state |
| Liveliness: accent | PASS for #605: DESIGN.md records existing single violet action accent and semantic status colors |
| Liveliness: identity motif | PASS for #605: existing LHIE STORE identity and shared navigation hierarchy retained |
| Liveliness: prior Design Read | PASS: declared before verification edits |
| C-1 intentionality | PASS for #605: original-layout measurement, exact candidate evidence and unchanged runtime serve release safety |
| C-2 completeness | NOT VERIFIED: unclicked controls remain inventoried |
| C-3 content-driven | PASS for #605: rows come from #591 and existing routes, not invented sections/features |
| C-4 resilience | NOT VERIFIED: real clients, zoom and exhaustive states missing |
| C-5 evidence | PASS: unknowns stay unknown; device metadata is not scenario PASS |
| R-05 layout | PASS for #605: no template layout created; full as-built review still pending |
| R-11 corners | PASS for #605: no visual change, existing 12px contract documented |
| R-15 actions | PASS for #605: no CTA changed |
| R-16 buzzwords | PASS: new documentation states concrete commands, outcomes and gaps |
| R-20 identity | PASS for #605: no identity change |
| R-21 theme choice | PASS for #605: existing preference/toggle preserved, no forced theme |
| R-29 palette | PASS for #605: current neutral/violet tokens documented, no palette change |
| R-30 originality | PASS for #605: canonical repo-selected Variant A retained |
| R-31 reasons | PASS for #605: purposes recorded above; full visual audit not claimed |

## Release note and rollback evidence

Release note: #605 removes layout mutation from overflow assertions, detects clipped body text, expands original-layout browser widths, adds native supplier workflow parity, removes obsolete styling-only checks, and corrects stale Admin design/evidence documentation. It changes no runtime business behavior and authorizes no deployment.

The latest successful production Actions run inspected was [37860180743](https://github.com/Alhiefikri/telegram-bot/actions/runs/37860180743), created 2026-10-08T23:34:24Z. Its `release-gate-diagnostics-37860180743` artifact (ID `11586181074`, not expired when retrieved) records:

- Known-good deployed commit: `ff97b28709f5b9d5fbc0d9f31a58269b55804b3d`.
- Immutable image: `ghcr.io/alhiefikri/telegram-bot@sha256:de64446050f55d2dd94801abc77a5455d22e1e8b07ad15856f4bc8750892ebf0`.
- Result: `deploy-succeeded-compatible-contract-pending`; state `compatible-candidate-running`, recorded 2026-10-08T23:36:58Z. Deferred outbound contract remains pending; do not infer it passed.
- Recorded candidate-release failed jobs: 0; operational/unrecognized queue depth: 0 at artifact capture. These are historical release checks, not a live VPS audit.

This is a verified historical rollback candidate, not a guarantee it is still the currently running release. Before an eventual approved release, the existing `.github/workflows/deploy-production.yml` must capture the then-running previous commit and immutable image again. Use its successful-CI/image gate and release journal/rollback mechanism; never reset the database, deploy mutable tags or perform direct VPS layout rollback. No workflow was dispatched, production endpoint mutated or database reset in #605.
