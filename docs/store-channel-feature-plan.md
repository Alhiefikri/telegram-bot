# Store Channel Feature Plan

This plan captures the domain decisions and implementation slices for adding a Store Channel to LhieStore.

## Locked decisions

- There is one **Store Bot**: `lhiestore_bot`.
- There is one public **Store Channel**: `LHIE STORE`.
- The Store Channel is used for:
  - required membership before customers can use store flows,
  - automatic Sales Proof Posts,
  - owner announcements.
- The bot must be an administrator in the Store Channel with permissions to check members and post messages.
- Store Channel setup and feature toggles are managed from the admin UI/database, not environment variables.
- Store Channel setup defaults to disabled until an admin tests the channel and enables the toggles manually.
- If the Community Gate is enabled but the Store Channel setup is invalid, customers fail open so the store does not become inaccessible because of a bad configuration.
- A customer who leaves the Store Channel is blocked again the next time they enter a store flow.
- Sales Proof Posts are triggered after payment is `paid` and the inventory sale has been recorded.
- Every paid order from every payment method creates at most one Sales Proof Post.
- Sales Proof Post failure never rolls back or blocks the order. Failed posts retry automatically up to three times, then can be retried manually from the order detail page.
- The public Sales Proof Post is one English `sendPhoto` delivery: the shared
  public-safe invoice image plus an English caption with masked buyer ID,
  invoice/order ID, item summary, payment method, WIB completion time,
  quantity, subtotal, amount paid, successful-order number, thanks, and the
  `🛒 Shop Now` action.
- Product category lists and search results show active products even when unavailable, using `✅` for available products and `❌` for unavailable products.

- Unavailable products can be opened, but cannot be bought or added to cart.
- Checkout must revalidate stock and reject cart items that became unavailable.

### Public financial exposure audit

