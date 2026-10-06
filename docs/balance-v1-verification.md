# Balance V1 verification

Issue #559 is the verification gate for the Balance V1 implementation. It proves the behavior already owned by the Balance packages; it does not activate funding, qualify custody, or authorize production deployment.

Run `scripts/balance-v1-verification.sh` from the repository root. The command writes a timestamped, ignored evidence directory under `artifacts/balance-v1/`. Each report records the commit under test, PHP/Laravel/PHPUnit versions, fixture seed, provider distinction, database/process lane, Redis loss probe, command results, and limitations. The PostgreSQL gate uses a disposable PostgreSQL 16 database and fails on skipped tests. SQLite and synchronous queues remain behavior evidence only.

The test matrix is:

| Requirement | Evidence boundary |
| --- | --- |
| I1, I3, I11 exact accounts and currency separation | `CustomerUsdtBalanceLedgerTest`, `UsdtMicroAmountTest`, `UsdtBalanceCheckoutTest`, `UsdtOrderRefundTest` |
| I2 and I4 ledger ownership, append-only history, atomic projection | `CustomerUsdtBalanceLedgerTest`, `StoreBalanceLedgerTest`, `AdminBalanceAdjustmentTest`, PostgreSQL trigger and rollback cases |
| I5 replay and changed-payload conflicts | `ExternalMoneyReceiptClaimTest`, `UsdtFundingIntentTest`, `UsdtTopUpSettlementTest`, `AdminBalanceAdjustmentTest` |
| I6 global receipt ownership | `ExternalMoneyReceiptClaimTest`, `ExternalMoneyReceiptWriterCutoverTest`, `ExternalMoneyReceiptPostgresConcurrencyTest`, `AdminBalanceAdjustmentPostgresConcurrencyTest` |
| I7 immutable snapshots | `BscUsdtTopUpRailTest`, `BscUsdtRailPostgresConcurrencyTest`, `UsdtFundingIntentTest`, `UsdtTopUpSettlementTest` |
| I8 linked refunds and reversals | `AdminBalanceAdjustmentTest`, `AdminBalanceAdjustmentPostgresConcurrencyTest`, `UsdtOrderRefundTest`, `UsdtOrderRefundPostgresConcurrencyTest` |
| I9 durable recovery and delivery ambiguity | `UsdtTopUpSettlementTest`, `UsdtTopUpSettlementPostgresConcurrencyTest`, `TelegramOutboundEffectTest`, `StoreBalanceTopUpTest`; the process-death cutpoint kills a worker after the settlement lease commits and verifies one recovery effect |
| I10 drift, freeze, restore and reconciliation | `UsdtBalanceReconciliationTest`, `BalanceRestoreGateTest`, `UsdtReorgIncidentTest`, `BalanceOperationsAlertTest`, `BalanceOperationsPostgresConcurrencyTest` |
| I12 authentic money evidence | `BscUsdtTopUpRailTest`, `BscUsdtTransferIngestionTest`, `UsdtTopUpReviewTest`, `UsdtTopUpSettlementTest`, `UsdtReorgIncidentTest` |
| Authorization, IDOR, CSRF and privacy | `AdminBalanceAdjustmentDashboardTest`, `AdminUsdtTopUpReviewTest`, `AdminBalanceReportTest`, `CustomerBalanceWebTest`, `UsdtBalanceMenuTest`, `UsdtTopUpReviewTest`; the Balance Dashboard has a direct production-mode CSRF boundary test |
| PostgreSQL race coverage | Every feature file carrying `#[Group('postgres-release')]` is listed in `src/phpunit.postgres.xml`; the release-manifest guard prevents omissions |

The H0-v1 decision record is authoritative for the approval policy: this release uses one Owner with fresh per-action password confirmation, durable lockout, session/credential/fingerprint binding, and audit. The earlier draft maker-checker requirement is superseded by H0-v1 and must not be presented as passed evidence.

Context7 was used for the installed framework/testing boundaries: Laravel 13 database isolation, HTTP authorization assertions, and `afterCommit` queue dispatch; PHPUnit 12 fixture/process configuration; and Redis volatile transport behavior. The implementation follows the repository’s existing durable PostgreSQL work and Telegram Outbound Effect seams rather than treating Redis as a money authority.

The verification uses synthetic RPC/HTTP fixtures. A green run proves code and database behavior in disposable environments. It does not prove live provider availability, receiver custody, legal permission, treasury backing, or production readiness; those remain H1/H2 gates.
