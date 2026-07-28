# Task 5 Report: KlikQRIS native QRIS idempotent creation

## Scope

Implemented Task 5 only in `/home/alhiefikri/telegram-bot/.worktrees/klikqris-sandbox`.

Added:

- `src/app/Services/KlikQris/KlikQrisNativeQrisService.php`
- `src/app/Services/KlikQris/KlikQrisPaymentEventLogger.php`
- `src/tests/Feature/KlikQrisNativeQrisTest.php`

Modified:

- `src/app/Services/KlikQris/KlikQrisTransaction.php`
- `src/app/Models/Payment.php`
- `src/database/migrations/2026_07_28_000001_add_provider_transaction_token_to_payments.php`

No production/admin/routing/Telegram QR delivery changes were made. No real secrets were added. Pakasir code paths were not changed.

## TDD evidence

### RED 1

Command:

```bash
cd src && php artisan test tests/Feature/KlikQrisNativeQrisTest.php
```

Result: failed as expected because `App\Services\KlikQris\KlikQrisNativeQrisService` did not exist.

### GREEN 1

Implemented `KlikQrisNativeQrisService`, `KlikQrisPaymentEventLogger`, persisted reconstruction in `KlikQrisTransaction`, and nested sanitized payload URL lookup in `Payment`.

Focused command:

```bash
cd src && php artisan test tests/Feature/KlikQrisNativeQrisTest.php
```

Result after iterations: 7 passed, 1 skipped, 35 assertions.

### RED 2

Added a regression for definitive create validation failures not being mislabeled as `outcome_unknown`.

Command:

```bash
cd src && php artisan test tests/Feature/KlikQrisNativeQrisTest.php --filter=definitive_create_validation_failure
```

Result: failed because the payment state was `outcome_unknown`.

### GREEN 2

Added conservative classification for definitive create failures: readiness/config, validation/parse, and definite 4xx failures become `create_failed`; ambiguous post-send/provider/connection failures remain `outcome_unknown` and are recovered by status before any future create.

Command:

```bash
cd src && php artisan test tests/Feature/KlikQrisNativeQrisTest.php
```

Result: 7 passed, 1 skipped, 35 assertions.

## Behavior covered

- `ensureCreated()` uses the required cache lock key/TTL/block: `klikqris:native-qris:create:{payment_id}`, `30`, `block(10)`.
- Inside the lock, the payment is refreshed and persisted payable QRIS returns without provider request.
- Existing state/payload recovers through `transactionStatus()` before create.
- Creation is marked `creating` transactionally before the external request.
- The external create request is not made inside the database transaction.
- Ambiguous create failure stores `outcome_unknown` with only the sanitized provider/connection exception class.
- A subsequent call after `outcome_unknown` calls status for the same `merchant_ref`, persists the recovered transaction, and does not blind-create again.
- Persistence stores provider total, fee as `total-base`, method `qris`, sanitized payload excluding `signature` and `qris_image`, encrypted transaction token, provider expiry, and state.
- Order/payment deadlines are shortened when provider expiry is earlier and never extended when provider expiry is later.
- Exactly one sanitized `ProviderCreate` event is logged for a persisted create/recovery.
- `KlikQrisTransaction::fromPersistedPayment()` reconstructs from sanitized payload plus encrypted token, does not require base64 QR data, rejects wrong provider, and fails closed on nonpayable/inconsistent data.
- Invalid final amount remains nonpayable.
- Pakasir regression tests confirm existing Pakasir behavior remains intact.

## PostgreSQL concurrency evidence / skip reason

Added `test_postgres_concurrent_ensure_created_observes_one_provider_create_effect()` under `@group postgres`, but it is explicitly skipped with this blocker:

> Requires the repository PostgreSQL multi-process harness; Task 5 report documents this blocker.

Reason: this repository test harness in the current worktree has no existing reusable PostgreSQL multi-worker HTTP fake/provider-effect harness for this new service. I did not fake concurrency with only sequential calls. Sequential idempotency and one-effect recovery are covered with request counts and event counts. A meaningful PG test should run two independent PHP workers/processes against the same PostgreSQL database with a provider fake that records one durable create effect across processes.

## Verification

Final command:

```bash
cd src && php artisan test tests/Feature/KlikQrisNativeQrisTest.php tests/Feature/KlikQrisGatewayTest.php tests/Unit/Services/KlikQris/KlikQrisTransactionTest.php tests/Unit/Services/KlikQris/KlikQrisProviderReadinessTest.php tests/Feature/PakasirGatewayTest.php tests/Feature/PakasirWebhookTest.php && ./vendor/bin/pint --test app/Services/KlikQris/KlikQrisNativeQrisService.php app/Services/KlikQris/KlikQrisPaymentEventLogger.php app/Services/KlikQris/KlikQrisTransaction.php app/Models/Payment.php database/migrations/2026_07_28_000001_add_provider_transaction_token_to_payments.php tests/Feature/KlikQrisNativeQrisTest.php
```

Result:

- Tests: 105 passed, 1 skipped, 691 assertions.
- Pint: PASS, 6 files.

## Self-review

- The external create request is outside DB transactions; persistence and event logging happen in one DB transaction after provider response.
- Recovery uses status before create whenever state/payload exists.
- Secrets/signature/base64 QR image are not persisted in payload or event payload; the signature is stored only in the encrypted token cast.
- The Task 5 test has a test-only schema guard for `provider_transaction_token` because PHPUnit `:memory:` migration resolution in this worktree did not see the additive migration consistently, while explicit `migrate:fresh --env=testing` did. The production schema path remains the additive migration.

## Concerns / follow-ups

- Implement a real PostgreSQL multi-process concurrency harness for KlikQRIS provider fakes, then replace the current skipped `@group postgres` test with a durable one-effect assertion.
- Consider introducing typed create-failure exceptions in the KlikQRIS client so definitive/uncertain classification is not message-based.

## Commit

Final commit message: `feat: create KlikQRIS transactions idempotently`.

Note: the exact final SHA is reported in the final assistant response because embedding a commit's own final SHA inside a file committed by that same commit is self-referential and changes the SHA.
