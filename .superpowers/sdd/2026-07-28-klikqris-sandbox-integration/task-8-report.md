# Task 8 Report: KlikQRIS one-minute reconciliation and Telegram status refresh

Date: 2026-07-28
Branch/worktree: `feature/klikqris-sandbox` in `.worktrees/klikqris-sandbox`

## Scope completed

- Added `klikqris:reconcile {--chunk=100}` as `App\Console\Commands\ReconcileKlikQrisPayments`.
- Registered one scheduler entry: `Schedule::command('klikqris:reconcile --chunk=100')->everyMinute()->withoutOverlapping();`.
- Added provider-specific Telegram order-detail refresh for KlikQRIS while preserving the existing Pakasir refresh method.
- Reused `KlikQrisProviderAdapter::transactionStatus()` and `KlikQrisCallbackProcessor::processReconciliationForPayment()` for both command and Telegram refresh.
- Extended KlikQRIS processor eligibility from pending-only to pending/expired/failed so provider-authoritative success can use the existing `OrderStateMachine` late-payment path. No new broad late-payment lifecycle was introduced.

## TDD evidence

RED run before implementation:

```bash
cd src && php -d opcache.enable_cli=0 artisan test tests/Feature/KlikQrisReconciliationTest.php tests/Feature/Telegram/KlikQrisCheckoutTest.php
```

Expected failures observed:

- `The command "klikqris:reconcile" does not exist.` for reconciliation command tests.
- Schedule test found zero `klikqris:reconcile --chunk=100` entries.
- Telegram detail tests showed KlikQRIS status was not refreshed before rendering.

GREEN/final focused regression after implementation and Pint:

```bash
cd src && php -d opcache.enable_cli=0 artisan test tests/Feature/KlikQrisReconciliationTest.php tests/Feature/Telegram/KlikQrisCheckoutTest.php tests/Feature/KlikQrisWebhookTest.php
```

Result: `25 passed (161 assertions)`.

Formatting:

```bash
cd src && vendor/bin/pint app/Console/Commands/ReconcileKlikQrisPayments.php app/Services/KlikQris/KlikQrisCallbackProcessor.php app/Services/Telegram/TelegramOrderIntents.php routes/console.php tests/Feature/KlikQrisReconciliationTest.php tests/Feature/Telegram/KlikQrisCheckoutTest.php
```

Result: Pint completed and fixed one test style issue.

## Requirement notes

- Provider scoping: command query is limited to `provider = klikqris`, non-null merchant refs, and pending/expired/failed statuses. Tests cover non-KlikQRIS and already-paid skips.
- Status-only reconciliation: command and Telegram refresh call `transactionStatus()` before `processReconciliationForPayment()`. Tests assert no `/qris/create` request for `outcome_unknown` recovery selection.
- Chunking: command uses `chunkById()` with bounded chunk option `1..1000`; invalid chunks fail clearly.
- Counts/output: command reports `processed`, `skipped`, and `failed`. Failure output references only local payment id and sanitized exception class/status text, never merchant refs, signatures, API keys, or provider URLs.
- Outages: per-payment failures increment `failed`, report safely, continue chunk iteration, and make the command exit failure after the summary.
- Telegram: KlikQRIS `order:{invoice}` uses its own `klikqris:status-refresh:{customer}:{payment}` rate-limit key, returns current detail safely on pending/outage/rate limit, and never calls Pakasir for KlikQRIS payments.
- Pakasir path: existing `refreshPendingPakasirPayment()` body is preserved and still used for non-KlikQRIS/default provider branch.

## Out of scope avoided

- No real credentials added or used.
- No production deploy, branch switch, or infrastructure action.
- No broad late-payment behavior beyond existing `OrderStateMachine` handling.
- No changes to Pakasir reconciliation behavior.

## Fix Round 1: outcome_unknown status-only SUCCESS recovery

Date: 2026-07-28
Fix commit: `e881eb8633c93c7eb88aaefc991c423ab30fb2b6` (`fix: recover outcome_unknown KlikQRIS success`)

### Finding fixed

A true KlikQRIS create `provider_create_state = outcome_unknown` payment can have no persisted `provider_payment_method`, `provider_total_payment`, or `provider_payload`. The reconciliation command correctly selected it and queried authoritative status only, but `KlikQrisCallbackProcessor::isEligiblePayment()` rejected it before SUCCESS could be applied.

### Files changed

- `src/tests/Feature/KlikQrisReconciliationTest.php`
  - Added `test_command_recovers_true_outcome_unknown_success_from_status_without_create_transaction()`.
  - The regression creates a pending KlikQRIS payment with `provider_create_state = outcome_unknown`, null provider method, null provider total, null provider payload, and null provider transaction token.
  - It fakes only `GET /qris/status/KLIK-UNKNOWN-SUCCESS`, asserts `/qris/create` is never called, expects `processed=1 skipped=0 failed=0`, verifies the payment/order become paid/processing, and verifies recovered provider method/total/payload evidence is persisted.
- `src/app/Services/KlikQris/KlikQrisCallbackProcessor.php`
  - After `KlikQrisTransaction::assertMatches()` and only for authoritative `SUCCESS`, persists recovered KlikQRIS status evidence for matching `outcome_unknown` rows missing provider evidence.
  - The recovery remains provider scoped to `klikqris`, status scoped to pending/expired/failed, requires `provider_create_state = outcome_unknown`, keeps order-id and base amount matching via `assertMatches()`, requires provider total to be at least the local amount, and then reuses the existing eligibility and `OrderLifecycle` paid path.

### TDD evidence

RED focused run before production fix:

```bash
cd src && php -d opcache.enable_cli=0 artisan test tests/Feature/KlikQrisReconciliationTest.php --filter=test_command_recovers_true_outcome_unknown_success_from_status_without_create_transaction
```

Observed failure:

```text
FAIL Tests\Feature\KlikQrisReconciliationTest
⨯ command recovers true outcome unknown success from status without c…
Output does not contain "processed=1 skipped=0 failed=0".
Tests: 1 failed (6 assertions)
```

GREEN focused run after fix:

```bash
cd src && php -d opcache.enable_cli=0 artisan test tests/Feature/KlikQrisReconciliationTest.php --filter=test_command_recovers_true_outcome_unknown_success_from_status_without_create_transaction
```

Result:

```text
PASS Tests\Feature\KlikQrisReconciliationTest
✓ command recovers true outcome unknown success from status without c…
Tests: 1 passed (13 assertions)
```

### Final regression evidence

Relevant KlikQRIS regressions after Pint:

```bash
cd src && php -d opcache.enable_cli=0 artisan test tests/Feature/KlikQrisReconciliationTest.php tests/Feature/Telegram/KlikQrisCheckoutTest.php tests/Feature/KlikQrisWebhookTest.php
```

Result: `26 passed (174 assertions)`.

Pakasir preservation regressions:

```bash
cd src && php -d opcache.enable_cli=0 artisan test tests/Feature/PakasirGatewayTest.php tests/Feature/PakasirWebhookTest.php
```

Result: `46 passed (507 assertions)`.

Formatting:

```bash
cd src && vendor/bin/pint app/Services/KlikQris/KlikQrisCallbackProcessor.php tests/Feature/KlikQrisReconciliationTest.php
```

Result: `PASS ... 2 files`.

### Concerns / follow-up

- No production deploy, production-mode change, or secret change was performed.
- The report update is committed separately from the code fix so this report can name the immutable fix commit SHA.
