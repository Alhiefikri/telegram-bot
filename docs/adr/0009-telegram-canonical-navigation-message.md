# Canonical Telegram navigation message

## Status

Accepted - 2026-09-10

## Decision

Each private Telegram Customer chat has at most one current **Canonical
Navigation Message**: a bot-owned message tracked in `telegram_navigation_roots`
by `customer_id`, `chat_id`, `message_id`, media kind, screen, locale, and a
generation/lease pair. The welcome banner establishes it, and every routine
customer navigation action edits that same message instead of sending a new
bot reply:

- Browse Products (catalog list, category picker, category list, family
  options, product detail, search results);
- My Balance, Orders, Support, and the Language picker;
- Back and Home, which always return to the current home projection.

`TelegramNavigationPresenter` is the presentation seam. A callback may only
commit to the root the callback message really targets; a callback on any other
message is stale, answers a localized recovery hint, and never edits or sends.
Content built without an originating message (commands such as `/menu`,
`/produk`, `/riwayat`, `/support`, `/language`) edits the current root when one
exists and only keeps the legacy send path when the chat has no root yet.

Rendering leases the stored generation before touching Telegram and commits the
new screen/locale projection only after the edit is proven. A lost lease, an
ambiguous Telegram result, or `message is not modified` therefore never
advances the stored screen optimistically; a lost lease is reported as
superseded, ambiguity only logs and answers a localized recovery hint.

A photo message can only carry a 1024-character parsed caption. When a
navigation screen exceeds that limit, the root is replaced exactly once by an
equivalent text message and the stored pointer moves atomically; the banner
file reference is retained for the next `/start`. A definitively uneditable
message (`message to edit not found`, `there is no text in the message to
edit`, and the other definite Bot API failures) is replaced exactly once the
same way. An ambiguous outcome never triggers a blind replacement send.

Durable transactional artifacts — QRIS instructions, receipts, delivered
credentials, checkout instruction effects, and business notifications — are
never tracked as the root and are never overwritten by navigation. The receipt
"main menu" action opens the canonical home instead of editing the receipt.

Group chats never own a private root and receive a public-safe surface only:
the group home exposes Browse Products, group catalog navigation edits the
originating group message, and financial or identity actions are denied before
any handler runs. The persistent reply keyboard (and its `☰ Menu` / main-menu
text aliases) is removed rather than retained alongside the inline navigation.
Legacy product labels remain accepted as migration entrypoints: they open the
catalog at the chat tail, remove the stale keyboard, and restore inline controls
on that same root message.

## Consequences

- Routine navigation produces exactly one edited message; a stale, ambiguous,
  or foreign callback produces no message side effect.
- The root projection is evidence of what the Customer sees, so recovery and
  reconciliation can trust `screen`, `locale`, and `rendered_at`.
- Long catalog screens may downgrade the root from banner photo to text for the
  rest of the session; the file reference is kept and reused by `/start`.
- Each home, Balance, Orders, and Support render reads fresh Customer Store
  Balance while global Store Activity metrics keep their bounded-staleness
  cache.
- Tests assert the invariant at webhook level
  (`tests/Feature/Telegram/TelegramRoutineNavigationTest.php`) and at the
  PostgreSQL generation/lease boundary
  (`tests/Feature/Telegram/TelegramNavigationRootPostgresConcurrencyTest.php`).
