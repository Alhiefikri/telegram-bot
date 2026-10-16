# DESIGN.md

## Product surface

Lhie Store is a hybrid storefront and Telegram commerce product. The public website helps customers browse AI/digital premium accounts, understand the purchase flow, and move into Telegram/payment support. The admin surfaces help operators manage products, stock, sales, broadcasts, channels, and order follow-up.

Primary UI surfaces:

- Public storefront homepage/catalog shell: `src/resources/views/storefront.blade.php`
- Storefront pages: product detail, Telegram admin checkout handoff, login guide
- Storefront modal and components: product modal, product cards, product cover, QRIS card, brand mark
- Admin login page
- Admin dashboard: ten canonical tabs, grouped sidebar/topbar, mobile drawer, and product/stock modals
- Admin order detail page

## Visual direction

The public storefront keeps a playful commerce identity: pale paper backgrounds, electric blue customer CTAs, black ink outlines, offset shadows and yellow accents. Its scoped marketplace overrides take precedence over the legacy neo-brutal primitives.

The Admin Dashboard and linked operator pages use canonical Variant A: neutral light/dark surfaces, a muted violet action accent, one-pixel separators, 12px corners and restrained elevation. Admin density serves products, orders and financial operations, not storefront decoration. Design dials: ENERGY 1 / RHYTHM 2 / MOTION 1; repeated form/card hierarchy varies only for real tables, queues and reporting.

Signed-in Customer pages use a compact account workspace with thin borders, 12px corners and no offset shadows. Design dials: ENERGY 2 / RHYTHM 2 / MOTION 1. Desktop places concise identity, teal balance and amber order-count panels at left; shopping, settings and help use the available width at right. Amber identifies commerce, teal money/success, blue navigation/support, and rose logout/error states. Each color has an explicit label and a dark-theme counterpart. Retain the existing fonts; reduce chrome and spacing rather than touch targets or readable text. Account pages own a compact header/footer with real store destinations; navigation sits above subpage content. Password fields share a row when space permits, and Google/Telegram sections sit beside each other on large desktops. Mobile stacks the same content, with no clipped lists or fixed-height forms. The shared catalog navbar still hydrates authenticated identity without embedding personal data in publicly cached HTML.

Account header links (Produk, Keranjang and Akun saya) and the Menu toko disclosure use bounded, filled button surfaces so customers can recognize them as controls before hovering. Blue marks navigation, amber retains the cart's commerce meaning, and a solid fill marks the current page or open menu. A chevron signals dropdown expansion rather than the native text-only disclosure marker. Keep native anchors/details semantics, visible keyboard focus and 44px targets; controls wrap on narrow screens rather than shrinking.

Paid invoice pages use a compact receipt sidebar beside credential delivery/support on desktop, with a teal verified-payment heading and amber credential-disclosure action. Mobile places credential delivery/support before the receipt. Compact invoice chrome/footer keeps normal collapsed content in one large desktop viewport; long receipts, recovery/warranty content and expanded credentials may scroll. The credential confirmation remains an in-page dialog, and visual density never changes invoice/Recovery Key authorization.

Avoid drifting back to older directions that no longer match the site: orange Warung AI storefront styling, Filament admin assumptions, generic SaaS dark gradients, or sterile premium minimalism.

### Marketplace storefront refinement

The marketplace storefront uses a softer blue-and-yellow treatment than the legacy neo-brutal reference below. Its current tokens and scoped overrides in `src/resources/css/app.css` take precedence over the older storefront token and primitive tables. Admin has its own Variant A tokens; do not apply storefront decoration to it.

