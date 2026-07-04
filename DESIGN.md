# DESIGN.md

## Product surface

Lhie Store is a hybrid storefront and Telegram commerce product. The public website helps customers browse AI/digital premium accounts, understand the purchase flow, and move into Telegram/payment support. The admin surfaces help operators manage products, stock, sales, broadcasts, channels, and order follow-up.

Primary UI surfaces:

- Public storefront homepage/catalog shell: `src/resources/views/storefront.blade.php`
- Storefront pages: product detail, payment/QRIS checkout, login guide
- Storefront modal and components: product modal, product cards, product cover, QRIS card, brand mark
- Admin login page
- Admin dashboard: stats, products, sales, broadcasts, store channels, product/stock modals
- Admin order detail page

## Visual direction

The current system is playful neo-brutal commerce: pale paper backgrounds, electric blue customer CTAs, black ink outlines, hard offset shadows, yellow/green/lilac accent chips, bold rounded panels, visible grid texture, and geometric floating decor.

Storefront surfaces can be expressive and memorable, but transaction clarity wins over decoration. Admin surfaces are louder and denser, with neon paper backgrounds and compact controls, but should stay fast to scan and operationally precise.

Avoid drifting back to older directions that no longer match the site: orange Warung AI storefront styling, Filament admin assumptions, generic SaaS dark gradients, or sterile premium minimalism.

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

The admin dashboard currently uses hardcoded colors in `src/resources/views/admin/dashboard/styles.blade.php` and component classes.

| Color | Value | Usage |
| --- | --- | --- |
| Neo ink | `#050505` | Main admin text, borders, bottom nav, offset shadows |
| Admin paper | `#fff6cf` / `#fff4bd` | Mobile and desktop admin background |
| Green | `#7BD10A` | Header gradient, primary admin actions, positive emphasis |
| Yellow | `#f2da3d` / `#fee648` | Active nav, filter pills, key highlights |
| Cyan | `#16B4CA` | Header gradient, informational accents |
| Pink | `#db3f98` / `#ff4fa8` | Desktop header/store accents and emphasis |
| Purple | `#8758de` | Store link section, earnings bar, focus/secondary accents |
| Blue | `#2d9cff` | Secondary accent where needed |
| Red | `#ef4444` | Destructive/error states |

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

- Mobile-first narrow shell: `.admin-mini-shell` maxes around `430px` on mobile and expands around `820px` on desktop.
- Background uses warm yellow paper plus radial neon washes; desktop simplifies the repeating stripe texture.
- Bottom mobile nav uses a near-black blurred pill bar; active items use yellow fill and hard black shadow.
- Desktop tab nav switches to a four-column grid.
- Filters use full-pill, 3px black borders, heavy `Space Grotesk`, uppercase labels, and compact count bubbles.
- Header/store-link sections use strong gradients instead of quiet neutral cards.
- Modals, notices, cards, form controls, and stat blocks should preserve hard borders, high contrast, and compact spacing.

Admin must prioritize scannability over flourish: order status, stock state, payment data, product names, totals, and destructive actions must remain unambiguous.

## Layout and spacing

- Storefront sections use centered max-width containers (`max-w-6xl` style structure) over a full-page paper/grid shell.
- Use grids for catalog/product arrangements and flex for button rows, chips, and nav groups.
- Neo-brutal panels rely on fewer, stronger containers; avoid cards-inside-cards unless the inner element is clearly an input, code block, QR frame, or status callout.
- Buttons and cards should have enough spacing for their offset shadows to breathe; do not place hard-shadow components flush against viewport edges.
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
- Pages: `src/resources/views/storefront/product.blade.php`, `src/resources/views/storefront/payment.blade.php`, `src/resources/views/storefront/login-guide.blade.php`
- Partials: `src/resources/views/storefront/partials/head.blade.php`, `header.blade.php`, `hero.blade.php`, `catalog.blade.php`, `featured-offer.blade.php`, `trust-marquee.blade.php`, `benefits.blade.php`, `purchase-flow.blade.php`, `testimonial.blade.php`, `metrics.blade.php`, `faq.blade.php`, `support.blade.php`, `footer.blade.php`, `floating-support.blade.php`, `product-modal.blade.php`
- Components: `src/resources/views/storefront/components/brand-mark.blade.php`, `product-card.blade.php`, `product-cover.blade.php`, `qris-card.blade.php`
- CSS: `src/resources/css/app.css`
- JS: `src/resources/js/app.js`, `src/resources/js/storefront.js`
- Copy/config: `src/config/storefront.php`
- Assets: `src/resources/images/lhie-store-logo.webp`, `lhie-store-logo-512.png`, `lhie-store-logo.png`, `chibi-favicon-256.png`, `chibi-favicon-64.png`, `src/public/qr-lhie.jpg`, `src/public/storefront/login-guide/page-*.jpg`

### Admin

- Login: `src/resources/views/admin/login.blade.php`
- Dashboard shell: `src/resources/views/admin/dashboard.blade.php`
- Order detail: `src/resources/views/admin/orders/show.blade.php`
- Dashboard styles: `src/resources/views/admin/dashboard/styles.blade.php`
- Dashboard partials: `src/resources/views/admin/dashboard/header.blade.php`, `desktop-navigation.blade.php`, `mobile-navigation.blade.php`, `status-flash.blade.php`, `stats-tab.blade.php`, `products-tab.blade.php`, `sales-tab.blade.php`, `broadcast-tab.blade.php`, `store-channel-tab.blade.php`, `product-card.blade.php`, `product-modal.blade.php`, `stock-modals.blade.php`
- Admin components: `src/resources/views/components/admin-dashboard/*.blade.php`
- JS: `src/resources/js/admin-dashboard.js`, `src/resources/js/app.js`

## Design guardrails

- Preserve the current electric-blue storefront identity; do not reintroduce the old orange storefront direction.
- Preserve Lhie Store naming and Telegram-first purchase/support cues; do not use Warung AI naming in new design docs or UI copy unless intentionally reintroduced by product strategy.
- Do not assume Filament for admin UI; the current admin is custom Blade components.
- Primary storefront actions are blue; admin primary/active actions may be green or yellow depending on context.
- Use black offset shadows deliberately. If a component already has a 3px border and hard shadow, avoid adding soft shadows or glass effects.
- Keep playful neo-brutal energy useful: decoration must not obscure product choice, stock, payment, support, or admin status.
