# DESIGN.md

## Product surface

The admin dashboard for this Telegram commerce bot uses Filament. The design system below applies to the Filament admin panel only; customer Telegram flows, payment logic, order logic, and public Laravel starter views are out of scope.

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
