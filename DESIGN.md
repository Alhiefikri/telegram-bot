# DESIGN.md

## Product surface

The admin dashboard for this Telegram commerce bot uses Filament. The public storefront is a Laravel Blade catalog and landing page for Warung AI products. Customer Telegram flows, payment logic, and order logic are out of scope for visual-system changes.

## Visual direction

Gen Z neobrutalist operations desk: light paper background, black ink, hard borders, bold offset shadows, loud accent panels, and intentionally square controls. The UI should feel direct and high-contrast while preserving Filament's resource/table/form behavior.

## Color tokens

| Token | Value | Usage |
| --- | --- | --- |
| `--nb-paper` | `#fff8df` | Main admin background, warm off-white paper |
| `--nb-surface` | `#fffdf2` | Cards, forms, tables, dropdown panels |
| `--nb-surface-alt` | `#f4ead2` | Secondary panels and table striping |
| `--nb-ink` | `#111111` | Primary text, borders, icon strokes |
| `--nb-muted` | `#5b5347` | Secondary copy and helper text |
| `--nb-yellow` | `#ffd400` | Primary actions and selected states |
| `--nb-cyan` | `#00d5ff` | Info accents, active navigation highlights |
| `--nb-pink` | `#ff5caf` | Danger/emphasis accents and badges |
| `--nb-green` | `#7dff68` | Success accents |

## Type tokens

| Token | Value | Usage |
| --- | --- | --- |
| `--nb-font-sans` | `Instrument Sans`, system sans | Filament body and controls |
| `--nb-font-mono` | `ui-monospace`, `SFMono-Regular`, `Menlo`, monospace | Meta labels, badges, numeric/admin data |
| `--nb-meta-tracking` | `0.12em` | Uppercase labels and badges |
| `--nb-control-weight` | `800` | Buttons, tabs, nav labels, table headers |

## Shape and spacing tokens

| Token | Value | Usage |
| --- | --- | --- |
| `--nb-border` | `2px solid var(--nb-ink)` | Default hard outline |
| `--nb-border-thin` | `1px solid var(--nb-ink)` | Dense table separators |
| `--nb-radius` | `0.375rem` | Square-ish components with small softness |
| `--nb-radius-sm` | `0.1875rem` | Inputs, badges, compact controls |
| `--nb-shadow` | `6px 6px 0 var(--nb-ink)` | Cards and prominent panels |
| `--nb-shadow-sm` | `3px 3px 0 var(--nb-ink)` | Buttons, fields, badges |
| `--nb-focus` | `0 0 0 3px var(--nb-cyan), 4px 4px 0 var(--nb-ink)` | Keyboard focus and active control ring |

## Component rules

- Use CSS variables from this document for all Filament admin colors, shadows, borders, radius, and focus rings.
- Buttons, inputs, selects, table rows, tabs, modals, dropdowns, and login panels should use hard 2px black borders and square-ish corners.
- Primary action surfaces use yellow with black text. Info accents use cyan. Destructive or warning emphasis may use pink.
- Meta labels, badges, table headers, breadcrumbs, tabs, and the local-testing login label use uppercase text, mono font, heavy weight, and wide tracking.
- Do not use emoji icons. Keep Filament's SVG icon system.

## Motion and accessibility

- Motion is small and transform/opacity-only. Hover may translate by at most 1px to keep the neobrutalist offset-shadow feel.
- Respect `prefers-reduced-motion: reduce` by disabling transitions and transforms.
- Focus states must remain strong and visible on off-white, yellow, cyan, and pink surfaces.
- Text and controls must stay high contrast against paper and accent colors.

## Implementation map

- Filament theme CSS lives at `src/resources/css/filament/admin/theme.css` and imports Filament's base theme.
- The admin panel registers the theme through `->viteTheme('resources/css/filament/admin/theme.css')` in `AdminPanelProvider`.
- Vite builds the theme by adding `resources/css/filament/admin/theme.css` to the Laravel plugin input list.

## Public storefront system

The storefront uses a separate Warung AI ecommerce system: warm white page surfaces, orange brand actions, ink text, subtle orange wave/grid accents, rounded white cards, and restrained motion. Tokens are implemented with Tailwind utilities in `src/resources/views/storefront.blade.php` and shared motion helpers in `src/resources/css/app.css`.

Storefront color tokens:

| Token | Value | Usage |
| --- | --- | --- |
| `--sf-paper` | `#fffaf4` | Page background and broad section surfaces |
| `--sf-surface` | `#ffffff` | Header, product cards, FAQ panels, trust cards |
| `--sf-surface-warm` | `#fff3e6` | Orange-tinted panels, step cards, CTA wash |
| `--sf-ink` | `#211407` | Headlines, nav text, high-emphasis copy |
| `--sf-muted` | `#7a6047` | Supporting copy and secondary labels |
| `--sf-border` | `#f2dfca` | Soft card borders and dividers |
| `--sf-orange` | `#f97316` | Primary CTA, logo accent, active category chips, prices |
| `--sf-orange-deep` | `#ea580c` | CTA hover, strong accents, focus rings |
| `--sf-orange-soft` | `#fed7aa` | Badges, stock chips, decorative marks |
| `--sf-success` | `#15803d` | In-stock text and ready badges |
| `--sf-warning` | `#b45309` | Out-of-stock and waitlist badges |

Storefront component rules:

- Header is white, sticky, lightly bordered, and keeps the Warung AI logo image with text fallback behavior from the Blade.
- Primary CTAs use solid orange or orange-to-deep-orange gradients with white text. Secondary CTAs use white surfaces with orange borders and ink text.
- Product, FAQ, trust, and process cards use white or warm-white surfaces, `--sf-border`, large rounded corners, and orange-tinted shadows. Cards never use dark glass on the public page.
- Category chips and stock badges are pill-shaped, semantic, and anchored to the same orange palette; success green is reserved only for stock-ready status.
- Page texture comes from low-contrast orange waves, radial washes, and grid marks in `app.css`; no purple/blue AI gradients and no dark SaaS shell.
- Hero and product motion must be GPU-only: `transform`, `opacity`, or `filter`. Do not animate layout properties.
- Motion must be contextual and calm: small reveal, product-card lift, and decorative mark drift. Disable animation for `prefers-reduced-motion: reduce`.
