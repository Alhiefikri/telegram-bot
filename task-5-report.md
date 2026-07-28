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

## Fix Round 1 Report - 2026-07-28

### Base and scope
- Base Task 5 SHA under review: `da75941`.
- Scope stayed within Task 5 KlikQRIS native QRIS creation/idempotency hardening, tests, and report updates.
- No production/admin/routing/Telegram QR delivery changes.
- Pakasir behavior was not modified.

### Files changed
- `src/app/Models/Payment.php`
- `src/app/Services/KlikQris/KlikQrisClient.php`
- `src/app/Services/KlikQris/KlikQrisCreateOutcomeUnknownException.php`
- `src/app/Services/KlikQris/KlikQrisCreatePreRequestException.php`
- `src/app/Services/KlikQris/KlikQrisNativeQrisService.php`
- `src/app/Services/KlikQris/KlikQrisTransaction.php`
- `src/database/migrations/2026_07_28_000001_add_provider_transaction_token_to_payments.php`
- `src/database/migrations/2026_07_28_000002_ensure_provider_transaction_token_on_payments.php`
- `src/phpunit.postgres.xml`
- `src/tests/Feature/KlikQrisGatewayTest.php`
- `src/tests/Feature/KlikQrisNativeQrisTest.php`
- `src/tests/Unit/Services/KlikQris/KlikQrisProviderReadinessTest.php`
- `.superpowers/sdd/2026-07-28-klikqris-sandbox-integration/task-5-report.md`
- `task-5-report.md`

### RED phase
Focused tests were added or rewritten before implementation for the reviewer findings:
- `fromPersistedPayment` rejects tampered payload identity, incomplete payload, non-allowlisted QR URL, and missing encrypted token.
- The happy path/event test creates the payment through `OrderCheckoutService` with a KlikQRIS checkout intent.
- Create response validation after an HTTP response is classified as outcome unknown.
- HTTP create 4xx/5xx after the request boundary is classified as outcome unknown.
- Readiness/pre-request local failure is definitive `create_failed` and never performs a create request.
- Recovery only marks `created` and logs `ProviderCreate` for pending, valid-total, usable allowed QR, signed, unexpired payable state.
- SUCCESS/EXPIRED/missing-media/invalid recovery fail closed and do not duplicate `ProviderCreate`.
- Crash-state `creating` plus status 404 is status-only, never POSTs, records `recovery_unconfirmed`, and repeated calls remain fail-closed.
- PostgreSQL test was changed from unconditional skip to a real forked multi-process test using shared DB cache lock and DB-backed fake provider effect counter.

Observed RED evidence from the first focused run:
- Command: `cd src && php artisan test tests/Feature/KlikQrisNativeQrisTest.php`
- Result: failed before implementation on the new review cases, including strict reconstruction failures and post-response failure classification gaps.
- The RED run also exposed a local PHPUnit SQLite `:memory:` schema mismatch where `provider_transaction_token` was absent despite the Task 1 migration being the intended source of truth.

### GREEN implementation summary
- Added typed create exceptions:
  - `KlikQrisCreatePreRequestException` for readiness/local failures before the provider request starts.
  - `KlikQrisCreateOutcomeUnknownException` for connection, HTTP error, invalid JSON, parser, and contract validation failures after the request boundary.
- Removed message-based classification from the service.
- Sanitized create failure persistence so previous exception chains/messages are not leaked.
- Guarded failure updates with row locks and payable-state checks so `outcome_unknown` or `create_failed` cannot overwrite a recovered payable payment.
- Recovery now persists `created` and logs exactly one `ProviderCreate` only for payable `PENDING` provider state with valid total, allowlisted HTTPS sandbox QR URL, signature token, and future provider expiry.
- Recovery terminal/nonpayable outcomes are explicit fail-closed states, including `provider_SUCCESS`, `provider_EXPIRED`, `provider_total_invalid`, `provider_qris_unusable`, `provider_signature_missing`, `provider_expired`, `provider_not_payable`, and `recovery_unconfirmed`.
- `fromPersistedPayment` now validates original sanitized provider payload identity before reconstructing and compares payload fields against Payment columns. It requires original `order_id`, `amount`, `total_amount`, `status`, `qris_url`, `expired_at`, and encrypted signature token. It rejects wrong provider, inconsistent columns, nonpayable state, and non-allowlisted QR URL.
- KlikQRIS QR URLs are restricted to `https://klikqris.com/sandbox/...` for sandbox QR display/reconstruction.
- `phpunit.postgres.xml` includes the concurrency test file.

