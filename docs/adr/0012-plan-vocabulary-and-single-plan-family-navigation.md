# Plan vocabulary and single-Plan family navigation

## Status

Accepted - 2026-09-26

## Decision

Customer-facing Product Family discovery names one **Product option** a
**Plan**:

- The Store Bot catalog labels a multi-option family with its Plan total and a
  forward affordance (`🎬 Netflix ▶ 3 Plan »` / `▶ 3 Plans »` for English).
  The number is the family's total Plan count, not the available count; button
  colour already carries availability.
- A family whose options are all sold out keeps its existing sold-out label.
- A family with exactly one public Product option uses that Product's name and
  icon for discovery, keeps its stock label (`📦 Spotify · Stok 5` / `Habis`),
  and submits `product:<id>`, so pressing it opens that Product Detail and
  never renders a one-item Plan list screen. The Web Storefront family-card
  list also displays the Product name.
- The default public sort ranks family entries by descending total sold units
  across their Product options. A sold unit is a quantity from a paid
  Processing or Completed Order. Ranking happens before pagination; ties keep
  the existing availability and family admin-order rules. Other sort modes
  and Admin family ordering are unchanged.
- The Plan list screen and the Web Storefront family page use the same
  vocabulary (`Pilih Plan`, `Choose a plan for`, `N Plan`).

Domain language is unchanged: `CONTEXT.md` keeps **Product Family** and
**Product option** as the entities, and `Plan` is only the customer-facing name
for a Product option. `Product ID` remains the identity submitted to cart,
checkout, order, inventory, waitlist, and delivery.

## Consequences

- The one-Plan shortcut is decided at the discovery entry
  (`TelegramCatalogIntents::entryKeyboard()`), so catalogue, category, and
  search listings share it without touching family session/origin state.
- Pressing a one-Plan family behaves like a standalone Product entry: Back from
  Product Detail returns to the product's category list rather than the
  originating search result. Multi-Plan families keep the origin-preserving
  family flow.
- Committed Order and OrderItem changes invalidate the cached catalog
  projection so the default family sales ranking refreshes.
- `/start family_<id>` still opens the Plan list, including for a one-Plan
  family, because the link explicitly targets the family.
- `catalog.family.button_plans` replaces
  `catalog.family.button_options_available`; every locale must carry the new
  key because `telegram:translation-audit` validates the union of catalog keys
  and placeholder parity against English.
