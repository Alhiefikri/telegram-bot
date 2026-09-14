# Implementation Plan: Soft Marketplace Storefront and `/pilihan` URL Migration

This plan operationalizes [`docs/superpowers/specs/2026-09-14-storefront-soft-marketplace-and-public-url-migration.md`](../specs/2026-09-14-storefront-soft-marketplace-and-public-url-migration.md).

## Branch and baseline

- Work branch: `feat/storefront-marketplace-migration`.
- Never implement or commit this work on `main`.
- Baseline purchase-flow contract: #436–#441, already completed. This plan changes presentation and public family URLs; it does not reopen checkout architecture.
- Canonical public family path after cutover: `/pilihan/{slug}`.
- Product detail stays `/produk/{product}`.
- Public copy must not expose `Product Family`, `Produk Keluarga`, `Pilih SKU`, or `SKU #...` as discovery language.
- Parent issue: [#477 — Soft marketplace storefront and public URL migration](https://github.com/Alhiefikri/telegram-bot/issues/477).
- Child issues: #479 (T1), #485 (T2), #482 (T3), #478 (T4), #480 (T5), #484 (T6), #483 (T7), and #481 (T8).

## Shared contracts every agent must consume

1. `StorefrontUrl` is the only shared public URL builder. Do not concatenate `/pilihan/` in views.
2. Existing internal route/model names may remain. Public path and rendered labels are the contract.
3. Legacy `/produk-keluarga/{slug}` is a 301 compatibility route. Unknown targets are 404, never a homepage redirect.
4. An old slug may resolve through a durable alias only when the target is current and public-visible. `from` is preserved only after same-origin validation.
5. Family options remain existing Products. No family-level stock, payment, credential, or checkout state.
6. Popularity is observed paid completed sales. Empty popularity falls back to available catalog without a fake claim.
7. New presentation/catalog values are DB/Admin-backed or existing static copy. Never add presentation ENV.
8. `sf-*` visual changes are Storefront-scoped. Admin `neo-*` styles are not part of the cutover.
9. Catalog/family rendering never initializes payment providers or exposes credentials/private order data.
10. Every child ticket must leave focused evidence; T8 owns broad integration checks.

## Ownership and dependency graph

```text
T1 URL foundation ─────┬── T3 catalog hierarchy ──┐
                       ├── T4 family choices ─────┤
T2 visual foundation ──┼── T5 product detail ─────┤── T8 integration evidence
                       └── T6 checkout CTA ───────┤
T1 ─────────────────────── T7 data/asset repair ────┘
```

T1 and T2 can start independently. T3–T6 consume their contracts. T7 requires the alias contract from T1. T8 waits for all behavior-changing tickets.

## Tiny-commit sequence

Each child should make small, reviewable commits in this order:

1. Add or update the focused behavioral test for the contract.
2. Implement the smallest source change that makes the test pass.
3. Remove obsolete public copy/path branches rather than keeping aliases in templates.
4. Run the child’s focused verification only.
5. Leave formatter/linter/full-suite work to final integration unless a focused command is required to understand a failure.

Do not create compatibility shims for the retired modal or a second checkout path.

## T1 — Canonical `/pilihan` URL foundation

### Goal

Make `/pilihan/{slug}` canonical, preserve old family links with safe 301s, and migrate every URL/SEO/return-context caller.

### Primary files

- `src/routes/web.php`
- `src/app/Storefront/StorefrontUrl.php`
- `src/app/Http/Controllers/StorefrontProductFamilyController.php` or a narrowly scoped redirect/resolver class
- `src/app/Storefront/StorefrontSeo.php`
- `src/app/Storefront/StorefrontSitemap.php`
- `src/resources/views/storefront/partials/breadcrumbs.blade.php` if it owns path text/links
- `src/tests/Feature/StorefrontProductFamilyDiscoveryTest.php`
- `src/tests/Feature/StorefrontSameOriginReturnUrlTest.php`
- `src/tests/Feature/StorefrontProductCheckoutTest.php`
- sitemap/SEO tests and relevant browser path assertions

### Implementation steps

1. Add tests for current canonical URL, legacy 301, invalid 404, safe/unsafe `from`, and alias resolution.
2. Change the public family route path to `/pilihan/{productFamily:slug}` while preserving the internal route name unless a symbol migration is truly necessary.
3. Add a legacy `/produk-keluarga/{slug}` GET route that resolves current slug or durable alias and redirects with 301.
4. Keep return context bounded to the existing `StorefrontUrl::isLocalUrl` policy. Do not reflect arbitrary query parameters.
5. Update `familyUrl`, checkout source recognition, checkout back-label recognition, sitemap locations, breadcrumbs, and SEO/JSON-LD URL generation.
6. Assert that no generated canonical/internal link uses the old public path.
7. If aliases require a table, keep the schema minimal and let T7 own data repair; T1 owns resolver behavior and contract tests.

### Acceptance

- `/pilihan/{slug}` 200 and self-canonical.
- `/produk-keluarga/{slug}` 301 to current canonical.
- old slug aliases redirect to the target’s current slug.
- invalid/private/deleted targets 404.
- unsafe `from` is dropped; safe same-origin `from` is preserved only for navigation context.
- checkout back labels/events remain correct.
- sitemap and metadata contain only canonical family paths.

### Verification

```bash
cd src
php artisan test tests/Feature/StorefrontProductFamilyDiscoveryTest.php
php artisan test tests/Feature/StorefrontSameOriginReturnUrlTest.php
php artisan test tests/Feature/StorefrontProductCheckoutTest.php
php artisan test tests/Feature/StorefrontSeoTest.php
```

### Non-goals

No CSS, copy overhaul, Product slug, payment/inventory change, or production data guess.

## T2 — Soft visual system, header, and compact featured rail

### Goal

Replace the heavy Storefront presentation with a light, consistent visual hierarchy and expose search/Cart without adding a frontend framework.

### Primary files

- `src/resources/css/app.css`
- `src/resources/views/storefront/partials/header.blade.php`
- `src/resources/views/storefront/partials/hero.blade.php`
- `src/resources/js/storefront.js` only for progressive enhancement and existing menu behavior
- focused browser evidence for header/hero geometry

### Implementation steps

1. Preserve existing `sf-*` token names and set the soft paper/surface/ink/border/action roles. Scope selectors to Storefront; leave `neo-*` Admin styles unchanged.
2. Add a prominent existing bounded search entry that submits to `/shop?q=...`.
3. Keep mobile menu focus/Escape behavior; add a reachable mobile Cart entry using existing server-rendered state.
4. Replace the oversized hero with a compact promise block and up to two DB-backed featured family/Product entries. Use existing public data and `StorefrontUrl`; do not introduce arbitrary config/ENV slide records.
5. Provide a no-JavaScript vertical fallback. If progressive controls/auto-advance exist, pause on focus/hover and disable under reduced motion.
6. Ensure the rail reserves image dimensions and does not create page-level horizontal overflow.

### Acceptance

- Storefront pages have light surfaces, 1px borders, 12–16px radius, and subtle shadows.
- Admin retains current neo appearance.
- Search, Cart, logo, menu, focus, keyboard, no-JS, and reduced-motion behavior work.
- Hero/rail does not consume the mobile first viewport or claim unsupported promotions.
- No new ENV/config presentation knobs are added.

### Verification

```bash
cd src
npm run build
npm run test:browser:customer
```

Run only the focused browser spec or project-equivalent filter while developing; T8 runs the complete matrix.

### Non-goals

No catalog listing hierarchy, family option card, Product detail, checkout, schema, or payment work.

## T3 — Catalog-first sparse merchandising

### Goal

Make available discovery the homepage’s primary task and handle empty popularity honestly.

### Primary files

- `src/app/Storefront/StorefrontCatalogPageBuilder.php`
- `src/resources/views/storefront/partials/catalog.blade.php`
- `src/resources/views/storefront/partials/shop/catalog.blade.php`
- `src/resources/views/storefront/components/family-card.blade.php`
- `src/resources/views/storefront/components/product-card.blade.php`
- `src/tests/Feature/StorefrontLandingPageTest.php`
- `src/tests/Feature/StorefrontCatalogPageBuilderTest.php`
- `src/tests/Feature/StorefrontShopCatalogTest.php`

### Implementation steps

1. Add a focused test fixture with no paid completed sales and assert the available catalog remains prominent.
2. Reorder homepage sections so search/categories and `Tersedia sekarang` follow the compact hero before support/editorial content.
3. Render `Paling banyak dibeli` only when `popularProducts` is non-empty and preserve its existing observed-sales ordering.
4. Keep `/shop` search/category/sort/pagination contracts and unavailable visibility.
5. Use family entries for grouped products and standalone Product entries as currently returned by `CatalogBrowse`; do not invent seller/rating/discount fields.
6. Ensure all cards use `StorefrontUrl` and preserve direct checkout vs Cart action hierarchy from #436.
7. Bump `StorefrontResponseCache` design version if cached homepage markup is incompatible with old HTML.

### Acceptance

- At 320×568 and 390×844 the catalog begins within the agreed reading budget.
- Empty popularity produces an available catalog, not a fake/empty trending claim.
- Real popularity remains visible only with a non-empty verified signal.
- Search, category, sort, pagination, unavailable, waitlist, and direct checkout behavior remain.
- No catalog response contains provider/private payloads.

### Verification

```bash
cd src
php artisan test tests/Feature/StorefrontLandingPageTest.php
php artisan test tests/Feature/StorefrontCatalogPageBuilderTest.php
php artisan test tests/Feature/StorefrontShopCatalogTest.php
```

### Non-goals

No family-page-specific copy, Product detail sticky bar, checkout, route resolver, or new sales domain.

## T4 — Family choices and option surface

### Goal

Make `/pilihan/{slug}` professional and decision-oriented while preserving existing Product option identities and actions.

### Primary files

- `src/resources/views/storefront/family.blade.php`
- `src/resources/views/storefront/components/family-card.blade.php`
- `src/app/Storefront/StorefrontFamilyPageBuilder.php` only when view data needs a safe presentation field
- `src/tests/Feature/StorefrontProductFamilyDiscoveryTest.php`
- focused browser family assertions

### Implementation steps

1. Replace public `Product Family`/`Pilih SKU`/`SKU #...` labels with `Pilihan`, option labels, and customer-relevant metadata.
2. Keep the family summary image/name/description/count/starting price from the existing projection.
3. Keep `Beli sekarang`/`Beli` as the dominant action for available options and Cart as secondary.
4. Keep unavailable options visible with detail/waitlist behavior and no checkout submit.
5. Preserve safe return URLs, direct review URL generation, existing Product IDs, and progressive details.
6. Ensure one-option families do not introduce an extra modal or duplicate decision page.

### Acceptance

- Rendered family pages contain no prohibited internal discovery labels.
- Every option exposes honest price and availability.
- Available option reaches existing direct checkout review; Cart action remains valid.
- Unavailable option cannot submit checkout and still exposes waitlist/detail.
- No payment/provider/inventory semantics change.

### Verification

```bash
cd src
php artisan test tests/Feature/StorefrontProductFamilyDiscoveryTest.php
php artisan test tests/Feature/StorefrontProductCheckoutTest.php
```

### Non-goals

No route/resolver implementation, Product detail redesign, checkout service, or new Product option entity.

## T5 — Product detail hierarchy and mobile purchase bar

### Goal

Make Product detail decision-ready and keep its primary purchase action reachable on mobile.

### Primary files

- `src/resources/views/storefront/product.blade.php`
- `src/resources/views/storefront/components/product-cover.blade.php`
- Storefront-scoped CSS/JS hooks
- `src/tests/Feature/StorefrontProductPresentationTest.php`
- focused browser Product assertions

### Implementation steps

1. Put Product identity, image, price, availability, and primary action before long secondary details.
2. Replace hard neo surfaces with the T2 soft tokens without changing presenter fields.
3. Add a mobile sticky purchase bar that submits/links to the existing canonical direct checkout URL and has a secondary Cart action where appropriate.
4. Reserve bottom padding equal to bar height plus safe-area inset. Verify no content or waitlist form is covered.
5. Preserve unavailable/waitlist behavior and no-JavaScript in-flow action.
6. Keep fallback image path and escaped alt text.

### Acceptance

- Available Product has one dominant purchase action visible in the first useful mobile scroll.
- Sticky bar is 44px-target compliant, keyboard usable, reduced-motion safe, and non-overlapping.
- Unavailable Product cannot purchase and can join waitlist.
- Product HTML contains only safe presentation data.

### Verification

```bash
cd src
php artisan test tests/Feature/StorefrontProductPresentationTest.php
php artisan test tests/Feature/StorefrontProductCheckoutTest.php
```

### Non-goals

No Product URL schema, stock/inventory, payment, Order, or checkout service change.

## T6 — Checkout sticky summary/action follow-up

### Goal

Improve mobile reachability of the existing checkout submit action without creating a second checkout implementation.

### Primary files

- `src/resources/views/storefront/shop-checkout.blade.php`
- existing checkout presentation partials under `src/resources/views/storefront/partials/shop/`
- `src/tests/Feature/StorefrontShopCheckoutTest.php`
- focused browser checkout assertions

### Implementation steps

1. Map the sticky action to the canonical existing form and submit control; do not duplicate `product_id`, quantity, payment, contact, or consent fields.
2. Mirror current total/provider text only from the server-rendered summary.
3. Disable or keep the sticky action inert for the same conditions as the canonical form, including missing required consent and maintenance lock.
4. Keep an in-flow button for JavaScript-disabled users.
5. Preserve validation focus, error summaries, disclosures, payment labels, and recovery behavior.
6. Add bottom padding and viewport evidence to prevent overlap.

### Acceptance

- One submit path creates at most one checkout intent.
- Sticky and in-flow actions share state and labels.
- Terms/errors/payment controls are never covered.
- Existing direct/Cart checkout and provider tests remain unchanged in meaning.

### Verification

```bash
cd src
php artisan test tests/Feature/StorefrontShopCheckoutTest.php
php artisan test tests/Feature/StorefrontShopCartTest.php
```

### Non-goals

No OrderCheckoutService, validation rule, provider adapter, transaction, invoice, or Recovery Key change.

## T7 — Slug repair, alias data, and image fallback delivery

### Goal

Repair the observed production data/asset defects required for a credible URL migration.

### Primary files

- migration/model/service selected by T1 alias contract
- `src/app/Services`/`src/app/Console/Commands` operation selected for the idempotent data audit
- `src/public/assets/product-placeholder.svg`
- deploy/build verification and focused tests

### Implementation steps

1. Use a data snapshot or production-like fixture to identify family name/slug mismatches by normalized name, not unknown IDs.
2. Apply the explicit Netflix/Gemini mapping only after inspecting the actual current Gemini record.
3. Write the old slug alias and new current slug atomically; make rerun a no-op.
4. Verify the family slug management path keeps URLs stable when only a name changes.
5. Ensure `product-placeholder.svg` is included in the deploy artifact at `/assets/product-placeholder.svg`.
6. Add a smoke/feature assertion that a no-image public Product renders a successful fallback URL.
7. Do not add ENV, hardcode credentials, or clean unrelated production catalog data in this ticket.

### Acceptance

- Netflix resolves at `/pilihan/netflix`.
- The observed old URL resolves through a durable alias/301, not a hardcoded view branch.
- Gemini’s resulting slug is based on actual data and does not collide.
- Repair is audited/idempotent and safe on a second run.
- Placeholder asset returns HTTP 200 from the built artifact.

### Verification

```bash
cd src
php artisan test tests/Feature/StorefrontProductFamilyDiscoveryTest.php
php artisan test tests/Feature/ProductionReleaseHardeningTest.php
npm run build
```

Production mutation/deploy execution is outside this branch planning pass; attach the audit result to the integration issue before release.

### Non-goals

No theme work, checkout/payment changes, inventory behavior change, or unrelated data cleanup.

## T8 — Responsive/SEO/release evidence

### Goal

Prove the complete contract on the real rendered Storefront and update the implementation map.

### Primary files

- `src/tests/Browser/**`
- relevant `src/tests/Feature/**`
- `docs/agents/codebase.md`
- issue evidence/comments

### Implementation steps

1. Update browser paths from `/produk-keluarga/` to `/pilihan/` while retaining explicit legacy-301 checks.
2. Exercise 320×568, 390×844, 768×900, 1280×900, 1440×900, and 1920×1080.
3. Exercise keyboard navigation, Escape menu close, 200% zoom, reduced motion, no-JavaScript navigation/checkout, and safe return URL cases.
4. Assert canonical metadata/sitemap/JSON-LD and absence of old canonical links/public internal labels.
5. Run a real available Product → checkout review smoke path and a no-image Product fallback path.
6. Run focused Storefront feature tests, build, and the broader relevant Storefront suite once.
7. Update `docs/agents/codebase.md` only for the final public route/presentation map.

### Acceptance

- All viewport and interaction evidence passes.
- URL, SEO, slug alias, fallback asset, and no-fake-popularity assertions pass.
- Existing checkout/invoice/access behavior remains proven.
- Documentation and issue links point to the final contracts.
- Branch remains `feat/storefront-marketplace-migration` or a descendant; no implementation landed on `main`.

### Final verification

```bash
cd src
php artisan test tests/Feature/StorefrontLandingPageTest.php
php artisan test tests/Feature/StorefrontProductFamilyDiscoveryTest.php
php artisan test tests/Feature/StorefrontProductPresentationTest.php
php artisan test tests/Feature/StorefrontProductCheckoutTest.php
php artisan test tests/Feature/StorefrontShopCatalogTest.php
php artisan test tests/Feature/StorefrontShopCartTest.php
php artisan test tests/Feature/StorefrontShopCheckoutTest.php
php artisan test tests/Feature/StorefrontSameOriginReturnUrlTest.php
php artisan test tests/Feature/StorefrontSeoTest.php
npm run build
npm run test:browser:customer
```

## Release checklist

- [ ] T1 route/alias contract merged before data/link cutover.
- [ ] T7 data audit confirms current Netflix/Gemini mapping.
- [ ] `/assets/product-placeholder.svg` is present in the deploy artifact.
- [ ] T2 soft theme does not alter Admin.
- [ ] T3 catalog-first fallback works with zero popularity data.
- [ ] T4 public family copy contains no internal family/SKU labels.
- [ ] T5 Product sticky action and T6 checkout action do not overlap content.
- [ ] T8 browser/feature/build/SEO evidence attached.
- [ ] Deployment is dispatched only from an approved green `main` commit through the repository’s GitHub Actions process, after branch work is merged; this planning branch itself does not deploy.
