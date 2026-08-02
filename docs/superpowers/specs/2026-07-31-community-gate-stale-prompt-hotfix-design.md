# Community Gate Stale Prompt Hotfix Design

**Date:** 2026-07-31
**Status:** Approved for immediate production hotfix

## Problem

A non-member can become permanently silent-blocked by the Telegram community gate. The application stores one `telegram_community_gate_prompts` row per customer and returns early whenever that row exists. Telegram does not provide a `getMessage` method that can prove a private-chat bot message is still visible. If the user deletes or loses the original prompt, later commands return successfully from the webhook but send no replacement guidance.

The normal `/start` path for an existing customer also sends welcome and menu content without checking membership. The first visible block therefore happens only after a later store action, making the gate appear inconsistent.

## Context7 API References

Context7 library `/websites/core_telegram_bots_api` resolves to the official Telegram Bot API documentation at <https://core.telegram.org/bots/api>.

- `getChatMember` is guaranteed for other users only when the bot is an administrator in the target chat. It returns a `ChatMember` on success.
- `restricted` users count as members only when `is_member` is true.
- `sendMessage` returns the newly sent `Message`, whose `message_id` is the durable identifier the application can store.
- `deleteMessage` is best-effort because deletion has Telegram-side limitations and may fail for missing or old messages.
- Every inline-button callback must receive `answerCallbackQuery` so Telegram stops the client progress indicator.

These constraints mean stored `message_id` data is not proof that a prompt remains visible. The only reliable recovery path is to send a fresh prompt and replace the stored identifier.

## Selected Design

### Prompt refresh

Whenever the gate blocks a customer:

1. Look up the existing prompt row.
2. If it has a message ID, attempt `deleteMessage` without allowing deletion failure to block recovery.
3. Send a fresh localized join prompt with the channel URL and verification callback.
4. Atomically replace the stored chat ID and message ID using `updateOrCreate`.

This preserves one canonical stored prompt while guaranteeing that every blocked interaction produces visible recovery guidance. Telegram may retain an old message when deletion is rejected, but the newest actionable prompt is always available.

### Consistent `/start`

After language selection exists, every `/start` variant must pass through the community gate before welcome, main-menu, or product-deep-link content is sent. Language selection itself remains available to first-time users. Members retain the existing welcome flow.

### Membership lookup failures

The gate remains fail-closed, but caught lookup failures are logged as `telegram.community_gate_membership_check_failed`. Error text is passed through `TelegramBotApiRedactor`; no customer Telegram ID or bot credential is logged. The existing prompt copy continues to tell the customer that Telegram verification may be temporarily unavailable and to retry later.

## Tests

Regression coverage will prove:

1. A second blocked message sends a second prompt, deletes the previous prompt best-effort, and stores the new message ID.
2. A failed prompt deletion still sends and stores a replacement prompt.
3. Existing-locale non-members are blocked on `/start` before welcome/menu.
4. Existing-locale members still receive welcome/menu on `/start`.
5. `getChatMember` failures are logged with redacted error text and still provide retry guidance.
6. Existing successful verification continues deleting stored prompt state, answering callbacks, and sending welcome/menu.

## Deployment

No schema or environment change is required. After local verification and review, the hotfix is merged into `main`, pushed, and allowed to complete the full `CI` workflow. Production is then deployed manually with `gh workflow run deploy-production.yml --ref main`, which deploys the CI-approved immutable image through Docker Compose project `telegram-bot-prod` and runs the repository's health, queue, scheduler, Telegram webhook, and public smoke gates.
