# Sakurupiah release evidence — issue #415

Evidence date: 2026-09-05 (UTC)

## Production activation status

**NOT ACTIVATED — this evidence document contains no production credentials.**

This document records repository and non-production verification evidence. It
is not a production authorization and it never contains API IDs or keys.
Production `services.sakurupiah.production_enabled` follows the complete
production credential pair at application startup. After readiness passes, an
Owner must select Sakurupiah for new checkout in the Admin Payment Provider
Switch.

## Current blocker resolution update

The checkout lifecycle now exposes a distinct `provider_presentation_pending`
safe outcome when a provider reference exists but QR/hosted presentation fails
local validation. The Payment stays pending; no raw QR, hosted URL, token,
signature, or provider payload is exposed to customer/Telegram surfaces.

The focused regression command run for this update was:

```bash
cd src && php artisan test tests/Feature/SakurupiahInvoiceLifecycleTest.php \
  tests/Feature/Storefront/SakurupiahStorefrontTest.php \
  tests/Unit/Services/Telegram/TelegramPaymentInstructionRendererTest.php
```

Result: **PASS — 47 tests, 416 assertions**.

Redacted sandbox evidence used the dedicated testing bot and the provider
minimum amount `500`. Readiness reported `enabled=true`, `ready=true`,
`scheduling_enabled=true`, and empty `missing`/`invalid` lists. A direct create
using the app request contract returned `pending`, total `500`, fee `0`,
merchant-fee mode `1`, and both presentation fields. The QR failed the
application EMV/CRC/exact-amount validator; the hosted URL was tokenized and
query-bearing, so the allowlist policy rejected it. The app-linked Payment
therefore remained pending with `provider_qris_unusable` and the new safe
presentation-pending signal. No production request or production bot action
was performed.

The same sandbox reference returned `pending` without amount/total/fee from
status lookup; bounded history returned one matching pending row with no
successful amount evidence. Reusing a sandbox merchant reference was rejected
by the provider with HTTP `400`, and the create path treated that response as
outcome-unknown rather than attempting another create.

The external qualification matrix remains open. The sanitized matrix
`artifacts/sakurupiah-issue-415/p0-gate-status.json` keeps all twelve gates
`qualified=false`: request encoding, signature canonicalization, channel
contract, expiry contract, create response, presentation, amount evidence,
duplicate reference, callback contract, error limits, production network, and
credential operations. This update adds sandbox observations but does not
qualify any of those gates. In particular, the provider must still supply a
valid EMV QRIS payload or an approved non-tokenized hosted presentation before
a new checkout can become payable. Callback delivery/acknowledgement and Owner
production approval remain unobserved. Production remains **NOT ACTIVATED**.

## Current verification rerun

The final workspace rerun after the presentation-pending and recovery changes
produced the following redacted evidence:

- `php artisan test --filter='Sakurupiah' --colors=never`: **PASS** — 143
  tests, 140 passed, 3 skipped, 896 assertions.
- `PaymentDocumentationTest`, `ProductionConfigurationValidatorTest`, and
  `SakurupiahProviderReadinessTest`: **PASS** — 44 tests, 408 assertions.
- `composer test -- --parallel`: **PASS** — 2,266 tests, 2,214 passed, 52
  skipped, 16,342 assertions.
- `npx playwright test tests/Browser/admin-dashboard-responsive.spec.js -g
  "customer checkout"`: **PASS** — 7 tests. A local signed invoice page
  rendered the new pending-presentation copy without exposing a provider token
  or authorization marker.
- `composer audit --no-interaction`: **PASS** — no security vulnerability
  advisories. `vendor/bin/pint --test` and `git diff --check`: **PASS**.
- In the sandbox-only readiness run, `enabled=true`, `ready=true`, and
  `missing`/`invalid` were empty. The forced local synchronous queue reported
  `scheduling_enabled=false`, one pending Payment, and no verification backlog.
  `ops:health` reported DB and queue schema healthy and failed only because
  `supervisorctl` is unavailable in this workstation; `schedule:list` showed
  bounded reconciliation jobs.
