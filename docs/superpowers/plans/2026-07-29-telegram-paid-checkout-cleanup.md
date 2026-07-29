# Telegram Paid Checkout Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Delete tracked KlikQRIS checkout and latest pending order-detail messages only after the buyer receipt is delivered successfully.

**Architecture:** Extend the order's durable Telegram-effect state with a second independently claimed detail-message target. Generalize the existing buyer checkout cleaner so one notifications job cleans both targets, and dispatch that job from the receipt notifier only after `telegram_receipt_sent_at` is persisted.

**Tech Stack:** PHP 8.4, Laravel 13, PostgreSQL, database queues, Telegram Bot API, PHPUnit 12.

## Global Constraints

- Schema changes are additive.
- Never delete receipts, delivered credentials, or failure messages.
- Cleanup is best-effort, idempotent, and independently retryable per target.
- Existing Binance cleanup behavior remains covered.
- No production credential, endpoint, or payment-provider activation changes.

---

### Task 1: Persist KlikQRIS Checkout And Order-Detail Message Targets

**Files:**
- Create: `src/database/migrations/2026_07_29_000001_add_buyer_order_detail_cleanup_state.php`
- Modify: `src/app/Models/Order.php`
- Modify: `src/app/Services/Telegram/TelegramCheckoutIntents.php`
- Modify: `src/app/Jobs/SendKlikQrisCheckoutInstructions.php`
- Modify: `src/app/Services/Telegram/TelegramOrderIntents.php`
- Test: `src/tests/Feature/Telegram/KlikQrisCheckoutTest.php`
- Test: `src/tests/Feature/Telegram/CartCheckoutTest.php`

**Interfaces:**
- `TelegramCheckoutIntents::sendKlikQrisPaymentInstructions(...): array` returns the Telegram `sendPhoto` response.
- `Order` stores the primary checkout target in existing `manual_checkout_customer_notification_*` fields.
- `Order` stores the latest detail target in new `buyer_order_detail_message_chat_id` and `buyer_order_detail_message_id` fields plus deletion claim/completion fields.

- [ ] Add failing tests asserting a successful KlikQRIS photo response persists the checkout chat/message IDs and a pending order-detail response persists its latest chat/message IDs.
- [ ] Run the two focused tests and confirm missing IDs/columns cause failure.
- [ ] Add the migration and model fillable/casts for detail target, deletion started/token, and deleted timestamp.
- [ ] Return the KlikQRIS photo response and persist its numeric message ID in the checkout job.
- [ ] Capture the final pending order-detail chunk response and atomically replace the tracked detail target while resetting its cleanup state.
- [ ] Run the focused tests and migration/model tests to green.

### Task 2: Clean Both Targets After Successful Receipt

**Files:**
- Create: `src/app/Jobs/DeleteBuyerCheckoutMessages.php`
- Modify: `src/app/Services/Telegram/TelegramBuyerCheckoutInstructionCleaner.php`
- Modify: `src/app/Services/Telegram/TelegramReceiptNotifier.php`
- Modify: `src/app/Services/OrderStateMachine.php`
- Modify: `src/app/Console/Commands/RecoverTelegramOutboundEffects.php`
- Test: `src/tests/Feature/TelegramCheckoutInstructionCleanupTest.php`
- Test: `src/tests/Feature/TelegramReceiptTest.php`

**Interfaces:**
- `TelegramBuyerCheckoutInstructionCleaner::deleteTrackedMessages(Order $order): void` independently claims and deletes checkout and detail targets.
- `DeleteBuyerCheckoutMessages` is unique per order and runs on `notifications`.
- Receipt notifier dispatches cleanup only after successful receipt state persistence.

- [ ] Add failing tests for no cleanup before receipt success, two-target deletion after success, one-target terminal 400 handling, transient failure claim release, stale target protection, and recovery dispatch.
- [ ] Run focused cleanup/receipt tests and confirm they fail for missing generic behavior.
- [ ] Implement a generic cleanup job and independently claimed target deletion using compare-and-set message IDs/tokens.
- [ ] Treat Telegram `message to delete not found` and `message can't be deleted` 400 responses as terminal outcomes; rethrow temporary failures.
- [ ] Remove paid-transition cleanup dispatches that race the receipt; dispatch cleanup after receipt persistence instead.
- [ ] Extend outbound recovery to redispatch completed/paid orders with due undeleted targets and a sent receipt.
- [ ] Run focused cleanup, receipt, lifecycle, and recovery tests to green.

### Task 3: Verify And Refresh Sandbox Runtime

**Files:**
- Modify only files required by verification failures.

**Interfaces:**
- Produces a migrated local sandbox runtime with automated stale-message cleanup.

- [ ] Run Pint on all changed PHP files.
- [ ] Run KlikQRIS Telegram, webhook, receipt, checkout cleanup, lifecycle, and migration/model suites.
- [ ] Run `git diff --check` and inspect the final diff for credential leakage.
- [ ] Rebuild `telegram-bot-prod` with the worktree `.env`, run migrations, and verify app/queue/scheduler health.
- [ ] Create a fresh real sandbox checkout, open order detail, simulate paid without another buyer status tap, and verify receipt remains while both tracked pending messages disappear.
