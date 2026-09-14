# Soft Marketplace Storefront dan Public URL Migration

## Status

- **Status:** Approved for implementation planning.
- **Planning date:** 2026-09-14.
- **Implementation branch:** `feat/storefront-marketplace-migration`.
- **Baseline:** [#436 — Web Storefront Purchase Flow Simplification](https://github.com/Alhiefikri/telegram-bot/issues/436) and its completed tickets #437–#441.
- **This document is a follow-on specification.** It does not compete with or reopen the page-based checkout decision in the baseline specification.
- **Planning-pass output:** this large specification, its implementation plan, and a ready-for-agent GitHub ticket set. No product-code implementation is part of this pass.
- **Parent issue:** [#477 — Soft marketplace storefront and public URL migration](https://github.com/Alhiefikri/telegram-bot/issues/477).
- **Ready-for-agent issues:** #479 (T1), #485 (T2), #482 (T3), #478 (T4), #480 (T5), #484 (T6), #483 (T7), and #481 (T8).

The target is a calmer, marketplace-inspired presentation for a small catalog, plus a professional public URL contract. The marketplace reference supplies information hierarchy and visual cues only. Lhie Store keeps its family-led discovery model, guest checkout, server-authoritative stock, and existing payment boundaries.

## 1. Executive decision

Adopt a **soft marketplace presentation** without turning the Storefront into a multi-seller marketplace:

1. Make discovery catalog-first after a compact hero.
2. Use a search-forward header, category filters, product/family cards, and short supporting sections.
3. Use a light, spacious visual system: white surfaces, pale blue background, slate text, blue primary actions, yellow as an accent.
4. Keep Product Family-led discovery because several products are compatible options of one customer-facing offering.
5. Show observed sales signals only. Never manufacture rating, seller, discount, popularity, or scarcity data.
6. Keep `/produk/{product}` as the Product-specific detail URL.
7. Replace the awkward public family path with the canonical URL **`/pilihan/{slug}`**.
8. Redirect the legacy `/produk-keluarga/{slug}` path to the new canonical path with HTTP 301 and preserve safe same-origin return context only when needed.
9. Keep internal PHP/model terminology and route names stable unless implementation needs a separate, explicitly reviewed refactor. Public copy must not expose `Product Family`, `Produk Keluarga`, or `SKU` as the discovery label.
10. Keep the baseline page-based checkout and all payment/inventory/order semantics unchanged.

Examples:

```text
Correct: /pilihan/netflix
Correct: /pilihan/gemini
Product-specific detail: /produk/123
Legacy: /produk-keluarga/gemini  →  301  →  /pilihan/netflix (after data repair)
```

The last example is intentional: the current production audit found a visible NETFLIX card pointing to a `gemini` family slug. URL migration and data repair must be delivered together; merely changing the prefix would leave a semantically wrong URL.

## 2. Evidence and problem statement

### 2.1 Reference pattern

The public `marketku.id` audit was used as a reference for hierarchy, not as an implementation dependency. Useful patterns:

- search is easy to find from the header;
- category chips reduce browsing cost;
- promotional/featured content is presented as a short rail instead of a full editorial page;
- cards expose image, name, price, availability/sales context, and a clear action;
- mobile purchase actions remain reachable without forcing a user to find the top of the page again.

Patterns deliberately rejected for Lhie Store:

- seller metadata, because Lhie Store has one seller;
- ratings or review counts, because no such verified projection exists;
- fake discount/percentage badges;
- dense multi-seller navigation;
- a “trending” section that claims popularity when completed paid sales are empty.

### 2.2 Current Storefront audit

The production and staging Storefront audit found:

- the public catalog is small: approximately twelve active catalog items, mostly organized into named families;
- many families have one public option, while others have two or three options;
- `StorefrontCatalogPageBuilder` already computes popularity from observed paid completed sales and can legitimately return an empty collection;
- production displays an empty popular state instead of a useful available-catalog fallback;
- the mobile homepage gives most of the first viewport to the hero, so the catalog starts too late;
- Product detail has a clear purchase action but no persistent mobile purchase action;
- the family page exposes the internal-looking `Product Family` and `SKU` language;
- production has a known semantic slug mismatch: a NETFLIX card links to `/produk-keluarga/gemini`, while the resolved page title and options are Netflix;
- the configured placeholder is `/assets/product-placeholder.svg`, but the audited deployed surface returned a missing asset for at least one no-image Product.

The existing code already owns the right seams:

- `CatalogBrowse` and `StorefrontCatalogPageBuilder` own credential-free public discovery;
- `StorefrontFamilyPageBuilder` owns family option presentation;
- `StorefrontCatalogProductPresenter` owns safe Product display data;
- `StorefrontUrl` owns product, family, checkout, return, and same-origin URL construction;
- `StorefrontSeo` and `StorefrontSitemap` own public metadata and indexable URLs;
- `OrderCheckoutService`, payment adapters, inventory, signed invoice, and Recovery Key flows remain downstream and unchanged.

### 2.3 Problem

The current presentation is technically functional but not proportionate to the catalog:

1. A small catalog is presented with a heavy neo-brutalist shell and a long hero before the buying task.
2. A customer cannot quickly distinguish available choices from supporting content.
3. Empty popularity does not redirect attention to products that can actually be bought.
4. Family terminology leaks internal implementation language into public discovery.
5. The public family URL contains an awkward internal-sounding segment and currently permits a wrong slug to become canonical.
6. Product detail and checkout purchase actions require unnecessary scrolling on mobile.
7. A missing fallback asset makes a legitimate no-image Product appear broken.

## 3. Scope boundary

### 3.1 Goals

- Establish `/pilihan/{slug}` as the canonical public discovery URL for a Product Family.
- Preserve old family links through safe 301 redirects and durable slug aliases where a slug is corrected.
- Remove public `Product Family`, `Produk Keluarga`, `Pilih SKU`, and similar internal labels from customer-facing Storefront HTML.
- Keep `/produk/{product}` as the Product-specific detail contract.
- Reduce the first mobile viewport cost of the hero and expose the catalog sooner.
- Make available Products and family options the primary visual task.
- Use an honest fallback when paid-sales popularity is empty.
- Introduce a soft Storefront visual language without changing Admin visuals.
- Make Product detail purchase actions reachable on mobile without overlapping content.
- Polish the existing page-based checkout CTA presentation without changing checkout ownership or payment semantics.
- Repair the observed production family slug mismatch and verify product image fallback delivery.
- Preserve accessibility, no-JavaScript operation, reduced-motion behavior, same-origin return validation, and existing funnel events.

### 3.2 Non-goals

- No new marketplace seller, review, rating, follower, or commission domain.
- No change to the meaning of `Product`, `Product Family`, Product option, Product Category, Cart, Order, Payment, inventory, credentials, or fulfillment.
- No family-level stock, price, credentials, or checkout state.
- No Product slug migration. Product detail remains `/produk/{product}` because Product ID is the existing identity submitted to Cart, checkout, order, inventory, waitlist, and delivery flows.
- No payment provider change, automatic payment fallback, provider readiness call from catalog, QRIS change, Binance/BSC change, or invoice/recovery change.
- No change to the completed modal retirement and page-based checkout contract in #436–#441.
- No SPA router, new frontend framework, client-side Cart store, or large design-system rewrite.
- No arbitrary CMS or campaign editor in this scope. A future campaign system requires its own data model and Admin contract.
- No new environment variable for icons, labels, prices, stock, hero content, or presentation behavior. Catalog/presentation data belongs in the database/Admin surface under the repository configuration policy.
- No hardcoded production primary-key assumptions in a migration.
- No public redirect that guesses a missing family or accepts an external return URL.
- No fake popularity, discount, urgency, stock, rating, or social-proof claims.

## 4. Public terminology and URL contract

### 4.1 Public language

`Product Family` remains the internal domain term in PHP, database, tests, and codebase documentation. It must not be the customer-facing eyebrow or heading.

Use:

| Internal concept | Public language |
| --- | --- |
| Product Family page | `Pilihan {nama}` or simply `{nama}` |
| Product option | `Pilihan`, `Opsi`, or a specific package/access label from the Product presentation |
| option count | `1 pilihan`, `2 pilihan`, and so on |
| available option | `Tersedia` / `Stok habis` |
| Product ID/SKU | Do not show it as a sales label; retain it only where an operational support reference is genuinely needed |
| family return action | `Kembali ke pilihan` |

Do not render these public labels:

- `Product Family`;
- `Produk Keluarga`;
- `Pilih SKU`;
- `SKU #123` as the primary customer-facing option identity;
- `Variant` when it implies a new sellable domain entity.

Product names, option labels, prices, stock labels, access duration, region, included items, limitations, warranty, and support policy continue to come from the existing safe presentation projection.

### 4.2 Canonical paths

| Use | Canonical path | Decision |
| --- | --- | --- |
| Home | `/` | unchanged |
| Full catalog | `/shop` | unchanged |
| Family choices | `/pilihan/{slug}` | new canonical public path |
| Product detail | `/produk/{product}` | unchanged |
| Direct Product checkout review | `/shop/checkout/products/{product}` | unchanged |
| Cart checkout review | `/shop/checkout` | unchanged |

The internal route name `storefront.product-families.show` may remain to avoid an unnecessary symbol-wide rename. Route names are not public vocabulary. If an implementation changes the route name, it must first use LSP references and migrate every caller in one cutover.

### 4.3 Legacy redirect behavior

`GET /produk-keluarga/{slug}` is a legacy public route, not a second canonical page.

Required behavior:

1. Resolve the current public family by slug.
2. If the current slug no longer exists, resolve a durable old-slug alias.
3. Verify the target is still public-visible before redirecting.
4. Redirect with HTTP 301 to `StorefrontUrl::familyUrl($currentSlug, $safeReturnUrl)`.
5. Preserve only a bounded, same-origin `from` return URL. Drop all other query parameters from the redirect.
6. If no current family or valid alias exists, return 404; never redirect to a guessed family or the homepage.
7. The canonical `/pilihan/{slug}` page returns 200 and self-canonical metadata.
8. No canonical page links back to `/produk-keluarga/`.
9. Redirect resolution must not create loops when an alias equals neither the current slug nor another alias target.

A durable alias table is justified here because this is a public URL cutover and the known Netflix/Gemini correction would otherwise break copied links and indexed URLs. The minimum shape is:

```text
product_family_slug_redirects
- id
- old_slug (unique, normalized lowercase slug)
- product_family_id (foreign key to product_families)
- created_at
```

The alias is historical URL state, not a second Product Family identity. The resolver must check public visibility of the target on every request. A hard-deleted target must not produce a redirect to a private/deleted page.

### 4.4 Slug lifecycle and repair

Current `ProductFamilyManagementService` creates a unique slug on creation and does not regenerate it when the family name is edited. Preserve that stability rule.

Add an explicit slug-repair/rename path with these invariants:

- changing a display name does not silently change a public URL;
- changing a slug is an explicit operation, audited with old and new values;
- the old slug alias and new current slug are written atomically;
- current slugs and old aliases cannot collide;
- the operation is idempotent;
- no migration relies on a production primary key that is not part of the contract.

Before release, run an idempotent data audit keyed by current slug and normalized family name. The known observed repair must result in:

```text
Netflix family current slug: netflix
Old slug alias: gemini → Netflix family
Netflix public URL: /pilihan/netflix
```

The audit must inspect the actual Gemini family before assigning any alias. If `gemini` is currently used by a different public family, the repair must first establish a unique, human-readable Gemini slug and then register aliases according to the actual before/after mapping. Do not guess from numeric IDs.

## 5. Information architecture

### 5.1 Homepage `/`

The homepage is a short discovery surface, not a full editorial landing page. The order is:

1. **Header:** logo, primary search entry, Cart, account/order access, and a compact mobile menu.
2. **Compact promise/featured rail:** one concise brand promise plus up to two database-backed featured family/Product cards. No provider calls and no invented promotions.
3. **Search and category controls:** the existing bounded search contract and public category filters. On mobile, controls stack without page-level horizontal overflow.
4. **`Tersedia sekarang`:** the primary catalog section. It contains available family entries and standalone Products using the existing public projection.
5. **`Paling banyak dibeli`:** render only when the existing observed paid-sales collection is non-empty. The section is hidden, not replaced by an empty sales claim.
6. **Trust/activity:** render verified activity only when the existing verified projection has data. Empty activity is omitted or replaced by neutral support copy, never fabricated.
7. **How to buy / FAQ / support:** one compact supporting group after the primary catalog task.
8. **Footer:** existing policy, guide, and order-access links.

At the audited small-catalog scale, the first available catalog heading must begin within the first mobile reading budget. Supporting sections must not precede the primary catalog.

### 5.2 Full catalog `/shop`

`/shop` remains the canonical searchable catalog:

- preserves `q`, category, sort, and bounded pagination contracts;
- uses the same soft cards and availability hierarchy as the homepage;
- does not include checkout/payment payloads or provider readiness data;
- keeps unavailable Products visible with a clear waitlist/detail action;
- shows an honest empty state for no products or no matching search/category;
- retains canonical query and noindex behavior already owned by `StorefrontSeo`.

Homepage and `/shop` may share partials, but `/shop` remains the complete discovery surface and the canonical destination for search.

### 5.3 Featured rail data rule

The featured rail is not a campaign CMS. It uses existing public database-backed Product/Family presentation data:

- only public-visible entries;
- deterministic ordering from existing family/product ordering and availability;
- actual image, name, price, and stock presentation;
- CTA generated by `StorefrontUrl`;
- no “sale”, “limited”, “trending”, or “best” wording unless backed by the corresponding projection;
- fewer entries render fewer slides; no empty placeholder slide;
- if there are no eligible entries, render the compact promise card only.

If marketing later requires arbitrary slide copy, dates, artwork, or targeting, that is a separate Admin-managed data-model specification. It must not be added as ENV or hidden config.

## 6. Visual and responsive contract

### 6.1 Soft storefront theme

Scope all visual changes to Storefront selectors and existing `sf-*` tokens. Admin `neo-*` tokens and Admin layouts remain unchanged.

Target visual properties:

| Token role | Target |
| --- | --- |
| page paper | very light blue/neutral, approximately `#f5f8fc` |
| card surface | white, approximately `#ffffff` |
| soft surface | pale blue, approximately `#eef5ff` |
| ink | dark slate/navy, approximately `#12233f` |
| muted text | readable slate, approximately `#66758c` |
| border | light blue-gray, approximately `#d8e2ef` |
| primary action | accessible blue, approximately `#2563eb` |
| primary hover | darker blue, approximately `#1d4ed8` |
| accent | warm yellow used sparingly, approximately `#f6c945` |
| shadow | subtle diffuse shadow; no black offset shadow on normal Storefront cards |

Implementation may tune values to meet contrast and existing brand constraints, but must preserve the roles above. Do not introduce a second parallel token namespace.

Required geometry:

- 1px borders for normal cards and controls;
- 12–16px card radius, with one consistent scale;
- no hard neo-brutalist offset shadow on the new Storefront primary surfaces;
- body text at least 14px; metadata at least 12px;
- interactive controls at least 44×44 CSS pixels;
- predictable content width with 16–24px mobile gutters;
- no page-level horizontal scroll at 320px, 390px, 768px, 1280px, 1440px, or 1920px widths;
- image containers reserve aspect ratio to avoid layout shift;
- `prefers-reduced-motion: reduce` disables auto-advance and decorative movement.

### 6.2 Header

The header keeps the existing server-rendered navigation and mobile menu behavior, with these presentation changes:

- search is prominent and submits to `/shop` using the existing `q` parameter;
- Cart remains visible on desktop and has a reachable mobile entry without requiring the hamburger;
- account/order access stays available but does not crowd the primary search action;
- the logo remains a link to `/`;
- mobile menu controls retain focus management and Escape behavior from `storefront.js`;
- no client-side Cart state is introduced;
- header actions remain usable with JavaScript disabled.

### 6.3 Compact promise/featured rail

- The brand promise is concise and has one dominant `Lihat produk` action.
- The rail may use CSS scroll snap and optional progressive enhancement; the page remains usable as a vertical stack without JavaScript.
- Any automatic advance must pause on hover/focus, stop under reduced motion, and expose previous/next controls with accessible names.
- A featured card must link to a family or Product route, never directly to a payment provider.
- The rail must not occupy the full mobile first viewport. It is supporting discovery, not the catalog replacement.

## 7. Surface-specific behavior

### 7.1 Family choices page `/pilihan/{slug}`

The page remains built from `StorefrontFamilyPageBuilder` and the credential-free `CatalogBrowse` projection.

Required presentation:

- heading uses the family name; public eyebrow is omitted or says `PILIHAN`;
- summary exposes option count, available count, and minimum price without internal domain language;
- each option shows its customer-facing option label, price, availability, stock label, and relevant merchandising facts;
- no `Product Family`, `Produk Keluarga`, `Pilih SKU`, or `SKU #...` label;
- available option: dominant `Beli sekarang`/`Beli`, secondary `Tambah ke keranjang`, and a detail link;
- unavailable option: visible `Stok habis`, detail/waitlist action, and no checkout submit;
- details use progressive disclosure when long; Product identity and action remain visible;
- existing `from` return context remains bounded by `StorefrontUrl`;
- one-option families must not force an unnecessary modal or duplicate decision surface. The option can link directly to the existing direct checkout review while retaining Product detail access;
- all option IDs submitted to Cart/checkout remain existing Product IDs.

### 7.2 Product detail `/produk/{product}`

- Use the safe `StorefrontProductPresentation` projection and the existing cover/fallback path.
- Show Product name, option label when present, price, current availability, and a concise purchase-relevant detail summary before secondary content.
- Keep `Beli sekarang` as the dominant action and `Tambah ke keranjang` secondary.
- On mobile, provide a sticky bottom purchase bar for an available Product. It must include safe-area padding and reserve equivalent bottom content space so it never covers details, waitlist, or form controls.
- On desktop, prefer an in-flow action panel; do not create a second nested scroll container.
- Unavailable Products remain public and show waitlist/detail behavior without an enabled checkout action.
- No credentials, inventory item content, payment-provider payload, or private order data enters the HTML.

### 7.3 Checkout review

This is a presentation follow-up to #438, not a checkout redesign.

- Keep the existing page-based direct and Cart review routes.
- Keep the existing `POST /shop/checkout`, validation, consent, maintenance, payment selection, invoice, provider, and recovery ownership.
- On mobile, a sticky summary/action may repeat only the existing submit action and current total; it must submit the canonical form, not duplicate fields or create a second transaction path.
- The sticky action is disabled when the canonical form is not submittable, including missing required consent or maintenance lock, and the in-flow fallback remains present for no-JavaScript users.
- `Buat invoice & bayar` or the existing provider-specific equivalent remains the only financial submit intent.
- Optional email/contact and advanced payment details remain progressive disclosures from the baseline.
- Error focus and `aria-live` behavior must remain intact.
- The bottom action must not cover terms, error messages, payment choices, or the final order summary.

## 8. Data, merchandising, and cache invariants

### 8.1 Honest signals

- `popularProducts` remains based only on observed paid completed sales through the existing builder contract.
- If it is empty, show `Tersedia sekarang` or the normal catalog; do not show an empty “terlaris” block as the primary task.
- If it is non-empty, label the section according to the existing verified signal; do not call it `Trending` unless the underlying contract is explicitly renamed and documented.
- Prices, stock, availability, access duration, region, included items, limitations, and support policies come from DB-backed Product/Product Family presentation fields and existing presenters.
- No sale percentage, crossed-out price, seller rating, sold count, or urgency badge may be invented by the view.
- An available Product can become unavailable between render and submit. Checkout remains server-authoritative and must show its existing stale-stock/validation outcome.

### 8.2 Admin and configuration policy

- Existing legacy `config/storefront.php` values may remain until an intentional content migration; this project does not add new ENV presentation settings.
- New catalog-facing values must not be introduced as ENV, deployment knobs, or build-time constants.
- Product icons continue to use the optional Product `icon` field and Admin Dashboard forms; never add `TG_KEYBOARD_ICON_*`, `TG_ICON_*`, or equivalent settings.
- Any future editable hero/promotion content requires a database field/table and Admin Dashboard ownership, in a separate scoped ticket if it exceeds existing Product/Family data.

### 8.3 Response caching

`StorefrontResponseCache` currently caches the no-search homepage response with a bounded TTL and includes a design version in its cache key.

- Update the Storefront design version when markup/token changes make old cached HTML incompatible.
- Preserve existing cache invalidation hooks for Product, Family, merchandising, and stock changes.
- Do not put provider readiness or sensitive data into cached catalog HTML.
- Catalog stock labels may be a bounded presentation snapshot; checkout always revalidates stock and price through existing transaction ownership.
- The placeholder asset and built CSS/JS must be part of the deploy artifact, not assumed to exist only in source.

## 9. URL, SEO, and analytics implementation boundaries

### 9.1 URL ownership

`StorefrontUrl` remains the only shared builder for public Storefront links.

The URL ticket must update every public family URL caller, including:

- `src/routes/web.php`;
- `StorefrontUrl::familyUrl()`;
- `checkoutPageSource()` and `checkoutReturnLabel()` path recognition;
- `StorefrontFamilyPageBuilder` and catalog entry presentation;
- family controller/redirect resolution;
- breadcrumbs and page metadata;
- sitemap family locations;
- browser tests and same-origin return URL tests;
- any generated `from` links.

Do not manually concatenate `/pilihan/` in views when `StorefrontUrl` can own the contract.

### 9.2 SEO

- `/pilihan/{slug}` is the only indexable family URL.
- `<link rel="canonical">`, Open Graph URL, JSON-LD `CollectionPage.url`, breadcrumb URLs, and sitemap `<loc>` use `/pilihan/{slug}`.
- Legacy `/produk-keluarga/{slug}` responses do not emit a second indexable page; they 301.
- Search/query pages retain current canonical/noindex behavior.
- Product JSON-LD remains Product-specific and points to `/produk/{id}`.
- Missing/invalid family aliases return 404 rather than a soft redirect.

### 9.3 Funnel events

Keep existing event names and ownership:

- `storefront.family_opened`;
- `storefront.product_opened`;
- `storefront.checkout_opened`;
- `storefront.checkout_submitted`;
- payment and post-purchase events already defined by `StorefrontFunnelEvents`.

Route migration must not put full URLs, email, contact, payment details, credentials, or return-query content into audit payloads. If a new presentation event is necessary, whitelist a route/surface name and Product/Family ID only, and update the funnel report contract in the same ticket.

## 10. Backend and module boundaries

### May change

- Storefront Blade partials/components and scoped `sf` CSS;
- `StorefrontCatalogPageBuilder` presentation composition and honest fallback selection;
- `StorefrontFamilyPageBuilder` view data only when needed for the new public copy;
- `StorefrontUrl` and a narrowly scoped legacy family URL resolver;
- `StorefrontSeo`, breadcrumbs, sitemap, and route/controller wiring;
- a durable family slug redirect table/service required for public URL compatibility;
- idempotent data repair/audit for observed production slugs;
- deploy asset verification for `product-placeholder.svg`;
- focused Storefront feature/browser tests and codebase guide references.

### Must not change

- `CatalogBrowse` domain meaning or credential-free boundary;
- Product ID identity, Product stock/inventory semantics, waitlist semantics;
- `OrderCheckoutService`, payment assembly, payment provider adapters, invoice signing, Recovery Key, or credential delivery;
- Cart session/quantity/checkout transaction ownership;
- Telegram Store Bot flows;
- Admin `neo-*` presentation unless a separate Admin ticket is created;
- ENV secrets or presentation configuration policy.

If implementation discovers that the alias resolver requires changing an invariant above, stop and open a separate decision issue rather than widening a child ticket.

## 11. Accessibility, security, and performance

### Accessibility

- All links and buttons have visible, specific labels.
- Focus-visible rings remain visible against light surfaces.
- Header menu, rail controls, disclosures, sticky bars, and forms work with keyboard only.
- Touch targets are at least 44×44px.
- Sticky actions expose their relationship to the canonical form through accessible names and do not duplicate form semantics.
- Reduced-motion users do not receive auto-advancing or decorative motion.
- Zoom to 200% retains readable content and reachable actions.
- `lang`, headings, landmarks, and `aria-live` error semantics remain valid.

### Security

- Legacy redirects accept only a route slug and a bounded same-origin `from` context.
- No open redirect, host-header-derived target, or unescaped user query is introduced.
- Escaped Product/Family copy remains escaped in Blade.
- Catalog responses contain no credentials, inventory item contents, Recovery Keys, payment secrets, provider tokens, or private order data.
- Slug alias lookup must verify public visibility and never reveal soft-deleted/private Family data.

### Performance

- No payment-provider or inventory credential call runs during catalog/family rendering.
- Images use reserved dimensions, responsive loading, and the deployed fallback asset.
- The initial hero/rail does not preload a large image set; only the first visible content is eager where appropriate.
- Homepage cache identity includes the design version and relevant existing public configuration.
- No client-side hydration/framework bundle is added for a visual-only rail.

## 12. Acceptance matrix

The integration ticket must verify the actual Storefront surface at all widths below:

| Viewport | Required checks |
| --- | --- |
| 320×568 | no horizontal page overflow; header/menu/search reachable; first catalog task starts within the first reading budget; Product sticky action does not cover content |
| 390×844 | hero is compact; first catalog heading begins by approximately the first 720px; Product sticky action and safe-area spacing work; checkout submit is reachable within two viewports |
| 768×900 | tablet grid does not create clipped cards or competing CTAs; family options remain readable |
| 1280×900 | desktop header/search/navigation align; cards use soft hierarchy; checkout remains document-scrolled |
| 1440×900 | no excessive empty hero, no layout shift, family/Product links use canonical paths |
| 1920×1080 | content width remains bounded; no stretched card/image or accidental full-width text measure |

Required functional assertions:

1. `/pilihan/{current-slug}` returns 200 with a self-canonical URL.
2. `/produk-keluarga/{current-slug}` returns 301 to `/pilihan/{current-slug}`.
3. A corrected old slug returns 301 to the corrected current slug through the alias table.
4. Invalid/missing target returns 404, not homepage 200.
5. Unsafe external `from` is not reflected in redirects, breadcrumbs, checkout actions, or audit values.
6. Internal links, sitemap, JSON-LD, Open Graph, and breadcrumbs contain no canonical `/produk-keluarga/` path.
7. Public HTML contains no `Product Family`, `Produk Keluarga`, `Pilih SKU`, or `SKU #` discovery labels.
8. A one-option Family reaches the existing direct checkout review without a modal.
9. An available Product reaches the existing checkout review; unavailable Product cannot submit checkout and retains waitlist/detail behavior.
10. Empty paid-sales popularity renders the honest available catalog without a fake popularity claim.
11. Existing Cart checkout, direct checkout, payment selection, consent, invoice, signed access, and Recovery Key tests remain green.
12. `GET /assets/product-placeholder.svg` returns 200 in the built/deployed artifact and a no-image Product renders a non-broken fallback.
13. JavaScript-disabled navigation and checkout still work through server-rendered links/forms.
14. Existing funnel event names and redaction boundaries remain intact.

## 13. Rollout and cutover

The branch rule is strict: all implementation commits are based on `feat/storefront-marketplace-migration`; do not implement, commit, merge, or deploy from `main` during this work.

Recommended sequence:

1. Merge the URL foundation first so the new path works and the old path redirects before links/data change.
2. Add the alias table/resolver and run the slug audit/repair transaction.
3. Verify `/pilihan/netflix` and the corrected Gemini path on a production-like data snapshot.
4. Ship scoped soft theme/header/featured rail.
5. Ship catalog fallback and family/Product presentation changes.
6. Ship checkout sticky presentation only after the baseline checkout contract remains green.
7. Run the full responsive/browser/SEO/asset evidence matrix.
8. Release with the legacy route retained as a 301 compatibility boundary.

Rollback requirements:

- reverting visual commits must not remove the legacy redirect or alias data;
- alias rows are harmless when the new visual shell is reverted;
- no payment/inventory rollback or data rewrite is permitted as part of a visual rollback;
- if a slug repair is wrong, stop traffic to the affected canonical link and correct the explicit alias/data mapping through the audited repair path, not by hand-editing production URLs.

## 14. Ready-for-agent ticket decomposition

The GitHub child issues created from this specification must preserve these ownership boundaries. Each child is independently understandable, has `ready-for-agent`, and must avoid formatter/linter/full-suite work; the integration ticket owns the final broad checks.

### T1 — Canonical `/pilihan` URL contract and legacy redirects

**Primary ownership:** routes, URL service, resolver, SEO URL generation, URL tests.

**Files/symbols:** `src/routes/web.php`, `src/app/Storefront/StorefrontUrl.php`, family controller/resolver, `StorefrontSeo`, `StorefrontSitemap`, breadcrumbs, same-origin URL and family discovery tests.

**Acceptance:** current `/pilihan/{slug}` 200; old path 301; durable old-slug alias; safe `from`; invalid target 404; sitemap/metadata/breadcrumbs canonical; all callers migrated; no open redirect.

**Non-goals:** visual redesign, Product slug, checkout/payment changes, production primary-key assumptions.

### T2 — Soft Storefront visual tokens, header, and compact featured rail

**Primary ownership:** Storefront `sf` tokens, header, mobile navigation presentation, compact promise/featured rail.

**Files/symbols:** `src/resources/css/app.css`, `src/resources/views/storefront/partials/header.blade.php`, `hero.blade.php`, `src/resources/js/storefront.js` only for progressive rail/menu behavior.

**Acceptance:** soft theme scoped to Storefront; search-forward header; mobile Cart access; compact rail; no JS fallback; reduced motion; no page overflow; Admin neo theme unchanged.

**Non-goals:** catalog card/family/Product detail/checkout markup, DB schema, payment code.

### T3 — Catalog-first sparse merchandising and honest fallback

**Primary ownership:** homepage/shop information hierarchy, category/search placement, available fallback, popularity rendering.

**Files/symbols:** `StorefrontCatalogPageBuilder`, `storefront.blade.php`, `partials/catalog.blade.php`, `partials/shop/catalog.blade.php`, catalog/family card components as needed for listing only.

**Acceptance:** available catalog is primary; real popularity renders only when non-empty; empty popularity does not dominate; search/category/pagination remain; no fake badges; mobile first reading budget passes; public links consume `StorefrontUrl`.

**Non-goals:** family detail copy, Product detail sticky bar, checkout, URL resolver internals.

### T4 — Family choices and option decision surface

**Primary ownership:** public family copy and option cards on `/pilihan/{slug}`.

**Files/symbols:** `storefront/family.blade.php`, `components/family-card.blade.php`, family presentation data only where necessary, focused `StorefrontProductFamilyDiscoveryTest` updates.

**Acceptance:** no internal family/SKU labels; options show honest price/stock/details; direct purchase and Cart actions preserve existing Product IDs; one-option path does not introduce a modal; unavailable/waitlist behavior remains.

**Non-goals:** route resolver implementation, Product detail redesign, checkout/payment logic.

### T5 — Product detail hierarchy and mobile purchase bar

**Primary ownership:** Product detail visual hierarchy, fallback image presentation, sticky mobile purchase action.

**Files/symbols:** `storefront/product.blade.php`, `components/product-cover.blade.php`, scoped CSS/JS hooks, `StorefrontProductPresentationTest` and focused browser assertions.

**Acceptance:** price/availability/action are early; sticky bar is keyboard/touch usable, safe-area padded, non-overlapping, and server-form compatible; unavailable waitlist works; no private payload.

**Non-goals:** Product URL schema, checkout transaction, inventory or payment changes.

### T6 — Checkout sticky summary/action presentation follow-up

**Primary ownership:** mobile checkout summary/action polish after #438.

**Files/symbols:** `storefront/shop-checkout.blade.php`, checkout presentation partials, focused checkout rendering/browser assertions.

**Acceptance:** one canonical submit action; sticky action mirrors canonical form without duplicate fields; disabled/error/consent/maintenance states correct; no-JS in-flow fallback; no overlap; #437/#438 payment and recovery contracts remain unchanged.

**Non-goals:** OrderCheckoutService, provider adapters, validation rules, payment semantics.

### T7 — Family slug data repair and deployed image fallback

**Primary ownership:** alias schema/repair operation if not completed in T1, production-like data audit, asset delivery verification.

**Files/symbols:** migration/model/service/command chosen by T1 contract, `src/public/assets/product-placeholder.svg`, deploy/build verification, focused data/asset tests.

**Acceptance:** Netflix current slug is `netflix`; observed old mapping is durable and audited; Gemini mapping is verified rather than guessed; repair is idempotent and not keyed by unknown IDs; placeholder returns 200 from built artifact and renders for no-image Product; no new ENV.

**Non-goals:** visual redesign, payment, inventory semantics, unrelated catalog cleanup.

### T8 — Responsive, SEO, and release evidence cutover

**Primary ownership:** end-to-end browser matrix, focused suite coordination, stale path/source checks, codebase documentation update.

**Files/symbols:** `src/tests/Browser/**`, relevant `src/tests/Feature/**`, `docs/agents/codebase.md` only for final public route/presentation map.

**Acceptance:** viewport/keyboard/no-JS/reduced-motion/zoom matrix; canonical/301/404/alias evidence; asset evidence; checkout/invoice/access smoke path; final docs describe `/pilihan` and page-based checkout; no implementation is left on `main`.

**Non-goals:** new feature implementation or ownership changes from T1–T7.

## 15. Verification commands

Run focused checks from `src/` while implementing a child. Do not run the full suite in every child.

```bash
php artisan test tests/Feature/StorefrontProductFamilyDiscoveryTest.php
php artisan test tests/Feature/StorefrontSameOriginReturnUrlTest.php
php artisan test tests/Feature/StorefrontSeoTest.php
php artisan test tests/Feature/StorefrontLandingPageTest.php
php artisan test tests/Feature/StorefrontProductPresentationTest.php
php artisan test tests/Feature/StorefrontProductCheckoutTest.php
php artisan test tests/Feature/StorefrontShopCheckoutTest.php
npm run build
npm run test:browser:customer
```

The exact browser script may be adjusted to the current package scripts, but evidence must exercise the real rendered Storefront rather than only snapshotting Blade source. The final integration ticket runs the broader relevant Storefront suite and formatter once.

## 16. ADR and documentation impact

No domain state-machine change is intended. `CONTEXT.md` remains authoritative:

- `Product Family` stays an internal canonical concept and is not renamed to a new sellable entity;
- `Product option` remains an existing Product/SKU identity;
- Product Category remains distinct from family discovery;
- Product icons remain DB/Admin-backed, never ENV-backed.

The URL alias table is a durable public-address decision. If implementation adds it, record the migration rationale in the relevant URL/Storefront architecture documentation or a small ADR; do not create a broad domain ADR for a presentation rename.

Update `docs/agents/codebase.md` only after the cutover is implemented, so its public route map says `/pilihan/{slug}` and continues to document page-based checkout. Do not rewrite unrelated Telegram, payment, inventory, or credential sections.

## 17. Definition of done

This specification is complete only when:

- all eight child tickets exist with `ready-for-agent` and explicit dependency edges/task-list links;
- the parent issue links this specification and implementation plan;
- implementation is performed only on `feat/storefront-marketplace-migration` or descendants;
- `/pilihan` is canonical, old family paths are safe 301s, and the known slug mismatch is repaired;
- the Storefront is visibly softer and catalog-first at all required widths;
- sparse catalog behavior is honest and useful;
- family, Product, and checkout actions remain end-to-end functional;
- no payment/order/inventory/security invariant is weakened;
- final browser, focused feature, build, SEO, and asset evidence is attached to the integration ticket.
