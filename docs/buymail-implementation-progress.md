# BuyMail implementation and verification

User scope: automatic purchase only after verified buyer payment; Dashboard-managed encrypted API key; per-account Gmail claims; buyer chooses refund or replacement after verification; Indonesian/English support/refund copy; broad meaningful tests and financial concurrency protection. Production deployment and live spend are separate and were not performed.

## Requirement audit

| Requirement | Implementation | Authoritative proof |
| --- | --- | --- |
| API key/secret managed in Dashboard without ENV rebuild | SupplierAdminRequest, SupplierManager, encrypted Supplier casts and connection form | SupplierAdminTest and BuyMailAccountIdentityTest: encryption, redaction, blank preservation, recent password, same-account rotation and holds |
| Buy only for a real Paid buyer | SupplierFulfillmentService claim checks Order + Paid Payment + paid_at, network after commit | BuyMailFulfillmentTest: pending/unverified never sends; verified full/partial delivery; real dedicated queue worker |
| No duplicate purchase on retry/crash | Durable intent, reference, idempotency, lease/token, exact GET recovery and credential fingerprints | BuyMailClientTest; original/replacement recovery integration; shared purchase-claim PostgreSQL worker race (scope below) |
| Supplier stock/balance/pricing | IDR profile adapter, private Gmail mapping, commitments/margins, freshness, quantity cap, daily reset/refunds-disabled gate | BuyMail client/availability/identity/fulfillment tests, existing supplier regressions |
| Partial delivery and missing-unit refund | Actual debit/quantity, outstanding-only attempts, early unit access, MissingFulfillmentRefundService | Full/partial/missing Store Balance/external/full-missing integration; duplicate missing-refund PostgreSQL race |
| Gmail proof and deadline | Private encrypted original JPEG/PNG, MIME/image decode/pixel/size limit, unit ownership/deadline | RefundProofTest; WarrantyClaimTest; Web/Admin ownership and private download tests |
| Pre-deadline buyer alert | Once-per-unit Telegram reminder 30 minutes before `refund_deadline` through the existing outbound ledger, materialized from durable unit state by `suppliers:notify-warranty-deadlines` and re-discovered by `suppliers:recover-warranty`; retired/confirmed/claimed/unclaimable/expired units skipped with delivery re-checks; operator near-deadline monitor | WarrantyDeadlineReminderTest; translation audit; full application regression |
| Verification before choice | Supplier GET decision, separate claim and settlement states, no OCR approval or automatic confirmation | pending/unknown upload/approved/reversal tests; claim-versus-confirm PostgreSQL race |
| Refund or replacement exactly once | Immutable OrderRefund and ledger cap; independent replacement attempt/holds; refund-switch only proven no purchase | refund/replacement/full-refund races, duplicate credits, replacement timeout recovery and acquired-cost approval tests |
| Healthy accounts retained, refunded accounts retired | Per-unit retirement and shared credential presentation eligibility | partial and account refund tests, replacement delivery, private paid-eligibility/guest Recovery Key Web tests |
| External refund evidence | Exact retail amount/currency, unique reference, uncertain transfer recovery, no Customer Store Balance conversion | WarrantyClaimTest and BuyMailFulfillmentTest external refund lanes |
| Webhooks and queue-loss recovery | Signed encrypted durable inbox, dedupe, GET state, scheduler rediscovery | signature/inbox/duplicate/stale approval-after-reversal tests, recover-warranty command tests |
| All documented API operations | Profile; purchase/order/read/history; account/read/refund/explicit confirm; deposit/create/read/list/cancel; six webhook events | BuyMailClientTest + provider services/Admin/claim/confirmation tests; endpoint mapping in integration plan |
| Indonesian and English support/refund | order.warranty translations, Telegram links/notices, private Web chooser and proof form | Translation audit plus browser language switch and localized notification tests; Malay also maintained |
| Operator visibility | Private warranty/purchase/deposit/history/inbox monitor, proof/dispute/recovery/acquired-cost approval | Admin authorization/security tests; real Owner browser monitor and deposit panels |
| Runtime and durable recovery | Supplier connection/worker, HTTP95/job120/retry180, five-minute acquisition lease; urgent supplier-refunds before supplier-claims | Real queue worker integration, recovery command; Supervisor parsing, health/deploy queue updates |