- The homepage hero pairs a blue promise panel and yellow primary CTA with a light, live catalog preview. Never invent sales or availability claims for decoration.
- Product and family artwork is square. Fallback covers retain the configured product icon/theme; family covers use a monogram when no image exists.
- Cards prioritize product name, price, stock, and the purchase action. Compact cart buttons keep a 44px touch target and a product-specific accessible name. Sold-out products retain working detail and waitlist links.
- Desktop catalog columns expand into available space when there are fewer entries; small screens retain a compact two-column layout where space permits.
- Keep the shared header sticky without making its ancestors scroll containers: use horizontal clipping rather than hidden overflow. Anchor targets must clear the header.
- The mobile menu is a keyboard-managed dialog. Closed drawers are inert; reopening during a close animation must not hide the reopened drawer, and switching to desktop must release the mobile scroll lock.
- Motion stays incidental: short fades (280–520ms), small lifts (≤3px), and tactile presses. Cards lift and their artwork zooms slightly on hover, covers shimmer until the image loads, catalog blocks fade up once on scroll, and the sticky mobile buy bar slides in.
- Never animate reading or payment surfaces: no moving price, countdown, or copy targets, and no looping motion outside the product covers, scroll progress, and cart badge ring. Every new animation is disabled under `prefers-reduced-motion`.


## Color tokens

### Storefront tokens

Defined in `src/resources/css/app.css` under `:root`.

| Token | Value | Usage |
| --- | --- | --- |
| `--sf-paper` | `#f4f7fb` | Main page background and broad storefront shell |
| `--sf-surface` | `#fffdf8` | Cards, header surfaces, modal bodies, calm content panels |
| `--sf-surface-warm` | `#eaf3ff` | Blue-tinted support panels, steps, secondary section washes |
| `--sf-ink` | `#0b1220` | Primary text, borders, shadows, high-emphasis UI |
| `--sf-muted` | `#58606e` | Supporting copy and secondary labels |
| `--sf-border` | `#0b1220` | Neo-brutal outlines |
| `--sf-accent` | `#005cff` | Primary customer CTA, active filters, brand action states |
| `--sf-accent-deep` | `#0047c7` | Hover/pressed strong blue |
| `--sf-accent-soft` | `#dcebff` | Soft highlight fills and low-emphasis blue panels |
| `--sf-yellow` | `#ffe45c` | Chips, labels, attention badges, playful emphasis |
| `--sf-blue` | `#2d9cff` | Focus rings, secondary accents, payment/testimonial details |
| `--sf-green` | `#35d486` | Ready/in-stock accents and positive state decoration |
| `--sf-lilac` | `#bda7ff` | Decorative accent and visual variety |
| `--sf-success` | `#107a48` | Success text and semantic status copy |
| `--sf-warning` | `#9a5b00` | Warning/out-of-stock semantic copy |
| `--sf-shadow` | `#0b1220` | Offset shadows for panels, buttons, cards |
| `--sf-grid` | `rgba(11, 18, 32, 0.08)` | Low-contrast grid texture |

### Shared Tailwind theme tokens

Defined in `src/resources/css/app.css` inside `@theme`.

| Token | Value | Usage |
| --- | --- | --- |
| `--color-brand-electric` | `#0052ff` | Legacy/shared electric blue |
| `--color-brand-electric-dark` | `#003ecc` | Dark electric blue |
| `--color-brand-electric-light` | `#f0f5ff` | Light electric blue panels |
| `--color-brand-navy` | `#0b0f19` | Dark text/surface utility |
| `--color-brand-slate-dark` | `#1e293b` | Secondary dark text |
| `--color-brand-muted` | `#64748b` | Muted admin/detail copy |
| `--color-brand-border` | `#e2e8f0` | Neutral borders in simpler detail surfaces |
| `--color-brand-bg` | `#f8fafc` | Neutral background utility |

### Admin dashboard palette

Variant A admin surfaces use the current `admin-*` variables in `src/resources/css/app.css`. Legacy `--color-neo-*` aliases are not the Admin palette contract.

| Token | Light | Dark | Usage |
| --- | --- | --- | --- |
| `--admin-paper` | `#f6f7fb` | `#121722` | Page background |
| `--admin-paper-desktop` | `#f6f7fb` | `#0e131d` | Desktop frame |
| `--admin-surface` | `#ffffff` | `#1a222f` | Cards, header and modal surfaces |
| `--admin-surface-muted` | `#eef0f5` | `#232d3b` | Secondary fields and factual panels |
| `--admin-ink` | `#202637` | `#f5f7fb` | Main text |
| `--admin-muted` | `#596579` | `#aab6c8` | Supporting text |
| `--admin-line` | `#e2e8f0` | `#263344` | One-pixel separators |
| `--admin-radius` | `0.75rem` | `0.75rem` | Shared corners |