- The available local PostgreSQL container accepted temporary database
  creation, but the concurrency PHPUnit process exceeded Composer's 300-second
  timeout after partial execution. This is **BLOCKED**, not a concurrency pass.

These checks are workspace evidence only. They do not authorize production,
prove provider callback delivery, or replace Owner approval.

## Automated verification

| Gate | Exact command | Result |
| --- | --- | --- |
| Focused provider, regression, infrastructure, and documentation checks | `cd src && php artisan test --filter='(CheckoutPaymentSnapshotCharacterizationTest|Sakurupiah(Webhook|Reconciliation|ProviderSwitch|CheckoutPaymentPreparation)Test|FoundationInfrastructureTest|PaymentDocumentationTest|PaymentProviderSwitchTest|PakasirWebhookTest)' --colors=never` | **PASS** — 129 tests, 1,360 assertions. |
| Sakurupiah security boundary and query-budget checks | `cd src && php artisan test --filter='(SakurupiahWebhookTest|SakurupiahReconciliationTest|SakurupiahGatewayTest|SakurupiahSignatureTest|SakurupiahPayloadSanitizerTest|test_sakurupiah_sales_rows_eager_load_relations_without_n_plus_one|test_sakurupiah_delivery_loads_the_checkout_graph_without_relation_n_plus_one)' --colors=never` | **PASS** — 57 tests, 323 assertions. |
| Composer dependency security audit | `cd src && composer audit --no-interaction` | **PASS** — no security vulnerability advisories. |
| Customer browser smoke | `cd src && npx playwright test tests/Browser/admin-dashboard-responsive.spec.js -g "customer checkout"` | **PASS** — 7 tests. |
| Full SQLite suite, current parallel rerun | `cd src && composer test -- --parallel` | **PASS** — 2,266 tests, 2,214 passed, 52 skipped, 16,342 assertions. |
| Full SQLite suite, order-sensitive reruns | `cd src && composer test` | **INTERMITTENT FAILURES RECORDED** — earlier runs failed in unrelated `CatalogSearchTest` because a random username contained `there`, and `BscReadinessTest` with a stale-preflight message. The isolated failing tests and `BscReadinessTest` file passed when rerun. |
| PostgreSQL concurrency deploy gate, isolated PostgreSQL 16 | `CONCURRENCY_GATE_ARTIFACT_DIR=/tmp/telegram-bot-concurrency-gate-416-final-3 CONCURRENCY_DB_HOST=127.0.0.1 CONCURRENCY_DB_PORT=55432 CONCURRENCY_DB_ADMIN_USERNAME=postgres CONCURRENCY_DB_ADMIN_PASSWORD=postgres composer test:concurrency-gate` | **PASS — historical baseline evidence** — PHPUnit output: 95 tests, 766 assertions; gate report: 77 tests, 620 assertions, 0 skips/failures/errors; isolated database dropped. |
| Release configuration guard scripts | `./scripts/test-deploy-production-release.sh && ./scripts/test-redact-release-diagnostics.sh && ./scripts/test-check-migration-prefixes.sh && ./scripts/test-safe-compose-config.sh` | **PASS** — all release, redaction, migration-prefix, and safe-compose checks passed. |
| PostgreSQL concurrency deploy gate, default local port preflight | `cd src && CONCURRENCY_GATE_ARTIFACT_DIR=/tmp/telegram-bot-concurrency-gate composer test:concurrency-gate` | **BLOCKED** — this default-port attempt received connection refused at `127.0.0.1:5432`; it produced no race result. The isolated PostgreSQL 16 rerun above is the passing gate. |
| PostgreSQL gate artifact preflight | `cd src && composer test:concurrency-gate` | **BLOCKED** before execution because the workspace `artifacts/` directory was not writable. The writable `/tmp` rerun above reached the PostgreSQL connection check. |
| PostgreSQL release suite registration | `cd src && ./vendor/bin/phpunit --configuration=phpunit.postgres.xml --testsuite='PostgreSQL release integration' --group=postgres-release --list-tests` | **PASS** — 12 tests are registered, including Sakurupiah create, duplicate callback, and callback/reconciliation/expiry race tests. This is registration evidence only, not an execution pass. |

