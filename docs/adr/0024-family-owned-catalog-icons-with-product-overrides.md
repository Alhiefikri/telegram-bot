# Family-owned catalog icons with per-Product overrides

## Status

Accepted - 2026-10-08

Amends [ADR-0015](0015-product-catalog-icons-from-telegram-custom-emoji.md): the family icon is now
owned by the Product Family instead of being derived from its members on every read.

## Context

ADR-0015 derived one shared family icon from the members on every read: the first public member in
family order with a captured custom emoji supplied both values, otherwise the first public member
with a glyph supplied it. The Store Bot family capture then materialised that value by writing it
into `products.icon` and `products.custom_emoji_id` on every member of the family.

That storage has two consequences: a family capture overwrites every member's own glyph, and a
Product can never keep an icon that differs from its family. The owner asked for a per-Product custom
icon with the family icon as its fallback ("kalau ga di custom dia ambil dari icon family").

## Decision

- A Product Family owns an optional catalog icon: `product_families.icon` (Unicode glyph) and
  `product_families.custom_emoji_id` (captured identifier). The family capture flow in the Store Bot
  writes those columns and never copies them onto member Products.
- A Product's own stored pair remains the per-Product override. The effective icon of a Product is
  its own pair when it stores a glyph or a captured identifier, otherwise the icon of the family it
  belongs to, otherwise the renderer's default glyph `🛍️`. `CatalogFamilyIconResolver` is the single
  place that decides this (`forProduct`/`forProducts` for a Product, `forFamily` for the family
  presentation), so catalog read models, Telegram copy, notifications, the stock list, broadcast
  previews, price-change snapshots, Web Storefront covers, and the checkout Order-item snapshot all
  agree.
- An override replaces the whole pair. A Product that stores a glyph without an identifier drops the
  family's premium emoji for that Product instead of mixing one Product glyph with a family
  identifier, because the button icon and the text fallback must describe the same icon.
- The Store Bot icon screen keeps one row per family. Picking a family opens the family capture
  prompt plus one `🎯 Ikon per Produk` button that lists the family's variants; each variant row
  previews the icon it presents now, marked ✅ when it carries its own override and ⬜ when it
  inherits the family icon. Capturing on a variant stores the Product override; clearing a family
  variant releases the whole override so it inherits again, while a standalone Product keeps clearing
  only its captured identifier so its editable glyph stays the text icon.
- The Admin Dashboard family form gains the same optional glyph field as the Product form, so the
  family icon can be edited without the bot.
- `catalog:icons-check` audits captured family identifiers next to product, menu, and message-copy
  identifiers.

## Consequences

- Rendering is unchanged for existing data: the migration copies the value ADR-0015 would have
  derived into the family row, then releases member pairs that exactly equal it, so a materialised
  family copy becomes inheritance instead of an override.
- A member whose stored glyph differed from the derived family value keeps it as a real per-Product
  override, so that Product shows its own glyph where the catalog previously showed the family icon.
  The owner can clear it from the new variant list to inherit again.
- A family with no icon of its own presents the default `🛍️` glyph; nothing is inferred from member
  icons any more, so a member override never leaks into the family heading or its Plan buttons.
- The family icon is a nullable pair. An empty identifier falls back to the glyph, and
  `CustomEmojiCapability` keeps degrading to Unicode whenever Telegram rejects custom emoji.
- Changing a family icon invalidates the Telegram catalog projection cache through the existing
  `ProductFamily` observer, and `TelegramCatalogProjectionCache::DESIGN_VERSION` was bumped because
  the cached family read model gained the icon fields.
- Icons stay presentation only: price, stock, checkout, and fulfillment are untouched, and no icon
  value is ever read from ENV.