#### Admin accent roles

Each accent role has its own light/dark pair, because one violet value cannot serve as both a fill and accent-colored text. Measured pairs below are computed browser colors from issue #602.

| Token | Light | Dark | Usage |
| --- | --- | --- | --- |
| `--admin-accent` | `#6d5bd0` | `#b8a9ff` | Accent fills (product primary actions, drawer create action) and accent borders |
| `--admin-accent-ink` | `#ffffff` | `#121722` | Foreground on accent fills |
| `--admin-accent-strong` | `#5b48c2` | `#b8a9ff` | Accent-colored text and icons on paper, surface and accent-soft backgrounds (active navigation, active tabs, purple badges, accent links, active filter pills) |
| `--admin-accent-hover` | `#5b48c2` | `#9d86f8` | Hover fill for accent-filled controls |
| `--admin-accent-soft` | `#eeeaff` | `#302a4f` | Soft selection/notice background; pair with `--admin-ink` or `--admin-accent-strong`, never with white text |
| `--admin-focus` | `#6d5bd0` | `#c4b5fd` | Focus rings and outlines |

Measured text contrast: light product primary/secondary actions `5.18:1`, light active nav item `5.66:1`, dark product primary/secondary actions `8.67:1`, dark active nav item `6.47:1`, admin login and Owner password confirmation `5.18:1` light / `8.67:1` dark, linked-shell back links `6.23:1` light / `8.67:1` dark. Hover fills measure `6.67:1` light and `6.15:1` dark, so no accent state drops below AA.

## Typography

Fonts are loaded from Google Fonts in the storefront head partial.

| Role | Font | Usage |
| --- | --- | --- |
| Body/UI | `Plus Jakarta Sans` | Default body, buttons, forms, navigation, customer/admin copy |
| Display/headings | `Space Grotesk` | Headings, hero display, section titles, chips, admin filter/nav emphasis |
| Data/secret snippets | system monospace | Secrets, codes, compact admin data only |
| Testimonial quote mark | `Georgia` | Large decorative quote mark only |

Rules:

- Use `Space Grotesk` for bold display moments and operational labels that need punch.
- Use `Plus Jakarta Sans` for readable transactional copy and dense admin content.
- Do not make monospace the general badge/meta system; reserve it for data-like content.
- Keep display letter spacing readable; do not over-tighten large headings.

## Storefront component system

Core utilities live in `src/resources/css/app.css`.

| Primitive | Current behavior |
| --- | --- |
| `.storefront-shell` | Pale paper surface with radial accent dots, low-contrast grid, and fixed decorative wash |
| `.neo-panel` | 3px ink border, `0.9rem` radius, `7px 7px` ink shadow |
| `.neo-panel-tight` | 3px ink border, `0.7rem` radius, `5px 5px` ink shadow |
| `.neo-button` | 3px ink border, `0.7rem` radius, offset shadow, hover lift to `8px 8px` shadow |
| `.neo-chip` | 2px ink border, full-pill radius, yellow fill, compact offset shadow |
| `.neo-input` | 3px ink border, `0.5rem` radius, surface fill, offset shadow |
| `.neo-filter-active` | Electric blue fill, white text, offset shadow, slight lifted transform |
| `.neo-hover` | Card hover lift with slight rotation and stronger shadow |

Customer-facing patterns:

- Product cards should keep strong image/product hierarchy, visible stock status, clear price, and a dominant CTA.
- Product detail and payment pages should feel like a transactional kiosk: framed product/QR blocks, explicit next steps, clear support routes.
- Login guide pages should stay instructional and editorial: readable step sections, screenshot-led content, sticky/anchored guidance on wider screens.
- FAQ, support, and trust sections should reduce purchase anxiety, not add decorative complexity.

## Admin component system

Admin is custom Blade + Alpine, not Filament. Reusable components live under `src/resources/views/components/admin-dashboard/` and dashboard partials live under `src/resources/views/admin/dashboard/`.

Admin patterns:

