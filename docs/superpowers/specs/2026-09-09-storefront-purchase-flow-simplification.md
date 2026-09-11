# Web Storefront Purchase Flow Simplification

## Status

Approved for implementation planning on 2026-09-09. This specification records the target customer flow, implementation seams, safety invariants, responsive behavior, cutover, and verification contract. It is not an implementation patch.

Tracking issue: [#436](https://github.com/Alhiefikri/telegram-bot/issues/436). Implementation is intentionally not started; the approved output of this planning pass is the specification and ready-for-agent ticket set only.

## Executive decision

Retire the Product checkout modal rather than attempting another compact-modal iteration.

The Web Storefront will use a page-based purchase flow with one document scroll:

```text
Standalone Product
Catalog → Checkout review → Invoice/payment

Product Family
Catalog → Family option → Checkout review → Invoice/payment

Multi-item purchase
Catalog → Cart → Checkout review → Invoice/payment
```

`Beli sekarang` is the dominant purchase action. `Tambah ke keranjang` remains available as a secondary action for multi-item customers, but the two intents are no longer presented as peer actions inside one modal.

A dedicated checkout page will support both a direct Product source and the existing guest Cart source. The page may present different summaries for those sources, but both submit through the existing `POST /shop/checkout` transaction and `StorefrontShopCheckout` / `OrderCheckoutService` ownership.

## Context and observed evidence

The current flow was inspected in the real local Storefront at 320×568, 390×844, and 1280×800.

Observed customer-facing geometry:

- the mobile homepage was approximately 13,735 CSS pixels tall at 390×844;
- `/shop` was approximately 6,446 CSS pixels tall;
- a Cart containing one Product was approximately 2,101 CSS pixels tall;
- one-item Cart checkout review was approximately 3,445 CSS pixels tall;
- the Product checkout dialog at 390×844 exposed a 731-pixel scroll region containing approximately 1,826 pixels of content;
- at 320×568, the dialog exposed a 463-pixel scroll region containing approximately 2,012 pixels of content;
- at 1280×800, the dialog still required internal scrolling: approximately 1,695 pixels of content inside 639 pixels;
- the sticky payment button overlapped adjacent form content; the 320-pixel scenario measured approximately 20 pixels of overlap;
- available Product cards on the mobile homepage were approximately 414–444 pixels high, while some secondary metadata used 9-pixel text.

The current Product dialog combines Product detail, quantity, subtotal explanation, Cart mutation, buyer identity, optional notification/contact fields, payment-rail selection, legal consent, purchase submission, and pre-purchase support. The interface makes callers and users understand most of the checkout implementation at once. It is a shallow module presented in a constrained modal surface.

Previous issue #252 made the modal QRIS-first, bounded its scroll, collapsed optional support, and added a sticky CTA. That improved individual controls but did not solve the architectural problem: checkout remains a long document nested inside a dialog. Issues #397–#401 then made the modal lazy to remove provider readiness from catalog rendering. This specification preserves that discovery-performance outcome while retiring the modal presentation and its transport.

## Problem statement

The Storefront currently imposes avoidable interaction and navigation cost:

1. A Product card opens a loading dialog and then a full checkout dialog instead of moving to a stable purchase page.
2. Product Family selection navigates to a Product page that immediately covers itself with the checkout dialog.
3. Direct purchase and multi-item Cart purchase compete in the same surface.
4. Checkout uses nested scrolling and a sticky action inside the nested scroll container.
5. Optional fields and advanced payment rails occupy the main decision path.
6. Failed direct checkout restores into the modal lifecycle rather than a stable URL-addressable page.
7. The homepage and shop page repeat supporting content before and after the primary catalog task.
8. Mobile Product cards preserve too much desktop information, producing tall cards and undersized metadata.

The result is technically functional but perceptually slow, spatially unstable, and harder to recover from than a normal page flow.

## Desired customer outcomes

A customer should be able to:

- recognize the primary purchase action without comparing Cart and checkout intents;
- move from a standalone Product card to a complete checkout review with one activation;
- select a Product Family option and reach checkout with one additional activation;
- finish checkout using normal page scrolling without an overlay or nested scroll region;
- understand Product, quantity, current price, current availability, chosen payment method, and final action before Order creation;
- keep optional notification/support details out of the path unless needed;
- return to the previous catalog, Family, Product, or Cart page using ordinary browser navigation;
- recover validation, stock, maintenance, or provider-readiness failures on the same checkout URL with entered safe fields restored;
- complete the same flow with keyboard, touch, zoom, reduced motion, or JavaScript disabled;
- retain secure signed invoice and Recovery Key behavior after checkout succeeds.

## Goals

- Remove all Product purchase/checkout dialogs from public Storefront flows.
- Establish one page-based checkout review module for direct Product and guest Cart purchases.
- Reduce the direct standalone purchase flow to Catalog → Checkout review → Invoice/payment.
- Keep Product Family selection explicit without the intermediate auto-opening Product modal.
- Give `Beli sekarang` one dominant visual hierarchy and keep Cart as a secondary, separate intent.
- Make checkout use document scrolling only.
- Keep the default payment rail immediately understandable and place alternatives behind an explicit, accessible disclosure.
- Keep optional email and support contact fields collapsed until requested, except where a selected payment provider makes a field required.
- Compact mobile catalog cards without hiding price, availability, Product identity, or the primary purchase action.
- Reduce repeated homepage/shop supporting content and prioritize catalog discovery.
- Preserve all existing checkout, inventory, payment, consent, access, privacy, and idempotency invariants.
- Remove obsolete modal transport, state, tests, and presentation payload after all callers migrate.

## Non-goals

- No change to Product, Product Family, Cart, Order, Payment, inventory, or credential domain meaning.
- No change to payment provider selection, provider fallback policy, KlikQRIS, Sakurupiah, Binance Pay, or BSC verification semantics.
- No automatic fallback from one payment rail to another.
- No removal of explicit terms/refund consent.
- No user account requirement for guest checkout.
- No change to signed invoice authorization, Recovery Key issuance, or paid credential eligibility.
- No change to Telegram Store Bot checkout.
- No database migration solely for this UX work.
- No new frontend framework, SPA router, client-side Cart store, or design-system rewrite.
- No ENV configuration for catalog or presentation behavior.
- No attempt to preserve the obsolete modal endpoint or JavaScript interface as a compatibility shim after migration.

## Design principles

### Stable pages for committed tasks

A modal is appropriate for a short, reversible choice. Checkout is a committed multi-field task with validation, external payment readiness, recovery, and navigation requirements. It therefore belongs on a stable URL-addressable page.

### One dominant action

Every discovery surface has one visually dominant purchase action:

- standalone Product: `Beli sekarang`;
- Product Family option: `Beli`;
- Cart: `Lanjut checkout`;
- checkout review: `Buat invoice & bayar` or the selected provider-specific equivalent.

Cart mutation and Product detail remain available but do not compete visually with the dominant purchase action.

### Progressive disclosure, not hidden requirements

Optional email, optional support contact, advanced rails, detailed fee explanations, and pre-purchase support may be disclosed on request. Product identity, quantity, price/subtotal, chosen payment method, consent, errors, and the submit action remain visible in the main flow.

If a selected provider makes a contact field mandatory, the checkout page must open that disclosure, mark the required field, move focus to it on failed submission, and explain why it is required.

### Server authority remains unchanged

Discovery and checkout review values are advisory presentation snapshots. `OrderCheckoutService` remains authoritative and locks/revalidates Products, Cart items, settings, stock, and checkout maintenance before creating an Order.

### One module interface for checkout review

The page builder should hide source-specific loading, presentation, current payment choices, checkout-maintenance state, snapshot construction, return URLs, and SEO/private-page headers behind one checkout-page interface. Controllers and Blade must not reproduce these rules.

The interface must represent exactly two source variants:

- direct Product: public Product identity plus bounded quantity;
- guest Cart: active session Cart plus its signed/current snapshot.

Do not encode source differences as loosely related arrays assembled in controllers. A small immutable source value or equivalent typed representation is preferred if it reduces invalid combinations.

## Target interaction flows

### Standalone Product from catalog

```text
Catalog card
├─ Product name/image → Product detail
├─ Beli sekarang → GET direct checkout review
└─ + Keranjang → POST Cart mutation, then Cart or retained catalog context

Direct checkout review
├─ quantity
├─ buyer name
├─ selected payment method
├─ explicit terms/refund consent
├─ optional details disclosure
└─ POST existing checkout transaction

Success → signed invoice/payment page
Failure → same direct checkout review URL with safe input and error restored
```

The whole Product card must no longer be one checkout link. This avoids nested interactive controls and lets Product detail and purchase have distinct accessible names.

### Product Family

```text
Catalog Family card → canonical Family page
Family option
├─ option detail link when useful
├─ Beli → direct checkout review for that Product ID
└─ + Keranjang → existing Cart mutation
```

The Family page remains canonical/indexable discovery. Choosing `Beli` must not navigate to `/produk/{product}?checkout=1` or trigger an auto-opening overlay.

### Product detail

The Product detail page remains useful for merchandising, limitations, warranty, fulfillment expectation, waitlist, and support. Its primary buy CTA links to the direct checkout review page. It does not embed or automatically open a checkout dialog.

### Multi-item Cart

The Cart remains server-side and session-bound. It owns item quantity mutation and item removal. Checkout review displays Cart quantities as review data; customers return to Cart to edit them. This prevents two quantity-editing owners.

The Cart page should place its summary and `Lanjut checkout` action before long explanatory/support content on mobile.

## Route and request contract

### Existing routes retained

- `GET /shop/checkout` remains the guest Cart checkout review route.
- `POST /shop/checkout` remains the sole Web Storefront checkout submission route.
- Existing Cart mutation routes remain unchanged.
- Existing signed invoice/order routes remain unchanged.

### Direct checkout review route

Add one canonical page route for direct Product review, following repository naming conventions. Preferred contract:

```text
GET /shop/checkout/products/{product}
route name: storefront.shop.checkout.product
optional query: quantity=1..20
```

Requirements:

- implicit Product binding;
- non-public Product returns `404` rather than leaking its existence;
- public but unavailable/not-buyable Product renders a safe unavailable state with Product/Family/catalog return action and must not offer submit;
- quantity is normalized to an integer from 1 through `min(20, currently available stock)` for presentation; POST remains authoritative;
- GET performs no Customer, Cart, Order, Payment, reservation, provider mutation, queue, or outbound side effect;
- response is private and non-cacheable because it includes session recovery state and current payment readiness;
- SEO is `noindex, nofollow`;
- normal Product and Family return context uses bounded same-origin URLs owned by `StorefrontUrl`; never trust an arbitrary external `from` URL.

The existing checkout POST accepts either `product_id + quantity` or `cart_snapshot`. Validation must reject requests that supply both source variants or neither. The current checkout business transaction remains unchanged beyond any validation/interface tightening necessary to enforce that invariant.

## Checkout page presentation contract

The same Blade page renders direct and Cart sources through one stable presentation shape.

### Mobile content order

1. Back link with source-aware label (`Kembali ke produk`, `Kembali ke pilihan`, or `Kembali ke keranjang`).
2. Compact heading and safe error summary.
3. Order/Product summary.
4. Quantity control for direct purchase, or read-only item summary for Cart.
5. Buyer name.
6. Current/default payment method.
7. `Ubah metode pembayaran` disclosure when more than one eligible rail exists.
8. `Tambahkan email atau kontak bantuan` disclosure.
9. Explicit terms/refund consent.
10. One primary submit action.
11. Secondary support action after the transaction form.

### Payment method behavior

- QRIS/provider-defined primary choice remains selected by deterministic server policy, independent of locale.
- If exactly one payment choice is eligible, render it as the selected method without a redundant radio group.
- If multiple choices are eligible, show the selected choice summary and an accessible disclosure containing alternatives.
- Changing a choice updates visible label, explanation, amount terminology, and submit label consistently.
- Binance/BSC sub-rail choice remains explicit; selecting a parent label must not accidentally choose a sub-rail without customer action when the existing payment contract requires that action.
- No client-side choice is authoritative; POST validation and provider resolution remain canonical.

### Optional contact behavior

- Email and support contact are collapsed by default.
- Previously entered values or validation errors reopen the disclosure.
- Provider-required contact automatically reopens the disclosure and exposes the requirement before submission.
- Email remains notification-only and is not represented as proof of ownership.
- Support contact values remain subject to existing normalization and validation.

### Submit behavior

- Prefer an in-flow submit button.
- A viewport-sticky submit area is allowed only if browser evidence proves it never covers focused fields, consent, errors, browser chrome safe areas, or the final content at all required viewport sizes and zoom conditions.
- The submit action is disabled only for an explicit current reason that is also rendered in text. Do not create a disabled button with no recovery guidance.
- Duplicate activation remains safe through the existing checkout key/idempotency contract.

## Catalog and landing-page presentation

### Product cards

At mobile widths, available standalone Product cards show only decision-critical content:

- image or safe fallback;
- Product name, maximum two lines;
- price;
- concise availability/stock label;
- dominant `Beli sekarang` control;
- secondary Product detail and Cart affordance without nested links/buttons.

Long description snippets, sold count, detailed merchandising, and support copy are removed from the default mobile scan path. They may remain on larger layouts when space supports them.

Minimum rendered geometry and typography:

- every actionable control is at least 44×44 CSS pixels;
- normal mobile body text is at least 14 CSS pixels;
- compact metadata is at least 12 CSS pixels;
- focus indication remains high contrast and is not clipped;
- cards do not rely on hover to reveal actions.

The layout may use two columns only when each card can satisfy those constraints without clipping or 9-pixel metadata. At narrower widths, use a single-column compact card or row layout. The breakpoint must be selected from rendered evidence, not retained solely to satisfy the old two-column test.

### Family cards and options

Family cards continue to lead to the canonical Family page. Family option rows/cards prioritize option name/duration, price, availability, and `Beli`. They do not open a Product checkout modal.

### Homepage and `/shop`

- Primary catalog discovery must begin within the first meaningful mobile viewport after the header/hero.
- Verified purchase activity must not cover or materially push down the primary Product action. It may be compact, in-flow, and non-blocking.
- Keep one concise purchase explanation near the primary catalog; move detailed guidance to `/panduan` or an accessible disclosure.
- Remove repeated `Pilih Produk`, trust, purchase-step, access, and support messages when they communicate the same action or guarantee.
- `/shop` remains the canonical filterable catalog and keeps bounded `q`, `category`, `sort`, and `page` state.
- Search/filter state and page position remain recoverable through URLs and ordinary browser history.

## Responsive contract

Required browser widths: 320, 390, 768, 1280, 1440, and 1920 CSS pixels. Use at least one short mobile viewport such as 320×568 and one common mobile viewport such as 390×844.

At every required width:

- no document-level horizontal overflow;
- no checkout/modal nested scroll container;
- no fixed or sticky element overlaps inputs, consent, errors, or primary actions;
- no clipped focus ring;
- labels and errors wrap without obscuring their controls;
- payment choices and Product names tolerate repository test fixtures with long names;
- header/navigation remains usable without covering checkout content;
- the page remains usable at 200% browser zoom where the browser harness supports it.

For the deterministic browser fixture at 390×844, the direct checkout primary action should be reachable within two viewport heights from the top without opening optional disclosures. This is a flow budget, not a general hard cap on arbitrary merchant-authored Product detail text; the compact checkout summary must not render the full Product description.

## Accessibility contract

- Page title and one `h1` identify checkout review and current source.
- Back, detail, Cart, disclosure, and submit actions have distinct accessible names.
- Error summary receives programmatic focus after failed server validation and links or moves focus to the first invalid control.
- Disclosure controls expose `aria-expanded`/native `details` semantics and hidden fields are not focusable.
- Payment selection is keyboard operable and its checked state is programmatically available.
- Quantity controls announce quantity and subtotal changes without excessive live-region output.
- Focus follows normal document order; there is no focus trap on checkout.
- Reduced-motion preference removes non-essential movement.
- JavaScript-disabled submission remains a first-class flow, not a hidden emergency form duplicated in `<noscript>`.

## Safety and privacy invariants

The implementation must preserve all of the following:

1. Browser-supplied Product names, prices, provider labels, availability, and totals are never authoritative.
2. Direct and Cart checkout are revalidated by `OrderCheckoutService` under the existing lock order.
3. Checkout maintenance is checked authoritatively inside the checkout transaction.
4. Explicit terms/refund consent is required before Customer, Cart, Order, Payment, reservation, provider side effect, or outbound effect creation.
5. Terms version and consent timestamp remain snapshotted on Order.
6. Checkout keys remain deterministic/idempotent for the full direct or ordered Cart snapshot and selected rail.
7. A stale Product, price, stock, visibility, quantity, Cart, or payment setting produces a safe recoverable error and no duplicate commercial state.
8. CSRF, throttling, trusted-host, and signed Storefront middleware remain intact.
9. Recovery Key plaintext is returned only at initial issuance, never stored in plaintext, and never sent through analytics.
10. Modal removal must not expose credentials, Product Stock Items, provider secrets, QR secrets, raw exceptions, or protected payment payloads in catalog/checkout HTML.
11. Analytics contain only existing safe identifiers/codes and no buyer contact, Recovery Key, credential, or payment proof.
12. No new ENV setting is introduced for UX, catalog, or presentation configuration.

## Analytics contract

Preserve the existing funnel event family while changing its presentation source:

- Product card/detail buy activation records the existing checkout-open intent with a source such as `direct_checkout_page`;
- Cart checkout review records the corresponding Cart source;
- checkout submission continues to record accepted/rejected outcome and safe payment method metadata;
- removing modal loading/close events must not be replaced with noisy page-scroll or field-level tracking;
- failed page reloads or browser back/forward must not create a second Order and must not send sensitive form values.

If existing analytics only support a browser event endpoint, keep that adapter; do not move funnel rules into Blade or the domain checkout module.

## Error and recovery behavior

### Validation failure

Redirect back to the same canonical checkout source URL with safe old input and errors. Direct Product failures no longer redirect to `/produk/{product}?checkout=1` or reopen a modal. Cart failures return to `GET /shop/checkout`, not the Cart page, unless the Cart itself is invalid and requires repair.

### Stock/price/visibility change

Show a concise error summary and refresh the server-rendered summary. Preserve safe buyer/contact input and consent only according to current repository policy. Never silently substitute another Product or quantity.

### Checkout maintenance

Browsing, Product detail, Family discovery, and Cart mutation retain existing behavior. Checkout review renders the maintenance notice and recovery/status guidance. POST continues to fail closed through the authoritative maintenance guard.

### Payment readiness change

Refresh eligible payment choices on the checkout page. If the previously selected choice is no longer available, show a safe explanation and select the canonical default only for review; do not automatically submit or create a Payment on another rail.

### JavaScript/network failure

Because checkout is a normal server-rendered page, no JSON Product modal request is required. Normal links and forms continue working. Enhanced quantity announcements or disclosures may degrade to native input/details behavior.

## Module and source ownership

### New/deepened checkout review module

Create or deepen a Storefront checkout page module, likely around `StorefrontCartPageBuilder` or a replacement with a clearer name such as `StorefrontCheckoutPageBuilder`.

Its interface owns:

- direct-versus-Cart source validation;
- current Product/Cart presentation;
- bounded direct quantity;
- payment choices and canonical default;
- Cart snapshot for POST;
- checkout maintenance presentation;
- source-aware return URL;
- private SEO metadata;
- safe old-input/error restoration data expected by the view.

The controller chooses the source from the route/session and invokes this module. Blade renders the result. Neither controller nor Blade performs payment readiness queries or rebuilds checkout source rules.

### Existing ownership retained

- `StorefrontShopCheckout` composes direct and Cart submission into the existing checkout transaction.
- `OrderCheckoutService` owns authoritative transaction, locks, snapshots, reservations, and idempotency.
- `StorefrontGuestCart` and `CartService` own guest Cart association/mutation.
- `StorefrontProductPresenter` may continue to own full Product checkout readiness for Product and Cart reads until a smaller checkout-specific presentation is proven necessary.
- `StorefrontOrderAccess` owns signed order access and Recovery Key grants.
- payment preparation and provider adapters retain their current ownership.

### Obsolete modal implementation removed after cutover

After all entry points and failed-checkout recovery use the page flow, remove:

- `GET /produk/{product}/modal` and `StorefrontProductModalController`;
- `resources/views/storefront/partials/product-modal.blade.php`;
- modal inclusion from homepage, shop, Family/Product pages where present;
- `storefrontModal()` state, lazy fetch, AbortController/token, body lock, focus trap, modal restore, and auto-open code in `resources/js/storefront.js`;
- `data-modal-url`, eager modal payload attributes, and `?checkout=1` auto-open behavior;
- tests that assert the deleted modal transport/lifecycle rather than the new customer contract;
- full `modalPayload()` presentation fields if symbol references prove they are now unused.

This is a clean cutover. Do not keep deprecated routes, aliases, hidden modal markup, or dual checkout implementations.

## Performance contract

- Catalog pages remain free of eager per-Product payment-provider readiness work, preserving the outcome of #397–#401.
- Do not re-embed full checkout payloads into every Product card.
- Direct checkout may perform current readiness work only for the selected Product.
- Cart checkout may perform bounded readiness work for Cart items using the existing page builder behavior; no N+1 provider or Product query is introduced.
- Removing the modal must reduce Storefront JavaScript and eliminate the lazy Product modal request from the purchase path.
- Images retain existing optimized/fallback behavior.
- No SPA hydration or client-side catalog duplication is introduced.

## Rollout and clean cutover

1. Add the page-based direct checkout review contract while existing modal entry points remain untouched.
2. Redesign the shared checkout page for both direct and Cart sources.
3. Migrate Product card, featured offer, Product detail, and Family-option purchase links to the direct checkout page; migrate failed-checkout recovery.
4. Remove the modal endpoint, partial, payload, JavaScript state, auto-open query behavior, and obsolete tests in the same migration ticket.
5. Simplify homepage/shop information hierarchy without changing catalog query semantics.
6. Replace modal browser coverage with page-flow browser evidence across required viewports.
7. Update `docs/agents/codebase.md` to describe the page-based flow and remove modal guidance.

Do not retain a runtime feature flag. Each ticket must leave the application runnable, and normal Product links provide a natural rollback path during development.

## Acceptance criteria

1. Available standalone Product cards expose distinct Product-detail and `Beli sekarang` actions; activating purchase never opens a dialog.
2. Direct purchase reaches a canonical server-rendered checkout review page without creating Customer, Cart, Order, Payment, reservation, or provider side effects.
3. Product Family `Beli` actions reach direct checkout without routing through an auto-opening Product modal.
4. Product detail primary purchase action reaches the same direct checkout interface.
5. Cart checkout continues through `GET /shop/checkout` and the same view/presentation contract.
6. Direct and Cart POST submissions continue through the existing `POST /shop/checkout`, `StorefrontShopCheckout`, and `OrderCheckoutService` ownership.
7. Checkout POST rejects ambiguous source input containing both direct Product and Cart snapshot, and rejects missing source input.
8. Checkout uses document scrolling only and contains no `role=dialog`, body scroll lock, or checkout-specific nested overflow region.
9. The default payment method, visible explanation, amount terminology, and submit label always agree.
10. Optional email/support and additional payment choices are collapsed by default and reopen for old input, errors, or provider requirements.
11. Explicit terms/refund consent, version snapshot, and no-side-effect rejection behavior remain unchanged.
12. Failed direct checkout returns to the canonical direct checkout page with safe fields/errors restored; it never reopens a modal.
13. Failed Cart checkout returns to checkout review when review remains possible; invalid Cart state gives a clear Cart recovery action.
14. Modal route, controller, partial, payload attributes, auto-open behavior, and modal JavaScript are absent after cutover.
15. Catalog discovery remains credential-free and does not eagerly execute per-Product provider readiness.
16. Product/Family/catalog pages preserve public visibility, availability, waitlist, search, filter, sort, pagination, and return-context behavior.
17. At 320, 390, 768, 1280, 1440, and 1920 pixels there is no horizontal overflow, clipped focus, or overlapping fixed/sticky UI.
18. At 390×844, the direct checkout submit action is reachable within two viewport heights without opening optional disclosures for the deterministic browser fixture.
19. Every customer action is at least 44×44 CSS pixels; mobile body text is at least 14px and metadata at least 12px.
20. Keyboard, native browser back, reduced motion, 200% zoom, and JavaScript-disabled flows remain usable.
21. Signed invoice, payment verification, cancellation, Recovery Key, paid credential, and order-access behavior remain unchanged.
22. Funnel analytics retain safe open/submit outcomes without buyer contact, Recovery Key, credentials, or payment proof.
23. No database migration, new frontend framework, presentation ENV, provider fallback, or Telegram checkout change is introduced.

## Evidence path and verification plan

### Claim A: the purchase flow is shorter and no longer modal

Owner: browser/integration ticket.

Evidence:

- Playwright starts from home and `/shop`, activates a standalone `Beli sekarang`, and asserts the canonical checkout URL and absence of visible dialogs;
- Playwright starts from a Family card, selects a member, and asserts direct checkout without visiting an auto-opening Product modal;
- navigation request counting establishes the expected page sequence rather than inferring it from markup;
- JavaScript-disabled browser context completes navigation to the same checkout form.

### Claim B: checkout is responsive and does not hide controls

Owner: compact checkout presentation ticket for focused behavior; integration ticket for viewport matrix.

Evidence:

- exercise direct and Cart checkout at 320×568, 390×844, 768×900, 1280×900, 1440×900, and 1920×1080;
- compare `documentElement.scrollWidth` with viewport width;
- assert no checkout nested element has scrollable overflow;
- assert bounding rectangles of visible fixed/sticky surfaces do not intersect inputs, errors, consent, or submit controls;
- assert strict 44×44 touch geometry and minimum computed font sizes;
- run a 200% zoom scenario and keyboard traversal;
- assert the deterministic 390×844 CTA flow budget.

### Claim C: checkout safety and recovery remain intact

Owner: checkout review module and compact checkout tickets.

Evidence:

- focused feature tests for GET side-effect absence, hidden/unavailable Product behavior, source exclusivity, bounded quantity, private/noindex response, current payment choices, and safe recovery URL;
- existing `StorefrontShopCheckoutTest.php` covers direct checkout/payment rails;
- existing `StorefrontShopCartTest.php` covers session isolation, stale snapshot, multi-item checkout, duplicate submit, maintenance rejection, and fulfillment;
- explicit regression assertions for consent rejection producing zero Customer/Cart/Order/Payment/reservation/provider effects;
- failed direct and Cart submissions restore to their canonical review pages.

### Claim D: discovery remains performant and safe

Owner: entry-point migration ticket.

Evidence:

- existing catalog page builder and Product modal tests are replaced with assertions that catalog HTML contains checkout URLs but no full checkout choice payload;
- feature tests prove hidden Product `404`, Product Family availability, waitlist, and bounded return URL behavior;
- `StorefrontProductPresenter`/catalog tests prove no credential, Product Stock Item, Recovery Key, or provider secret appears in catalog HTML;
- frontend production build proves deleted modal imports/state leave no broken bundle.

### Claim E: downstream invoice/access behavior is unchanged

Owner: integration ticket.

Evidence:

- focused successful direct and Cart checkout scenarios reach signed invoice URLs;
- existing signed invoice, status refresh, cancellation, Recovery Key, and credential-delivery tests remain green;
- no production provider call is required for browser geometry evidence; use repository fake/manual payment fixtures.

### Preferred verification commands

Exact test filters may be adjusted to current names, but tickets must use focused project entry points from `src/`:

```bash
php artisan test tests/Feature/StorefrontProductCheckoutTest.php
php artisan test tests/Feature/StorefrontShopCheckoutTest.php
php artisan test tests/Feature/StorefrontShopCartTest.php
php artisan test tests/Feature/StorefrontLandingPageTest.php
npm run build
npm run test:browser:customer
```

Run the broader relevant Storefront suite and formatter once at final integration. The PostgreSQL concurrency gate is not required when implementation stays within presentation/GET review seams and leaves checkout locks untouched. If a ticket changes lock ordering, reservation behavior, or transaction ownership, stop and re-scope it rather than silently expanding this specification.

## Ticket decomposition contract

Implementation is split into five tickets with non-overlapping primary ownership:

1. [#437 — Page-based direct checkout review contract](https://github.com/Alhiefikri/telegram-bot/issues/437) — routes, typed source/page builder, GET safety, canonical recovery, and focused feature tests. No blocker.
2. [#438 — Compact responsive checkout and Cart presentation](https://github.com/Alhiefikri/telegram-bot/issues/438) — shared checkout Blade, disclosure hierarchy, Cart CTA ordering, and focused rendering/behavior tests. Blocked by #437.
3. [#439 — Purchase entry-point migration and modal retirement](https://github.com/Alhiefikri/telegram-bot/issues/439) — Product/card/Family/featured links, failed direct recovery integration, modal route/controller/partial/JavaScript deletion, focused migration tests, and frontend build. Blocked by #437 and #438.
4. [#440 — Homepage and shop information hierarchy simplification](https://github.com/Alhiefikri/telegram-bot/issues/440) — hero/supporting-section reduction and catalog-first responsive shell, excluding checkout, modal, and Product-card files. No blocker; may proceed independently.
5. [#441 — End-to-end responsive evidence and documentation cutover](https://github.com/Alhiefikri/telegram-bot/issues/441) — browser viewport/keyboard/no-JS flow matrix, stale browser-test removal, and codebase guide update. Blocked by #438, #439, and #440.

GitHub native sub-issue and dependency edges are configured on parent #436. All five child tickets carry `ready-for-agent`; no implementation work is part of this planning pass.

## ADR and domain impact

No domain vocabulary or state-machine change is intended. `CONTEXT.md` should remain unchanged unless implementation discovers that a customer-facing domain term is currently missing.

- ADR-0001 Recovery Key ownership remains unchanged.
- ADR-0002 checkout idempotency remains unchanged.
- ADR-0006 and current payment-provider ownership remain unchanged.
- The accepted Checkout Payment Assembly Architecture remains unchanged.
- `docs/agents/codebase.md` must be updated because it currently describes the modal-based Web Storefront flow and modal payload ownership.

If implementation requires changing any of those decisions, that is a specification conflict and requires a separate decision before code changes.