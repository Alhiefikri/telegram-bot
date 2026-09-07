# Sakurupiah release evidence — issue #416

Evidence date: 2026-09-05 (UTC)

## Production activation status

**NOT ACTIVATED — this evidence document contains no production credentials.**

This document records repository and non-production verification evidence. It
is not a production authorization and it never contains API IDs or keys.
Production `services.sakurupiah.production_enabled` follows the complete
production credential pair at application startup. After readiness passes, an
Owner must select Sakurupiah for new checkout in the Admin Payment Provider
Switch.

## Automated verification

| Gate | Exact command | Result |
| --- | --- | --- |
| Focused provider, regression, infrastructure, and documentation checks | `cd src && php artisan test --filter='(CheckoutPaymentSnapshotCharacterizationTest|Sakurupiah(Webhook|Reconciliation|ProviderSwitch|CheckoutPaymentPreparation)Test|FoundationInfrastructureTest|PaymentDocumentationTest|PaymentProviderSwitchTest|PakasirWebhookTest)' --colors=never` | **PASS** — 129 tests, 1,318 assertions. |
| Sakurupiah security boundary and query-budget checks | `cd src && php artisan test --filter='(SakurupiahWebhookTest|SakurupiahReconciliationTest|SakurupiahGatewayTest|SakurupiahSignatureTest|SakurupiahPayloadSanitizerTest|test_sakurupiah_sales_rows_eager_load_relations_without_n_plus_one|test_sakurupiah_delivery_loads_the_checkout_graph_without_relation_n_plus_one)' --colors=never` | **PASS** — 57 tests, 323 assertions. |
| Composer dependency security audit | `cd src && composer audit --no-interaction` | **PASS** — no security vulnerability advisories. |
| Customer browser smoke | `cd src && npx playwright test tests/Browser/admin-dashboard-responsive.spec.js -g "customer checkout"` | **PASS** — 7 tests. |
| Full SQLite suite, final successful run | `cd src && composer test` | **PASS** — 2,172 tests, 2,121 passed, 51 skipped, 15,608 assertions. |
| Full SQLite suite, order-sensitive reruns | `cd src && composer test` | **INTERMITTENT FAILURES RECORDED** — earlier runs failed in unrelated `CatalogSearchTest` because a random username contained `there`, and `BscReadinessTest` with a stale-preflight message. The isolated failing tests and `BscReadinessTest` file passed when rerun; the final full run above passed. |
| PostgreSQL concurrency deploy gate, isolated PostgreSQL 16 | `CONCURRENCY_GATE_ARTIFACT_DIR=/tmp/telegram-bot-concurrency-gate-416-final-3 CONCURRENCY_DB_HOST=127.0.0.1 CONCURRENCY_DB_PORT=55432 CONCURRENCY_DB_ADMIN_USERNAME=postgres CONCURRENCY_DB_ADMIN_PASSWORD=postgres composer test:concurrency-gate` | **PASS** — PHPUnit output: 95 tests, 766 assertions; gate report: 77 tests, 620 assertions, 0 skips/failures/errors; isolated database dropped. |
| Release configuration guard scripts | `./scripts/test-deploy-production-release.sh && ./scripts/test-redact-release-diagnostics.sh && ./scripts/test-check-migration-prefixes.sh && ./scripts/test-safe-compose-config.sh` | **PASS** — all release, redaction, migration-prefix, and safe-compose checks passed. |
| PostgreSQL concurrency deploy gate, default local port preflight | `cd src && CONCURRENCY_GATE_ARTIFACT_DIR=/tmp/telegram-bot-concurrency-gate composer test:concurrency-gate` | **BLOCKED** — this default-port attempt received connection refused at `127.0.0.1:5432`; it produced no race result. The isolated PostgreSQL 16 rerun above is the passing gate. |
| PostgreSQL gate artifact preflight | `cd src && composer test:concurrency-gate` | **BLOCKED** before execution because the workspace `artifacts/` directory was not writable. The writable `/tmp` rerun above reached the PostgreSQL connection check. |
| PostgreSQL release suite registration | `cd src && ./vendor/bin/phpunit --configuration=phpunit.postgres.xml --testsuite='PostgreSQL release integration' --group=postgres-release --list-tests` | **PASS** — 12 tests are registered, including Sakurupiah create, duplicate callback, and callback/reconciliation/expiry race tests. This is registration evidence only, not an execution pass. |

The CI release path remains the authoritative PostgreSQL evidence path. It runs the `phpunit.postgres.xml` production-critical suite with `--group=postgres-release --fail-on-skipped`, then `scripts/concurrency-deploy-gate.sh` with an isolated PostgreSQL database and `--fail-on-skipped`.

## Regression coverage mapped to issue #416

- `CheckoutPaymentSnapshotCharacterizationTest` characterizes Manual QRIS, KlikQRIS, and Sakurupiah QRIS as shared IDR checkout rails. The same suite covers Binance Pay ID and BSC payment snapshots.
- Existing `PakasirWebhookTest` coverage remains the historical compatibility characterization. New checkout provider selection continues to reject Pakasir; `SakurupiahProviderSwitchTest::test_switching_away_from_sakurupiah_keeps_existing_payment_provenance` verifies that switching the active provider does not rewrite an existing Sakurupiah Payment.
- `SakurupiahWebhookTest::test_paid_callback_duplicate_materializes_each_fulfillment_effect_once` verifies one paid transition produces one sale, one credential allocation, one receipt effect, one Admin paid effect, and one Sales Proof effect under duplicate callbacks.
- `SakurupiahReconciliationTest::test_command_bounds_chunk_and_uses_deterministic_ordering_without_n_plus_one`, `SakurupiahCheckoutTest::test_sakurupiah_delivery_loads_the_checkout_graph_without_relation_n_plus_one`, and `AdminDashboardOverviewTest::test_sakurupiah_sales_rows_eager_load_relations_without_n_plus_one` assert bounded reconciliation, eager-loaded checkout delivery relations, and eager-loaded Admin sales relations without per-row queries.
- `ProductionConcurrencyTest::test_concurrent_duplicate_checkout_key_reuses_one_order_payment_reservation_and_effect` adds the PostgreSQL process race for one checkout key.
- `SakurupiahInvoiceLifecyclePostgresConcurrencyTest` now registers in both the `concurrency` and `postgres-release` groups and covers concurrent invoice creation, duplicate paid callbacks, and callback/reconciliation/expiry contention. The current commit passed the isolated PostgreSQL 16 process gate above; the default local `127.0.0.1:5432` prerequisite remained unavailable.
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