- Variant A shell: grouped sidebar at >=1024px, compact topbar, and an accessible drawer below that breakpoint. There is no tab strip and no bottom navigation.
- Neutral paper/surface pairs plus one muted violet accent; semantic success/warning/danger colors keep a text label. No warm-yellow paper background or neon washes on operational surfaces.
- One-pixel `border-admin-line` separators, `--admin-radius` (12px) corners, restrained `shadow-admin-sm` elevation, regular-case labels, and medium-weight metadata.
- Filters use the shared `filter-pill` idiom: one-pixel border, `--admin-radius` corners, regular-case labels, and compact count bubbles.
- Section headers state context and hierarchy; keep the primary action per surface on the accent fill and everything else neutral.
- Modals, notices, cards, form controls, and stat blocks compose the `x-admin-dashboard.*` primitives instead of carrying local border/shadow/palette/typography overrides.
- Image uploads (`x-admin-dashboard.file-upload`) keep the native `<input type="file">` as the control so Tab, Space and Enter open the native picker with unchanged `name`/`accept`/`validation`. It is visually replaced by a label of at least 44×44px that carries the hover and focus ring, while the chosen filename (or empty state) stays visible beside it. The raw storefront-banner inputs keep the same minimum target and admin focus outline.
- Long operational values (invoice hashes, provider references, wallet addresses, SKUs, secrets) use `font-mono text-xs break-all`; every other label stays in the regular UI type scale.
- Data-dense tables and wide rows scroll inside an `overflow-x-auto` container; the page itself never overflows horizontally.

Operational surfaces in scope for this contract: Ringkasan (`stats`, including payment mode/provider readiness, BSC receivers, IDR/USDT funding policy, balance operations, checkout maintenance and Support WhatsApp), Produk (catalog, family, category, stock intake and management, storefront banners), Pesanan (orders, order detail, Manual Fulfillment Review, Manual Delivery Review), Supplier (connections, private catalog/import/mapping, product pricing policy, purchases/recovery, warranty/refund/replacement, BuyMail deposit/history), communications (Siaran, Store Channel plus fake sales proof), reporting (Riwayat KlikQRIS, Withdraw, Funnel), and the financial linked pages (Laporan saldo, Kelola saldo, Review deposit USDT dan insiden, Kompensasi top-up).