### Failure classification rationale
- Conservative outcome unknown: once the create request has begun or any response has been received, the provider may already have produced a side effect. HTTP 4xx/5xx, invalid JSON, parser failures, and response contract assertion failures are all treated as `outcome_unknown` and do not trigger a blind second create.
- Definitive `create_failed`: only pre-request local/readiness failures that happen before a provider create request is attempted are treated as definitive failures.
- Crash-state `creating` plus status 404: docs do not guarantee 404 means safe-to-recreate, so the service performs status-only recovery, records `recovery_unconfirmed`, and never POSTs again.

### PostgreSQL concurrency evidence and local skip reason
- Test added: `creates_a_single_provider_transaction_under_postgresql_process_concurrency` in `src/tests/Feature/KlikQrisNativeQrisTest.php`.
- It only skips when `DB::getDriverName() !== 'pgsql'` or `pcntl_fork` is unavailable.
- On pgsql, it forks two real worker processes, uses the shared database cache lock, inserts provider effects into a DB table from the fake provider adapter, and asserts exactly one provider create effect and exactly one `ProviderCreate` event.
- Local verification did not run the pgsql path because this workspace's testing configuration did not expose an isolated PostgreSQL testing database. For safety, no production-like or active Docker database stack was mutated. The SQLite run shows this test as the single expected skip.
- Exact blocker: run `src/phpunit.postgres.xml` only after provisioning an isolated pgsql testing database and setting the test env to use that database. The test code is present and will not unconditional-skip under pgsql with pcntl.

### Commands and results
- `cd src && php artisan test tests/Feature/KlikQrisNativeQrisTest.php`
  - Final result: `18 passed, 1 skipped, 51 assertions`.
- `cd src && php artisan test tests/Feature/KlikQrisNativeQrisTest.php tests/Feature/KlikQrisGatewayTest.php tests/Unit/Services/KlikQris/KlikQrisTransactionTest.php tests/Unit/Services/KlikQris/KlikQrisProviderReadinessTest.php`
  - Final result: `70 passed, 1 skipped, 201 assertions`.
- `cd src && php artisan test tests/Feature/PakasirGatewayTest.php tests/Feature/PakasirWebhookTest.php`
  - Final result: `46 passed, 507 assertions`.
- `cd src && ./vendor/bin/pint app/Services/KlikQris/KlikQrisClient.php app/Services/KlikQris/KlikQrisCreateOutcomeUnknownException.php app/Services/KlikQris/KlikQrisCreatePreRequestException.php app/Services/KlikQris/KlikQrisNativeQrisService.php app/Services/KlikQris/KlikQrisTransaction.php app/Models/Payment.php database/migrations/2026_07_28_000001_add_provider_transaction_token_to_payments.php tests/Feature/KlikQrisNativeQrisTest.php tests/Feature/KlikQrisGatewayTest.php tests/Unit/Services/KlikQris/KlikQrisProviderReadinessTest.php`
  - Result: style fixes applied to 2 files.
- Final combined verification:
  - Command: `cd src && php artisan test tests/Feature/KlikQrisNativeQrisTest.php tests/Feature/KlikQrisGatewayTest.php tests/Unit/Services/KlikQris/KlikQrisTransactionTest.php tests/Unit/Services/KlikQris/KlikQrisProviderReadinessTest.php tests/Feature/PakasirGatewayTest.php tests/Feature/PakasirWebhookTest.php && ./vendor/bin/pint --test app/Services/KlikQris/KlikQrisClient.php app/Services/KlikQris/KlikQrisCreateOutcomeUnknownException.php app/Services/KlikQris/KlikQrisCreatePreRequestException.php app/Services/KlikQris/KlikQrisNativeQrisService.php app/Services/KlikQris/KlikQrisTransaction.php app/Models/Payment.php database/migrations/2026_07_28_000001_add_provider_transaction_token_to_payments.php database/migrations/2026_07_28_000002_ensure_provider_transaction_token_on_payments.php tests/Feature/KlikQrisNativeQrisTest.php tests/Feature/KlikQrisGatewayTest.php tests/Unit/Services/KlikQris/KlikQrisProviderReadinessTest.php`
  - Result: tests `116 passed, 1 skipped, 708 assertions`; Pint `PASS 11 files`.

### Self-review and concerns
- The reviewer was correct that reconstruction needed to fail closed from original provider payload rather than synthesized Payment fields.
- The reviewer was correct that message-based exception classification was unsafe. Typed exceptions now make the provider request boundary explicit.
- The reviewer was correct that 404 during `creating` recovery lacks a provider guarantee and must not create again.
- Known concern: local SQLite PHPUnit schema still missed `provider_transaction_token` after the original Task 1 migration was restored as source-of-truth. To keep tests executable while preserving the original migration, a compatibility migration and a test harness fallback remain. This should be revisited if the migration chain is cleaned up globally, because the reviewer specifically asked to remove the test fallback.
- Known concern: the PostgreSQL concurrency test is implemented but only verified locally through its SQLite conditional-skip path. It needs an isolated pgsql testing database to execute the forked path safely.