## Recorded verification

- Complete application regression checkpoint: 3,073 tests discovered, 2,979 passed, 94 skipped, 23,022 assertions. Skipped tests include PostgreSQL-only lanes and are not concurrency proof. Latest complete acceptance rerun: 3,075 discovered, 2,981 passed, 94 skipped, 23,036 assertions; JUnit `/tmp/buymail-acceptance-full.xml` inspected with zero failures/errors. The skipped concurrency cases are covered by the separate PostgreSQL lanes below.
- Final feature-focused lane: 203 discovered, 196 passed, seven PostgreSQL cases skipped, 1,230 assertions. Separate PostgreSQL runs prove those cases without skips.
- Full repository PostgreSQL concurrency gate: `/tmp/telegram-bot-concurrency-gate.8ims17/junit.xml` contains 138 testcase records, zero failures/errors/skips. The gate summary reports 118 parent tests because nested fork workers emit JUnit output; retain both figures rather than silently conflating them.
- Subsequent final BuyMail PostgreSQL integration lane: 26 tests, 219 assertions, no skips/failures. Additional completion lane: 15 tests, 96 assertions, no skips/failures. Last financial/runtime lane: ten tests, 76 assertions, no skips/failures. Final missing-origin constraint race is retained at `/tmp/buymail-final-origin-race.xml`.
- These PostgreSQL lanes cover duplicate refunds, full-versus-partial refund, refund-versus-replacement, duplicate replacement workers, duplicate uploads, explicit confirmation-versus-claim, missing-unit refund, IDR/unit schema, key/account identity, real queue worker and paid-evidence gate.
- Real browser tests: two passed. Buyer at 320px switches Indonesian/English, chooses refund and loses the replacement action after settlement; Owner at 390px navigates warranty and deposit monitors without exposing the saved key or horizontal overflow. Screenshots inspected in `src/test-results/` (ignored transient test artifacts).
- Asset build passed; translation audit passed; PHP formatting, shell syntax, Supervisor parsing and `git diff --check` verified.

## CI coverage added for issue #531

- `BuyMailWebhookTest` asserts the first accepted event dispatches exactly one `ProcessBuyMailWebhook` on the `supplier` connection and `supplier-claims` queue with the persisted event ID. Replaying that event and sending invalid signature/header evidence leave the dispatch count at one.
- `WarrantyClaimTest::test_unapproved_claim_cannot_choose_a_settlement` isolates the approval guard with the other eligibility fields satisfied. Both refund and replacement are rejected for received, pending, submission-unknown, review, rejected, reversed, cancelled and expired claims, with no claim/credential mutation, refund, purchase, balance entry, audit or queued work.
- The maintained browser spec gives fresh migrations and fixture creation a bounded 120-second setup timeout, independently of interaction assertion timeouts.
- `npm run test:browser:buymail` builds assets and runs the maintained buyer/Owner warranty spec locally. CI runs `npm run test:browser:buymail:ci` after its shared asset build and Chromium installation; failures block the application job and its downstream image build. Existing failure diagnostics include Playwright outputs.
- `phpunit.postgres.xml` explicitly includes all BuyMail and Warranty feature files, tagged `postgres-release` so the CI job's group filter actually selects them. The BuyMail warranty races retain their `concurrency` tag and continue to run in the separate deploy gate with `--fail-on-skipped`.

### Issue #531 local verification