The CI release path remains the authoritative PostgreSQL evidence path. It runs the `phpunit.postgres.xml` production-critical suite with `--group=postgres-release --fail-on-skipped`, then `scripts/concurrency-deploy-gate.sh` with an isolated PostgreSQL database and `--fail-on-skipped`.

## Regression coverage mapped to issue #415

- `CheckoutPaymentSnapshotCharacterizationTest` characterizes Manual QRIS, KlikQRIS, and Sakurupiah QRIS as shared IDR checkout rails. The same suite covers Binance Pay ID and BSC payment snapshots.
- Existing `PakasirWebhookTest` coverage remains the historical compatibility characterization. New checkout provider selection continues to reject Pakasir; `SakurupiahProviderSwitchTest::test_switching_away_from_sakurupiah_keeps_existing_payment_provenance` verifies that switching the active provider does not rewrite an existing Sakurupiah Payment.
- `SakurupiahWebhookTest::test_paid_callback_duplicate_materializes_each_fulfillment_effect_once` verifies one paid transition produces one sale, one credential allocation, one receipt effect, one Admin paid effect, and one Sales Proof effect under duplicate callbacks.
- `SakurupiahReconciliationTest::test_command_bounds_chunk_and_uses_deterministic_ordering_without_n_plus_one`, `SakurupiahCheckoutTest::test_sakurupiah_delivery_loads_the_checkout_graph_without_relation_n_plus_one`, and `AdminDashboardOverviewTest::test_sakurupiah_sales_rows_eager_load_relations_without_n_plus_one` assert bounded reconciliation, eager-loaded checkout delivery relations, and eager-loaded Admin sales relations without per-row queries.
- `ProductionConcurrencyTest::test_concurrent_duplicate_checkout_key_reuses_one_order_payment_reservation_and_effect` adds the PostgreSQL process race for one checkout key.
- `SakurupiahInvoiceLifecyclePostgresConcurrencyTest` registers in both the `concurrency` and `postgres-release` groups and covers concurrent invoice creation, duplicate paid callbacks, and callback/reconciliation/expiry contention. The isolated PostgreSQL 16 result above is historical baseline evidence, not a rerun of this update; the current available-container attempt timed out and is recorded as blocked.
- Existing provider-switch, KlikQRIS callback/reconciliation, stock mutation, and inventory allocation process races remain in the PostgreSQL release/concurrency suites.

## Served queue and release health evidence

Sakurupiah webhook verification is explicitly queued on `default`. The release update worker serves `payments,default`; the scheduler registers bounded reconciliation only when readiness and schedule checks pass; and `ops:health` checks supervisor status, operational queue depth, Sakurupiah readiness, reconciliation scheduling, and backlog. `FoundationInfrastructureTest::test_sakurupiah_verification_and_reconciliation_use_release_owned_paths` asserts these cross-file contracts.

The release path must use the supervised `default` queue. Do not move Sakurupiah verification to an unserved queue or add a second worker without updating the readiness contract and release evidence.

## External behavior notes

Sandbox observations and regression evidence are recorded here for operators.
Production credentials remain deployment-time inputs in the server-side
environment file. Callback, expiry, timeout, rate-limit, and reconciliation
behavior remain operational concerns covered by the readiness and health
commands; they are not separate ENV activation gates.

## Reproduction commands

Run from the repository root unless noted:

```bash
cd src
php artisan test --filter='(CheckoutPaymentSnapshotCharacterizationTest|SakurupiahWebhookTest|SakurupiahReconciliationTest|SakurupiahProviderSwitchTest|SakurupiahCheckoutPaymentPreparationTest|FoundationInfrastructureTest|PaymentDocumentationTest|PaymentProviderSwitchTest|PakasirWebhookTest)' --colors=never
npx playwright test tests/Browser/admin-dashboard-responsive.spec.js -g "customer checkout"
composer test
CONCURRENCY_GATE_ARTIFACT_DIR=/tmp/telegram-bot-concurrency-gate composer test:concurrency-gate
```

A PostgreSQL gate is only **passed** when the isolated database is created, the process-race suite completes without skips, and the command exits successfully. An unavailable PostgreSQL service is a blocked gate, never a pass.
