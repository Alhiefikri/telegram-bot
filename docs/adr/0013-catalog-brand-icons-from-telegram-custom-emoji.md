# Catalog brand icons from Telegram custom emoji

## Status

Superseded by [ADR-0015](0015-product-catalog-icons-from-telegram-custom-emoji.md) on 2026-09-28 (was Accepted - 2026-09-26).

## Decision

Catalog buttons may carry a **Catalog Brand Icon**: a brand entry in the
`catalog_brand_icons` table that owns name patterns, a plain Unicode fallback
glyph, and a captured Telegram custom emoji identifier.

- Matching order is unchanged in shape: an explicit Product `icon` wins, then
  the first brand whose pattern matches the Product or Product Family name, then
  the static default `🛍️`. Families keep inheriting from their own name rather
  than from a new family column.
- Inline keyboard buttons carry the captured identifier as
  `icon_custom_emoji_id` and drop the glyph from their text; message bodies keep
  using the Unicode glyph. Custom emoji in free text and in Store Channel posts
  are out of scope.
- Identifiers are captured, never typed: the admin opens the icon lists in the
  Store Bot (brands and named navigation slots), picks one entry, and sends that
  emoji in a private chat. The webhook ingress keeps `custom_emoji` entities
  (and only those) in its minimized payload, the pending capture
  (`TelegramIconCaptureSession`, one kind at a time) consumes the next private
  message, and the identifier is stored only after `getCustomEmojiStickers`
  resolves it.
- Named navigation slots live in `telegram_ui_icons` (home, back, product
  catalog, balance, orders, support, language, channel). Their Unicode glyph
  stays in the locale copy, so a slot without a captured identifier renders the
  original string untouched; `TelegramMenuIntents::navigationRow()` and
  `homeKeyboard()` are the seams that apply it, and screens reusing those rows
  inherit the icon without touching their own copy.
- The Admin Dashboard page `/admin/brand-icons` owns label, patterns, fallback
  glyph, and sort order, and can clear a captured identifier. The previous
  `telegram-product-icons.brands` configuration list is retired; only the code
  default `🛍️` stays in configuration.
- `catalog:brand-icons-check` audits stored identifiers against
  `getCustomEmojiStickers` and can clear a remembered rejection.

## Consequences

- Telegram only renders custom emoji on buttons when the **owner of the bot has
  a Telegram Premium subscription**, and that route covers messages the bot
  sends to private, group, and supergroup chats. Brand icons therefore never
  appear in Store Channel posts; that would need a Fragment-purchased bot
  username instead.
- Telegram exposes no API to query that capability, so degradation is reactive:
  `CustomEmojiCapability` remembers a rejection for thirty minutes,
  `TelegramBotClient` retries the rejected send once with every icon stripped
  (a rejected request created no message, so nothing is duplicated), and the
  resolver then renders the Unicode glyph until the window expires. Customer
  visible messages never fail because of a lapsed subscription.
- Brand names are matched by ordered patterns, so a broad pattern added ahead of
  a specific one changes which icon later brands receive; the Admin Dashboard
  shows the order that decides it.
- The registry is cached for ten minutes and the model invalidates that cache on
  save and delete, so an admin edit shows up on the next render without a
  configuration-cache refresh or image rebuild. The navigation slot table
  behaves the same way.
- Navigation slots are keyed by locale key, so a screen that builds its own
  button instead of reusing `navigationRow()` (for example a screen-specific
  back label) keeps its Unicode glyph until it is moved onto the shared row.
- Every locale must carry the `catalog.brand_icon.*` keys because
  `telegram:translation-audit` validates the union of catalog keys and
  placeholder parity against English.
