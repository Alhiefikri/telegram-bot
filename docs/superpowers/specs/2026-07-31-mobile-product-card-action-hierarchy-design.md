# Mobile Product Card Action Hierarchy Design

## Context

Issue #176 asks the admin product card to reduce decision load on a 375×900 mobile viewport. The current card renders six peer actions at once: Ubah, Stok, Kelola, Share, visibility toggle, and Hapus. This gives routine and rare or destructive actions equal prominence, while the delete route is described inconsistently as both Arsipkan and Hapus.

## Goals

- Show exactly one visually dominant primary action.
- Show no more than two secondary actions directly.
- Keep uncommon actions discoverable through one consistent disclosure control.
- Use Arsipkan consistently for the existing archive/delete route.
- Guarantee every interactive control is at least 44×44 CSS pixels using strict rendered geometry.
- Verify the behavior with Playwright at 375×900.

## Non-goals

- Changing product-management routes, authorization, payloads, or backend behavior.
- Redesigning the whole product card or admin dashboard.
- Replacing the existing Blade components or Alpine runtime.
- Changing desktop-only action semantics.

## Interaction Design

### Direct actions

The card exposes three routine actions in a stable order:

1. **Stok** is the primary action because replenishing inventory is the most common operational task and directly affects product availability.
2. **Ubah** is a secondary action for product metadata.
3. **Kelola** is a secondary action for existing stock credentials and history.

On mobile, Stok occupies the full first row. Ubah and Kelola share the second row. This creates an explicit visual hierarchy without introducing a new component vocabulary.

### Disclosure

A full-width **Aksi lainnya** button follows the direct actions. It uses `aria-expanded` and `aria-controls`, and toggles an in-flow panel rather than an absolutely positioned dropdown. The in-flow panel avoids clipping and stacking-context problems inside cards and remains usable with zoom or long labels.

The disclosed actions are:

- **Bagikan** for copying the product share URL.
- The existing visibility action, preserving its dynamic label such as Pause or Aktifkan.
- **Arsipkan** for the existing destructive archive route.

The panel is hidden by default and each product card owns independent disclosure state. Escape closes the panel and returns focus to the disclosure button. Clicking outside is not required because the panel is in-flow, non-modal, and remains spatially attached to its trigger.

### Terminology

The destructive action and confirmation both use **Arsipkan**. The implementation does not claim permanent deletion because the existing confirmation already describes archival behavior.

## Accessibility

- All direct, disclosure, and disclosed controls use the shared admin button component.
- The small button size retains a strict minimum block size of 44 CSS pixels.
- Form wrappers stretch their child buttons to the available width.
- `aria-expanded` reflects disclosure state.
- `aria-controls` points to a unique panel ID derived from the product ID.
- The disclosed region has an accessible label tied to the trigger.
- Hidden actions are not focusable because Alpine applies `x-cloak` and `x-show`.
- Focus indicators continue using the existing high-contrast admin component styles.

## Responsive Layout

- At mobile widths, Stok is full width, followed by a two-column Ubah/Kelola row.
- At larger widths, the same hierarchy remains stable rather than restoring six equal peers. Consistency reduces relearning and keeps destructive actions out of the primary scan path.
- The disclosure panel uses a one-column mobile layout and may use a wider multi-column layout at the existing small breakpoint if labels remain readable.

## Testing Strategy

### Feature test

Update the rendered-card feature test to assert:

- Stok, Ubah, Kelola, and Aksi lainnya appear in order.
- Bagikan, visibility action, and Arsipkan remain present in the server-rendered HTML.
- Hapus is not used for the product archive action.
- Disclosure semantics and unique panel identifiers are rendered.

### Playwright test at 375×900

Add focused assertions that:

- The card initially has exactly three directly visible management actions plus the disclosure trigger.
- Stok is visually primary and appears before Ubah and Kelola.
- Bagikan, visibility, and Arsipkan are initially hidden.
- Activating Aksi lainnya reveals all uncommon actions and updates `aria-expanded`.
- Every visible interactive control inside the action area has raw `getBoundingClientRect()` width and height greater than or equal to 44, without rounding or ceiling tolerance.
- The archive action remains reachable after disclosure.
- The document does not overflow horizontally.

## Implementation Boundaries

The production change should remain localized to:

- `src/resources/views/admin/dashboard/product-card.blade.php`
- the shared button component only if strict geometry demonstrates it is necessary
- `src/tests/Feature/AdminDashboardStockFlowsTest.php`
- `src/tests/Browser/admin-dashboard-responsive.spec.js`

No backend route or controller changes are expected.