These surfaces compose the `x-admin-dashboard.*` primitives. The password-confirmation page keeps its form and redirect behavior but uses the same primitives. Implementation is not release acceptance: [the #605 parity and evidence record](docs/agents/admin-dashboard-release-parity.md) distinguishes executed checks from missing browser/device scenarios and human sign-off.

Menu labels keep their established, specific action wording; emoji are not used as heading decoration. See `docs/agents/admin-dashboard-sidebar-cutover.md` for the recorded decisions and evidence, including the `supplier-*` CSS family that this contract removed.

Admin must prioritize scannability over flourish: order status, stock state, payment data, product names, totals, and destructive actions must remain unambiguous.

## Layout and spacing

- Storefront sections use centered max-width containers (`max-w-6xl` style structure) over a full-page paper/grid shell.
- Use grids for catalog/product arrangements and flex for button rows, chips, and nav groups.
- Storefront neo-brutal panels leave space for their offset shadows. Admin uses neutral separators and shared restrained elevation instead.
- Both surfaces avoid cards-inside-cards unless the inner element is a real input, data block, QR frame or status callout.
- On mobile, keep CTAs stacked or full-width when needed; payment and Telegram next-step actions must stay obvious.

## Motion and interaction

Current motion is mostly transform/opacity based and should stay that way.

- Storefront reveal items use IntersectionObserver and `translateY`/opacity transitions.
- Product cards/buttons lift on hover by changing transform and offset shadow.
- Decorative floating badges/icons use slow, gentle transform animations.
- Marquee, counters, QR scan-line, modal transitions, FAQ accordion, clipboard/share feedback, and category filters are supported by `src/resources/js/storefront.js`.
- Admin dashboard interactions are handled by `src/resources/js/admin-dashboard.js` and Alpine state in Blade templates.
- Never gate important content behind animation; content must remain present if JavaScript or motion is reduced.
- Respect `prefers-reduced-motion: reduce`; the base CSS already collapses animation/transition duration globally.

## Accessibility rules

- Target WCAG AA for customer and admin surfaces.
- Keep primary text against `--sf-paper`, `--sf-surface`, and accent fills at accessible contrast.
- Preserve strong keyboard focus: storefront focus uses a 3px blue outline with offset; admin controls should keep high-contrast rings and visible active states.
- Modals must keep dialog semantics (`aria-modal`, labelled headings) and must trap/restore interaction where applicable.
- FAQ toggles should keep `aria-expanded`; category filters should keep `aria-pressed`.
- Mobile navigation/menu controls should expose current expanded/active state where possible.
- Do not rely on color alone for stock, payment, error, or success state; pair color with labels/icons/text.

## Implementation map

### Storefront

- Main shell: `src/resources/views/storefront.blade.php`
- Pages: `src/resources/views/storefront/product.blade.php`, `src/resources/views/storefront/payment.blade.php`, `src/resources/views/storefront/login-guide.blade.php, `api-docs.blade.php`
- Partials: `src/resources/views/storefront/partials/head.blade.php`, `header.blade.php`, `hero.blade.php`, `catalog.blade.php`, `featured-offer.blade.php`, `benefits.blade.php`, `purchase-flow.blade.php`, `testimonial.blade.php`, `metrics.blade.php`, `faq.blade.php`, `support.blade.php`, `footer.blade.php`, `floating-support.blade.php`, `product-modal.blade.php`
- Components: `src/resources/views/storefront/components/brand-mark.blade.php`, `product-card.blade.php`, `product-cover.blade.php`, `qris-card.blade.php`
- CSS: `src/resources/css/app.css`
- JS: `src/resources/js/app.js`, `src/resources/js/storefront.js`
- Copy/config: `src/config/storefront.php`
- Assets: `src/resources/images/lhie-store-logo.webp`, `lhie-store-logo-512.png`, `lhie-store-logo.png`, `chibi-favicon-256.png`, `chibi-favicon-64.png`, `src/public/qr-lhie.jpg`, `src/public/storefront/login-guide/page-*.jpg`

### Admin

- Login: `src/resources/views/admin/login.blade.php`, password confirmation: `src/resources/views/auth/confirm-password.blade.php`
- Dashboard shell: `src/resources/views/admin/dashboard.blade.php`
- Dashboard partials: `src/resources/views/admin/dashboard/status-flash.blade.php`, `stats-tab.blade.php`, `products-tab.blade.php`, `product-card.blade.php`, `product-modal.blade.php`, `product-family-modal.blade.php`, `category-management-modal.blade.php`, `stock-modals.blade.php`, `storefront-banners.blade.php`, `sales-tab.blade.php`, `suppliers-tab.blade.php`, `broadcast-tab.blade.php`, `store-channel-tab.blade.php`, `history-tab.blade.php`, `withdraw-tab.blade.php`, `funnel-tab.blade.php`, `balance-issues-tab.blade.php`, `balance-issues-compensation-modal.blade.php`
- Linked workflow pages: `src/resources/views/admin/orders/show.blade.php`, `manual-fulfillment-reviews/{index,show}.blade.php`, `telegram-outbound-effects/{index,show}.blade.php`, `suppliers/*.blade.php`, `balance-reports/index.blade.php`, `store-balance/adjustments.blade.php`, `store-balance/usdt-review.blade.php`
- Admin components: `src/resources/views/components/admin-dashboard/*.blade.php`
- JS: `src/resources/js/admin-dashboard.js`, `src/resources/js/admin-dashboard/*.js`, `src/resources/js/app.js`

## Design guardrails

- Preserve the current electric-blue storefront identity; do not reintroduce the old orange storefront direction.
- Preserve Lhie Store naming and Telegram-first purchase/support cues; do not use Warung AI naming in new design docs or UI copy unless intentionally reintroduced by product strategy.
- Do not assume Filament for admin UI; the current admin is custom Blade components.
- Primary storefront actions are blue; admin primary actions use the muted violet accent fill with semantic amber/red reserved for real warning and destructive states.
- Black offset shadows belong to storefront primitives, not the Variant A Admin shell.
- Keep storefront decoration clear of product choice, stock, payment and support. Keep Admin hierarchy factual and operational.