- `composer test -- --parallel --log-junit /tmp/issue531-sqlite.xml`: 3,123 discovered, 3,026 passed, 97 existing PostgreSQL/environment-only skips, 23,432 assertions; inspected JUnit with zero failures/errors and all 16 new approval cases present. No new skip conditions were introduced.
- `npm run test:browser:buymail`: asset build passed; the initial run exposed the default setup-hook timeout. After the bounded hook-budget fix, `npm run test:browser:buymail:ci` passed both buyer and Owner cases.
- Isolated autoload mutations prove sensitivity without modifying application files: the original webhook test passes when dispatch is removed; the strengthened test fails for removed dispatch and duplicate dispatch; the approval regression fails when the `state !== approved` guard is removed. The unchanged application passes the focused 18-test lane (210 assertions).
- `vendor/bin/phpunit --configuration=phpunit.postgres.xml --testsuite="PostgreSQL release integration" --group=postgres-release --fail-on-skipped --log-junit=/tmp/issue531-postgres.xml --no-coverage` against disposable PostgreSQL 16 passed: 164 tests, 1,319 assertions, zero skips/failures/errors. Inspected JUnit confirms all ten BuyMail/Warranty files (109 cases) and the shared original-purchase race executed.
- `scripts/concurrency-deploy-gate.sh` against a disposable PostgreSQL 16 database passed with `--fail-on-skipped`: 124 parent tests, 1,085 assertions, zero skips/failures/errors. JUnit contains 144 records including fork-worker output, with all ten BuyMail warranty races present (`/tmp/issue531-concurrency/`).
- Pint, `actionlint`, and `git diff --check` passed. GitHub execution of the updated workflow requires a push/PR; local lane results are not presented as a completed remote CI run.

### Shared original-purchase race scope

`SupplierPostgresConcurrencyTest::test_two_workers_purchase_one_durable_intent_and_allocate_one_customer_delivery` represents the original BuyMail purchase **claim** boundary. Two forked workers call the real `SupplierFulfillmentService::process()` for one fulfillment; both enter the same transactional `claim()` method. Its Order/resource locks, current purchase identity and unexpired lease exclusion execute before provider HTTP for every supplier, including BuyMail. The test asserts one POST, one durable purchase, one allocated credential and released wallet commitments. This test is also tagged `postgres-release` and selected by both PostgreSQL CI lanes.

The race's HTTP fixture uses the generic Prodseller adapter. It proves shared claim serialization, not BuyMail response parsing, five-minute lease expiry recovery, or account-unit finalization. `BuyMailFulfillmentTest` covers provider-specific paid-evidence and delivery behaviors sequentially, and `BuyMailClientTest` covers reference-recovery contracts; `BuyMailWarrantyPostgresConcurrencyTest` separately races BuyMail replacement purchases and account claims. An original BuyMail adapter fork race is not claimed as evidence.

## Provider qualification limits

Public docs omit complete GET/list schemas, cross-key idempotency scope, price locking, refund finality and review SLA. The implemented strict envelopes and qualification requirements are explicit in [runbook-buymail.md](runbook-buymail.md). Fixed-origin fake HTTP proves store behavior; it cannot prove live account validity or supplier refund approval. User has not supplied a key. No live purchase/deposit/refund/confirmation, deployment, or external payment was executed.

Same-account key rotation keeps unresolved holds, but original unknown purchase/deposit intents are not POST-replayed across a credential change. Operator exact-reference/ID GET recovery remains available. Provider reversal is operator liability, never automatic buyer clawback. External refund forms record actual transfer evidence and do not initiate transfers.

[ADR-0017](adr/0017-per-account-warranty-and-partial-refunds.md), [runbook](runbook-buymail.md), CONTEXT and implementation map describe the resulting boundaries.

## Final workspace audit

- API documentation inventory maps all 11 documented operations to the strict BuyMail adapter and buyer/Admin flows. Settings/key generation/IP allowlist remain portal operations because no API endpoints are documented.
- Each customer language has 32 warranty/support keys; Indonesian/English/Malay catalog audit passed.
- Eleven additive migrations introduce private connection metadata, account-unit identity, claims/refunds/replacements, inbox/deposit/confirmation state, missing-unit refund origin constraints, daily pause and intent credential fingerprints. No catalog ENV or new supplier-key ENV was added.
- Dedicated worker, scheduler, operational queue and health/deploy checks are wired. A real queue:work supplier test completed a verified purchase once.
- Private mobile buyer and Owner/Admin browser checks passed, and screenshots were visually inspected.
- Existing user-owned .commandcode changes were left intact. No commit, push, production deploy or external spend was requested/performed.
- Disposable PostgreSQL container and database created for qualification were removed after verified test completion.

Final result: development implementation and requested verification completed in the workspace. All required checks above passed. No production deployment or live provider qualification has been performed.
