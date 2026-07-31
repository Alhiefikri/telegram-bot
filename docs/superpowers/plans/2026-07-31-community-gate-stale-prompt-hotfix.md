# Community Gate Stale Prompt Hotfix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Guarantee visible recovery guidance for every blocked non-member, enforce membership consistently on `/start`, and release the verified hotfix through the production CI/CD workflow.

**Architecture:** Keep access policy in `TelegramCommunityGate`. Replace stale prompt state by best-effort deletion followed by a fresh prompt and `updateOrCreate`; add redacted warning logging at the membership API boundary. Move the existing-locale `/start` gate check ahead of all welcome/product responses while keeping first-time language selection available.

**Tech Stack:** PHP 8.4+, Laravel, PHPUnit, Laravel HTTP fakes, Telegram Bot API, GitHub Actions, Docker Compose.

## Global Constraints

- Preserve fail-closed membership enforcement.
- Do not add a migration, dependency, environment variable, or new service.
- Never log Telegram user IDs, bot tokens, webhook secrets, or unredacted Bot API URLs.
- Continue answering every callback query.
- Deploy only from `main` using `.github/workflows/deploy-production.yml` after successful CI for the exact SHA.
- Production Compose project remains `telegram-bot-prod`.

---

### Task 1: Refresh blocked prompts and enforce `/start`

**Files:**
- Modify: `src/tests/Feature/Telegram/CommunityGateTest.php`
- Modify: `src/app/Services/Telegram/TelegramCommunityGate.php`
- Modify: `src/app/Services/Telegram/TelegramMessageRouter.php`

**Interfaces:**
- Consumes: `TelegramBotClient::getChatMember`, `sendMessage`, `deleteMessage`; `TelegramBotApiRedactor::redactText`.
- Produces: unchanged public methods `TelegramCommunityGate::ensureAllowedOrPrompt(Customer $customer, string $chatId): bool` and `verify(Customer $customer, string $chatId): bool`.

- [ ] **Step 1: Write failing prompt-refresh tests**

Update the existing non-member reuse test so two blocked updates must produce two localized prompt messages, the first message ID must be passed to `deleteMessage`, and the database row must contain the second message ID. Add a second test whose fake returns HTTP 400 for `deleteMessage` and proves replacement still succeeds.

```php
$this->postTelegramWebhook($this->messageUpdate(1, '/katalog'))->assertOk();
$firstMessageId = TelegramCommunityGatePrompt::query()->sole()->message_id;
$this->postTelegramWebhook($this->messageUpdate(2, '/katalog'))->assertOk();

$this->assertSame(2, $this->sentCount('/sendMessage', 'Gabung kanal dulu ya'));
$this->assertNotSame($firstMessageId, TelegramCommunityGatePrompt::query()->sole()->message_id);
Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/deleteMessage')
    && $r['message_id'] === $firstMessageId);
```

- [ ] **Step 2: Write failing `/start` tests**

Create an existing Indonesian customer. With `getChatMember` returning `left`, `/start` must send the gate prompt and must not send welcome/menu. With `member`, `/start` must retain welcome/menu behavior.

- [ ] **Step 3: Write failing redacted logging test**

Use `Log::spy()`, return a `getChatMember` 400 response containing a configured token in the description, and assert `telegram.community_gate_membership_check_failed` is logged with redacted error text and no customer ID.

- [ ] **Step 4: Run tests and verify RED**

Run:

```bash
cd src
php artisan test --filter=CommunityGateTest
```

Expected failures:
- replacement prompt count remains one,
- old message is not deleted during repeat block,
- `/start` sends welcome/menu to a non-member,
- membership failure warning is absent.

- [ ] **Step 5: Implement minimal prompt replacement**

In `sendOrReusePrompt`, remove the early return. Best-effort delete the previous stored message, send the fresh prompt, then persist with:

```php
TelegramCommunityGatePrompt::query()->updateOrCreate(
    ['customer_id' => $customer->id],
    ['chat_id' => $chatId, 'message_id' => data_get($message, 'result.message_id')],
);
```

- [ ] **Step 6: Implement redacted membership failure logging**

Catch `Throwable $e`, log only the exception class and `TelegramBotApiRedactor::redactText($e->getMessage())`, then return false.

- [ ] **Step 7: Enforce gate on existing-locale `/start`**

After the null-locale language-picker return and before product or welcome handling, call `ensureAllowedOrPrompt`. Return immediately when blocked.

- [ ] **Step 8: Run targeted tests and verify GREEN**

```bash
cd src
php artisan test --filter=CommunityGateTest
```

Expected: all CommunityGate tests pass with no warnings or failures.

- [ ] **Step 9: Commit implementation**

```bash
git add src/tests/Feature/Telegram/CommunityGateTest.php \
  src/app/Services/Telegram/TelegramCommunityGate.php \
  src/app/Services/Telegram/TelegramMessageRouter.php
git commit -m "fix: refresh blocked community gate prompts"
```

---

### Task 2: Verify, review, integrate, and release

**Files:**
- Verify only: all changed files and release workflow.

**Interfaces:**
- Consumes: repository CI and `Deploy production` GitHub Actions workflow.
- Produces: a CI-approved immutable production image deployed to `telegram-bot-prod`.

- [ ] **Step 1: Run formatting and full local verification**

```bash
cd src
./vendor/bin/pint --test
composer test
```

Also run from repository root:

```bash
./scripts/smoke-admin-assets.sh
```

- [ ] **Step 2: Request code review**

Review the diff for spec compliance, regression risk, Telegram API semantics, secret safety, and test coverage. Resolve every blocking finding and rerun targeted/full tests.

- [ ] **Step 3: Confirm clean branch and commit history**

```bash
git status --short
git log --oneline main..HEAD
```

Expected: clean worktree with only the spec and implementation commits ahead of `main`.

- [ ] **Step 4: Integrate onto current `main` without touching unrelated working-tree files**

Fetch origin, ensure `origin/main` has not diverged unexpectedly, then fast-forward or cherry-pick the two hotfix commits into the root `main` checkout. Do not stage or modify existing Camouproxy work.

- [ ] **Step 5: Push `main` and wait for exact-SHA CI**

```bash
git push origin main
gh run list --workflow ci.yml --branch main --limit 5
ci_run_id="$(gh run list --workflow ci.yml --branch main --event push --limit 1 --json databaseId --jq '.[0].databaseId')"
gh run watch "$ci_run_id" --exit-status
```

Expected: the complete CI workflow succeeds and records the `production-image` digest for the pushed SHA.

- [ ] **Step 6: Dispatch production deployment**

```bash
gh workflow run deploy-production.yml --ref main
gh run list --workflow deploy-production.yml --branch main --limit 5
deploy_run_id="$(gh run list --workflow deploy-production.yml --branch main --event workflow_dispatch --limit 1 --json databaseId --jq '.[0].databaseId')"
gh run watch "$deploy_run_id" --exit-status
```

Expected: `Release gate` and `Deploy to production VPS` succeed; release result is `deploy-succeeded`.

- [ ] **Step 7: Verify production outcome**

Read the workflow summary/artifacts and confirm app health, scheduler heartbeat, queue depth, failed jobs, Telegram webhook registration, public smoke, immutable image identity, and absence of duplicate Telegram Compose projects all passed. Report the deployed commit SHA, CI run URL, deploy run URL, and any remaining manual Telegram UX check that cannot be simulated without a designated non-member account.
