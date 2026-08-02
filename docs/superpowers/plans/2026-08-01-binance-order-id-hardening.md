# Plan: Harden Binance Pay Order ID Verification

## Global Constraints

- Preserve the existing amount_unique flow during Release A.
- Keep buyer-facing wording as Binance Pay Order ID and I've Paid.
- Match the submitted Order ID exactly against provider orderId, with transactionId accepted only as a documented compatibility fallback.
- Never approve on an unbounded, truncated, stale, wrong-receiver, wrong-currency, wrong-amount, or expired transaction.
- Serialize payment submission and completion under the order and payment locks.
- Keep provider HTTP out of storefront status polling.
- Use the dedicated binance-verification queue with bounded retries and sanitized evidence.

## Task 1: Add the verification contract and schema

Files: payment migration(s), Payment model, enums/value objects, schema tests, model tests.

Write failing tests that assert both legacy amount_unique and new order_id records can coexist, all verification fields persist, submission_version is monotonic, the compatibility marker is nullable, and the old amount index remains available after Release A.

Run: php artisan test --filter=BinanceOrderIdSchemaTest

Implement the additive columns, casts, fillable fields, indexes, enum values, and idempotent down migration. Do not remove the legacy index in Release A.

Run: php artisan test --filter=BinanceOrderIdSchemaTest

Commit: feat: add Binance Order ID verification contract

## Task 2: Make checkout mode-aware without breaking legacy payments

Files: checkout service, manual payment settings, amount allocator, checkout tests.

Write failing tests for default amount_unique checkout, explicit order_id checkout, exact-amount display, and legacy settings without the new mode.

Run: php artisan test --filter=BinanceCheckoutModeTest

Restore the existing amount allocator for amount_unique, persist the selected verification mode and immutable expected amount, and keep old records readable.

Run: php artisan test --filter=BinanceCheckoutModeTest

Commit: feat: make Binance checkout verification mode-aware

## Task 3: Harden identifier, receiver, amount, currency, and time validation

Files: Binance transaction value object, verification service, config, unit tests.

Write failing tests for normalized ASCII Order ID input, malformed and overlong input, exact decimal amount comparison, currency mismatch, configured accountId or UID receiver matching, future transactions, pre-checkout transactions, and transactions after the original deadline.

Run: php artisan test --filter=BinanceVerificationContractTest

Implement strict input validation, configured receiver namespace checks, bounded transaction time checks, exact amount comparison, orderId-first matching, transactionId compatibility fallback, and sanitized evidence.

Run: php artisan test --filter=BinanceVerificationContractTest

Commit: feat: enforce Binance transaction verification contract

## Task 4: Implement bounded history lookup

Files: Binance Pay client, history reader, cache configuration, reader tests.

Write failing tests for exact cache keys, saturated-page window splitting, bounded page and time budgets, and the rule that a truncated response cannot produce a false negative.

Run: php artisan test --filter=BinanceHistoryReaderTest

Add a page-aware client and a bounded reader that recursively splits saturated windows, caches each exact window, and returns an inconclusive result when the configured budget is exhausted.

Run: php artisan test --filter=BinanceHistoryReaderTest

Commit: feat: make Binance history lookup bounded and complete

## Task 5: Add the shared asynchronous submission runner

Files: submission service, verification job, queue config, worker supervisor, concurrency tests.

Write failing tests for idempotent submission, per-payment attempt limits, stale submission_version rejection, dispatch-after-commit, retry classification, and concurrent submitters producing one terminal transition.

Run: php artisan test --filter=BinanceVerificationConcurrencyTest

Implement the shared service and unique job on binance-verification, lock order then payment, increment the version atomically, persist pending evidence, and retry only transient or inconclusive results.

Run: php artisan test --filter=BinanceVerificationConcurrencyTest

Commit: feat: serialize Binance verification submissions

## Task 6: Update Telegram Order ID UX

Files: Telegram intent handler, localized copy, Telegram feature tests.

Write failing tests for the exact English prompt, accepted input path, invalid-input response, pending response, terminal success response, and no duplicate provider calls under repeated taps.

Run: php artisan test --filter=TelegramBinanceOrderIdTest

Make I've Paid request the Order ID, validate locally, enqueue the shared runner, and keep the Telegram response pending until the asynchronous result is known.

Run: php artisan test --filter=TelegramBinanceOrderIdTest

Commit: feat: guide Telegram users through Binance Order ID verification

## Task 7: Add signed storefront Order ID submission

Files: signed invoice URLs, routes/controllers, storefront presenter/view, browser and feature tests.

Write failing tests for signed POST submission, CSRF and throttling, anonymous customer ownership, exact prompt copy, status polling without provider HTTP, and accessible touch targets.

Run: php artisan test --filter=StorefrontBinanceOrderIdTest

Add the signed form and endpoint, route it through the shared submission service, expose state-only status polling, and fix the modal close control to the tested 44px minimum.

Run: php artisan test --filter=StorefrontBinanceOrderIdTest

Commit: feat: add secure storefront Binance Order ID submission

## Task 8: Align reconciliation, lifecycle, and admin evidence

Files: auto verifier, expiry/release service, reconciliation command, admin views/tests.

Write failing tests for mode-specific matching, expiry releasing reservations, late evidence remaining manual-only, and redacted audit payloads.

Run: php artisan test --filter=BinanceLifecycleAndEvidenceTest

Update reconciliation and lifecycle handling so only a verified order_id payment can complete from Order ID submission, amount_unique remains compatible, expired reservations are released safely, and admin evidence is sufficient without secrets.

Run: php artisan test --filter=BinanceLifecycleAndEvidenceTest

Commit: fix: harden Binance lifecycle and audit evidence

## Task 9: Add health, marker, deployment, and CI gates

Files: health check, manual setting command/migration, deployment workflow/script, supervisor config, CI tests, browser assertions.

Write failing tests for the compatibility marker, dedicated queue health, supervisor process health, and deploy sequencing.

Run: php artisan test --filter=BinanceOperationalReadinessTest

Add the marker and live-canary readiness checks, queue and worker health, post-smoke marker write, and CI formatting/browser gates. Keep production deployment manual through Deploy production from main.

Run: php artisan test --filter=BinanceOperationalReadinessTest

Commit: chore: add Binance verification operational gates

## Task 10: Guarded activation migration

Files: activation migration, command, migration tests, deployment documentation.

Write failing tests for refusing activation on a non-empty database without the compatibility marker, preserving rollback safety, and switching new payments to order_id only after readiness.

Run: php artisan test --filter=BinanceOrderIdActivationTest

Implement the guarded migration that verifies the marker, recreates the payment index for order_id records only, and changes the default mode. Leave cleanup of legacy amount_unique records for a later release.

Run: php artisan test --filter=BinanceOrderIdActivationTest

Commit: feat: add guarded Binance Order ID activation

## Task 11: Full verification and handoff

Run Pint, focused suites, the full test suite, browser smoke, deploy harness, migration checks, and git diff checks. Review the final diff against the design and production acceptance gates. Record any intentionally deferred cleanup and remind operators to refresh Docker with the existing telegram-bot-prod compose project after deployment.

Commit: test: verify Binance Order ID production readiness
