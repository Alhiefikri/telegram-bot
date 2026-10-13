# Product catalog icons from Telegram custom emoji

## Status

Accepted - 2026-09-28

Supersedes [ADR-0013](0013-catalog-brand-icons-from-telegram-custom-emoji.md).

Amended by [ADR-0024](0024-family-owned-catalog-icons-with-product-overrides.md), 2026-10-08: the
Product Family owns its icon now and every member without an icon of its own inherits it, so the
"first member supplies the family icon" derivation no longer applies.

## Context

ADR-0013 introduced a `catalog_brand_icons` registry whose name patterns were
matched against Product and Product Family names. The brand list shipped with
the schema instead of the catalog: the Store Bot admin icon screen showed brands
that do not exist in the store and hid the Products that do, and a Product could
receive an icon from an accidental name match. The owner asked for the icon list
to mirror the real catalog and for every Product to be configured explicitly.

## Decision

- Catalog icons are per-Product data. Each Product owns an optional plain
  Unicode `icon` and a captured Telegram `custom_emoji_id`. Capturing a custom
  emoji also stores Telegram's associated Unicode `emoji` as the text and
  Web Storefront fallback; a plain Unicode capture sets `icon` directly. The
  Admin Dashboard can edit the glyph later. Nothing is inferred from a Product
  name.
- The admin icon screen in the Store Bot lists the Products that exist in the
  `products` table, active first, and each entry captures or clears that
  Product's custom emoji. An empty catalog renders an explicit empty state
  instead of a fixed brand list.
- A Product Family presents one shared icon in its heading and on every Plan
  text row. The first member in family order with a captured custom emoji
  supplies both the identifier and its associated glyph; otherwise the first
  member with a glyph supplies it, then the shared default glyph `🛍️` is used.
  The same public-member selection is used by every live-Product Telegram copy,
  including each variant's detail and quantity prompts, product notifications,
  cart, broadcast previews and buttons, the admin stock list, and price-change
  snapshots, and it is snapshotted onto Order Items for receipt and Sales Proof
  presentation.
  Multi-Plan discovery entries and Plan-selection button labels use the compact
  Unicode marker `▸`. When the family has a captured custom emoji, every
  Plan-selection button also carries the shared family
  `icon_custom_emoji_id`; a family without one keeps the Unicode marker as its
  button indicator.
- Product-owned custom emoji render as Telegram custom emoji in supported text,
  with the Product's Unicode glyph as fallback; inline catalog buttons use
  `icon_custom_emoji_id`. Product icons remain stored only on Products.
- Static bot copy uses independent `message.*` slots in `telegram_ui_icons`.
  `TelegramTranslationCatalog` resolves source glyphs in trusted translation
  templates before interpolation; code templates render static prefixes before
  adding customer, Product, payment, or credential values.
- Plain message/caption text consumes every `<tg-emoji>` wrapper into Telegram
  custom emoji entities, including catalog wrappers that carry no internal
  marker; no literal markup may reach a customer. Inline keyboard labels use the
  configured Unicode fallback.
  Store Channel promotions attempt custom emoji entities and fall back to
  Unicode when Telegram rejects them; the Web Storefront uses Product glyphs.
- Translated copy that renders a `message.*` icon already carries its wrapper.
  Rendering static copy is idempotent, so a second pass never nests wrappers,
  and any later escaping of such copy must preserve trusted wrappers
  (`TelegramHtmlCaption::escapeTextPreservingIcons()`); plain `htmlspecialchars`
  would expose the tag as customer-visible text.
- The `catalog_brand_icons` table, its name patterns, its Admin Dashboard page
  (`/admin/brand-icons`), and the brand callback namespace are retired. The
  `products.custom_emoji_id` column replaces them; a Product model change
  invalidates the Telegram catalog projection cache, so a captured icon appears
  on the next render.
- Menu navigation slots remain separate `menu.*` rows in `telegram_ui_icons`.
  The `menuicon:` flow configures them; the `messageicon:` flow configures
  shared static-copy slots without changing Product-owned icon values.
- Custom identifiers are captured, never typed: the admin picks a slot in the
  Store Bot and sends a premium emoji in a private chat; the identifier is
  stored only after `getCustomEmojiStickers` resolves it. Plain Unicode emoji
  can set a slot's fallback directly. `catalog:icons-check` audits Product,
  menu, and message-copy identifiers and can clear a remembered rejection.
- Locale keys are `catalog.product_icon.*` (renamed from `catalog.brand_icon.*`)
  and every locale must carry them because `telegram:translation-audit`
  validates the union of catalog keys and placeholder parity against English.

## Consequences

- Telegram allows custom emoji entities in supported bot text when the bot owner
  has a Telegram Premium subscription, which covers direct private, group, and
  supergroup messages. Channel posts additionally require the bot's
  Fragment-purchased additional username entitlement. Without it Telegram
  accepts the post with HTTP 200 and silently drops every `custom_emoji` entity
  and `icon_custom_emoji_id`, so a Store Channel post keeps its Unicode glyph
  and no rejection is observable. Rejected requests are retried with the
  configured Unicode fallback.
- Degradation stays reactive: `CustomEmojiCapability` remembers message and
  channel rejections separately for thirty minutes, and
  `TelegramBotClient` retries once with message wrappers and button identifiers
  stripped, preserving the Unicode fallback. A channel rejection does not
  disable custom emoji in supported bot chats.
- Product icons are presentation only. Checkout snapshots the effective icon
  onto Order Items, but pricing, stock, payment, eligibility, and fulfillment do
  not depend on it. The Admin Dashboard remains available to edit the Unicode
  glyph after capture.
- Replacing the name-pattern registry with explicit per-Product configuration
  means a new Product starts with the default glyph until the owner captures its
  icon; no automatic brand detection is performed.