The shared public invoice intentionally includes subtotal, provider/admin fee
when present, and amount paid. This is an approved product contract, not an
incidental renderer leak: issue [#322](https://github.com/Alhiefikri/telegram-bot/issues/322)
approved those fields in the shared invoice, and issue
[#324](https://github.com/Alhiefikri/telegram-bot/issues/324) approved the same
invoice and English totals in Store Channel Sales Proof. The implementation
must continue excluding credentials, OTP destinations, access tokens, Recovery
Keys, support contacts, usernames, display names, and full Telegram IDs.

## Architecture boundaries

Do not implement this as a god file. In particular, avoid growing the Telegram routers or Bot API client into business-logic containers.

Recommended seams:

- **Telegram Bot API client**: low-level wrapper for `sendMessage`, `sendPhoto`,
  `getChat`, `getChatMember`, and `deleteMessage`. It should return Telegram
  payloads where callers need `message_id`.
- **Store Channel settings**: database-backed source of truth for channel username/link, resolved channel ID, toggles, setup status, and last test result.
- **Store Channel setup verifier**: resolves public channel username/link, checks whether the bot can access/post, sends an actual test message, and records the result.
- **Community Gate**: checks current channel membership, blocks store flows, reuses one active join prompt per customer, deletes the prompt after successful verification, and releases the customer into the existing `/start` welcome flow.
- **Sales Proof publisher**: idempotently publishes public proof posts after paid-order events and owns retry state.
- **Paid invoice presentation**: adapts a real paid Order or an in-memory synthetic Product presentation into the one shared public-safe invoice renderer.
- **Sales Proof formatter**: pure English formatting over the immutable invoice presentation, with bounded Product names and a 1,024-character caption limit.
- **Product availability presenter**: renders `✅`/`❌` rows and unavailable detail state separately from cart/checkout stock validation.

## Data model

### Store Channel settings

Single-row settings for:

- channel username/link,
- resolved channel ID,
- Community Gate toggle,
- Sales Proof Post toggle,
- last test timestamp,
- last test success/failure,
- last test message ID,
- last test error reason.

### Sales Proof Posts

Per-order idempotency and retry state:

- unique order reference,
- channel chat ID,
- Telegram message ID,
- status: `pending`, `sent`, or `failed`,
- attempts,
- last error,
- posted timestamp,
- next retry timestamp.

### Community Gate prompts

Per-customer prompt reuse/deletion state:

- customer reference,
- Telegram chat ID,
- prompt message ID,
- sent timestamp,
- deleted timestamp.

## Flows

### Admin Store Channel setup

1. Admin opens the **Telegram Channel** admin page.
2. Admin enters the public Store Channel username/link.
3. Admin clicks **Test Channel**.
4. The system resolves the channel ID, verifies bot access/posting ability, sends a test message to the channel, and stores the result.
5. A successful test does not automatically enable any feature. Admin enables Community Gate and Sales Proof Posts manually.

### Community Gate

1. A Telegram update enters the bot.
2. The customer is resolved.
3. If the gate is disabled, existing behavior continues.
4. If the gate is enabled and setup is valid, the bot checks current membership in the Store Channel.
5. Members, administrators, and creators continue to the existing store flow.
6. Non-members are shown one reused join prompt and store flows are blocked.
7. `/start`, the join prompt, and the “Saya sudah join” verification action remain available while blocked.
8. After successful verification, the bot deletes the join prompt and sends the existing `/start` welcome plus main menu.

### Sales Proof Post

1. Payment becomes `paid` and inventory sale has been recorded.
2. A lightweight job runs after the database commit.
3. The job claims the order in Sales Proof Post storage. If the order is already sent, it stops.
4. The real publisher adapts the paid Order to the shared public-safe invoice presentation. The formatter builds a bounded English caption with:
   - `ORDER PAYMENT CONFIRMED`,
   - masked Buyer ID and full invoice/order ID,
   - one item line or bounded multi-item list,
   - `Paid via` and English WIB completion time,
   - quantity, subtotal, amount paid, and successful-order number,
   - `Thank you for choosing Lhie Store.`,
5. The bot sends one `sendPhoto` request with the shared invoice PNG, caption, and `🛒 Shop Now` action.
6. Success stores Telegram `message_id` and marks the post `sent`.
7. Definite failure increments attempts and remains retryable; ambiguous delivery becomes `unknown` and is never automatically resent. Finalization synchronizes the SalesProofPost projection without affecting the paid Order.

Pre-cutover text effects remain an explicit compatibility exception only when
their template is `sales-proof-post-v1`. The repair migration and runtime
normalizer change only retryable `pending`/`failed` effects that still combine
`sendMessage` with `sales-proof-post-v2`; `sent`, `unknown`, and
`manual_review` effects are never rewritten or automatically resent.


### Product availability

1. Category and search results include active products even when no stock is available.
2. Rows render as `✅ Product Name` or `❌ Product Name`.
3. Unavailable product detail remains visible, but buy/add-to-cart actions are hidden.
4. Checkout revalidates stock and rejects unavailable cart items with a clear instruction to update the cart.

## Admin UI requirements

Create a dedicated **Telegram Channel** page with:

- channel username/link input,
- resolved channel ID,
- setup status,
- last test result and error reason,
- **Test Channel** action that sends a real message,
- Community Gate toggle,
- Sales Proof Post toggle,
- warning when a toggle is enabled but setup is invalid.

Order detail should show:

- Sales Proof Post status,
- Telegram message ID when sent,
- failure reason when failed,
- manual retry action when failed.

## Issue breakdown

The feature is split into five tracer-bullet issues:

1. [#49 Admin Store Channel setup and settings](https://github.com/Alhiefikri/telegram-bot/issues/49).
2. [#50 Community Gate membership enforcement](https://github.com/Alhiefikri/telegram-bot/issues/50).
3. [#51 Product availability presentation and checkout guard](https://github.com/Alhiefikri/telegram-bot/issues/51).
4. [#52 Idempotent Sales Proof publishing](https://github.com/Alhiefikri/telegram-bot/issues/52).
5. [#53 Sales Proof visibility and manual retry from order detail](https://github.com/Alhiefikri/telegram-bot/issues/53).

This keeps each issue demoable without making the set too fragmented.

## Operational constraints

- Keep the design light for a VPS with 2GB RAM and 2 CPU.
- Do not poll Telegram. Use webhook-driven updates and membership checks only at relevant entry points.
- Use lightweight queued jobs and bounded retries.
- Prefer plain text Telegram messages unless escaping is implemented.
- Use resolved numeric channel IDs internally even when admins configure by public username/link.
