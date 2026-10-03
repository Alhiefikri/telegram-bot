# BuyMail implementation and verification

User scope: automatic purchase only after verified buyer payment; Dashboard-managed encrypted API key; per-account Gmail claims; buyer chooses refund or replacement after verification; Indonesian/English support/refund copy; broad meaningful tests and financial concurrency protection. Production deployment and live spend are separate and were not performed.

## Requirement audit

| Requirement | Implementation | Authoritative proof |
| --- | --- | --- |
| API key/secret managed in Dashboard without ENV rebuild | SupplierAdminRequest, SupplierManager, encrypted Supplier casts and connection form | SupplierAdminTest and BuyMailAccountIdentityTest: encryption, redaction, blank preservation, recent password, same-account rotation and holds |
| Buy only for a real Paid buyer | SupplierFulfillmentService claim checks Order + Paid Payment + paid_at, network after commit | BuyMailFulfillmentTest: pending/unverified never sends; verified full/partial delivery; real dedicated queue worker |
| No duplicate purchase on retry/crash | Durable intent, reference, idempotency, lease/token, exact GET recovery and credential fingerprints | BuyMailClientTest; original/replacement recovery integration; PostgreSQL worker race |
| Supplier stock/balance/pricing | IDR profile adapter, private Gmail mapping, commitments/margins, freshness, quantity cap, daily reset/refunds-disabled gate | BuyMail client/availability/identity/fulfillment tests, existing supplier regressions |
| Partial delivery and missing-unit refund | Actual debit/quantity, outstanding-only attempts, early unit access, MissingFulfillmentRefundService | Full/partial/missing Store Balance/external/full-missing integration; duplicate missing-refund PostgreSQL race |
| Gmail proof and deadline | Private encrypted original JPEG/PNG, MIME/image decode/pixel/size limit, unit ownership/deadline | RefundProofTest; WarrantyClaimTest; Web/Admin ownership and private download tests |
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
