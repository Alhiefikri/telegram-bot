# USDT funding policy, active-target reservation and pause controls (#548)

U3 implements work package **P4** of [#536](https://github.com/Alhiefikri/telegram-bot/issues/536) on top of the closed dependencies U1 [#546](https://github.com/Alhiefikri/telegram-bot/issues/546) (exact USDT ledger) and H0 [#538](https://github.com/Alhiefikri/telegram-bot/issues/538) (operational policy). It implements the approval overlay recorded on the issue:

> U3 policy amendment: H0-v1 business min/max/per-Customer/aggregate exposure limits are explicitly disabled (no business cap), with representational/overflow guards retained. No preallocated beta quotas/allowlist or preset buttons for this release. Audience all registered bot Customers, custom exact positive USDT input, application fee 0, Customer-paid transfer fees, one active funding target across rails, ten-minute confirmation and snapshotted Binance/BSC dashboard deadline. Implement durable active-target reservation, ambiguous-money handling, policy/terms versions, Owner pause/resume and bounded technical anti-abuse limits. Do not silently replace the approved uncapped policy with a smaller capped beta.

U3 is configuration and exposure-slot state only. It never moves money, mints a provider target or creates a funding receipt. **Production USDT funding stays disabled**: the seeded policy has `funding_enabled = false` and no qualified production readiness provider is registered. U6 adds the actual U5 watch-only technical path only in disposable local/testing environments; this is not qualification, deploy authorization, real funding or production configuration.

## 1. What this package adds

| Piece | Owner | Money effect |
| --- | --- | --- |
| `customer_usdt_funding_policies` singleton | `CustomerUsdtFundingPolicyManager` | none: versioned DB/Admin config only |
| `customer_usdt_funding_terms` append-only versions | `CustomerUsdtFundingPolicyManager` | none: published customer terms snapshots |
| `customer_usdt_funding_pauses` scoped Owner pauses | `CustomerUsdtFundingPolicyManager` | none: blocks new targets/spending only |
| `customer_usdt_funding_reservations` | `CustomerUsdtFundingReservationService` | none: durable exposure slot and resolution evidence |
| `CustomerUsdtFundingGate` + rail readiness registry | `UsdtFundingRailReadinessRegistry` | none: fail-closed availability statement |

Money remains exclusively owned by `CustomerUsdtBalanceLedger` (#546) and reconciliation by `UsdtReconciliation` (#547). No IDR table, writer or reader is touched.

## 2. Versioned DB/Admin policy (no ENV)

`CustomerUsdtFundingPolicy` is a singleton row seeded by the migration:

| Field | Meaning |
| --- | --- |
| `funding_enabled` | Owner activation switch, seeded `false` |
| `confirmation_ttl_seconds` | confirmation window, default 600 s, bounded 60–3600 s |
| `application_fee_micro` | exact nonnegative micro-USDT fee, default 0 (H0-v1) |
| `terms_version` | FK to the append-only terms version snapshotted by each reservation |
| `version` | optimistic policy version |

There is deliberately **no** minimum, maximum, preset, per-Customer cap, aggregate cap, quota or allowlist column. H0-v1 supersedes ADR-0019's Proposed cap/beta defaults; the only remaining amount guards are representational: `UsdtMicroAmount` strict positive micro-USDT parsing (no float/exponent/zero/excess precision/overflow), a bounded TTL and a nonnegative fee. The application fee input accepts `0` or a canonical ≤6-decimal USDT string and is stored as exact micro-USDT.

`CustomerUsdtFundingPolicyManager::change()` locks the singleton row, rejects a stale `expectedVersion` with `CustomerUsdtFundingPolicyConflict`, treats an identical submission as a no-op (no audit, no row touch), writes one `customer_usdt.funding_policy.updated` audit row and appends a new immutable terms version only when the body changed. Policy/terms changes never create a reservation, a ledger entry or a provider target.

## 3. Durable active-target reservation

`customer_usdt_funding_reservations` is the single open funding target per Customer, enforced by the partial unique index `(customer_id) WHERE resolved_at IS NULL` — not by a check-then-insert:

- `CustomerUsdtFundingReservationService::reserve()` runs in one short transaction: lock the policy row → return the recorded reservation for a repeated source (fingerprint-verified replay) → return the Customer's open target for a new source (`existing_active`) → re-check the gate → insert. A uniqueness race is recovered through a savepoint and a re-select, never by continuing an aborted PostgreSQL transaction.
- The reservation snapshots rail, amount, fee and the policy/terms versions it was confirmed against; its identity columns are immutable (Eloquent guard + PostgreSQL trigger, including DELETE denial).
- **Expiry never releases the slot.** Due reservations become `expired_unresolved` (lazily on read through `activeForCustomer()`/`reserve()`, or by the bounded `usdt-funding:hold-expired-reservations` command), which still occupies the partial unique slot. Ambiguous money therefore stays reserved.
- `markSettled()` resolves a reservation only together with an existing `funding_credit` entry whose source claim is `customer_usdt_top_up` + the top-up reference, whose exact micro amount equals the reservation and whose account belongs to the same Customer. Callers invoke it inside the same transaction as the ledger posting, so pending reservation → liability is atomic.
- `release()` requires a typed `CustomerUsdtFundingRelease` (`neverExposed`, `providerDeclined`, `externallyReturned`, `compensated`) with a bounded reference and actor. There is no generic cancel that could drop exposure while money is still possible; retries replay the same resolution and a different resolution conflicts.

The slot is per Customer across rails, so confirmation can never open a second target on another rail; `findBySource()` is the read seam for showing the existing target.

U6 calls `reserve()` with the confirmed Dashboard payment deadline. The optional immutable deadline is validated at creation and on same-source replay; callers without a target deadline retain the U3 confirmation-TTL default. No UPDATE extends a reservation after creation.

## 4. Pause and readiness controls

- `CustomerUsdtFundingPauseScope` covers `funding`, `spending`, `rail_binance_pay` and `rail_bsc_usdt`. Pausing requires an Owner, a 3–191 character single-line reason, is audited (`customer_usdt.funding_pause.created`/`deleted`) and is idempotent. Resume deletes the row and audits it.
- Pausing blocks **new** funding targets (or new USDT spending) only. Existing targets replay, settlement, refunds and reconciliation are never gated — the U2 reconciliation contract keeps consuming old deposits.
- `UsdtFundingRailReadiness` providers (tag `usdt-funding-rail-readiness`) report a local, non-blocking, sanitized observation. The qualified production tag stays empty, so production fails closed. U6's `LocalBscFundingReadiness` is assembled only for local/testing when no explicit BSC provider is registered, and observes no network. RPC qualification and exclusive receiver validation happen on the actual U5 creation path. Synthetic test evidence and technical readiness never qualify custody/legal/production funding; H1 remains no-go regardless of GitHub closure.
- `CustomerUsdtFundingGate` is consumed by `reserve()` inside its transaction, so an Owner activation/pause change (serialized on the policy row lock) cannot interleave with a new target. U10 [#555](https://github.com/Alhiefikri/telegram-bot/issues/555) consumes `assertSpendingAvailable()` for USDT checkout.

## 5. Admin surface

Owner-only (`canManageUsdtFundingPolicy`, `admin.store-balance-owner`):

- `admin.store-balance.usdt-policy` — `AdminCustomerUsdtFundingPolicyController` updates activation, TTL, fee and terms with optimistic versioning and the `usdtFundingPolicy` error bag.
- `admin.store-balance.usdt-pauses.{store,destroy}` — `AdminCustomerUsdtFundingPauseController` pauses/resumes one scope with a required reason.
- The Stats tab renders a "Kebijakan Top-up USDT" card with the policy version, rail availability, pause state, the H0-v1 uncapped disclosure and the edit forms. Finance/Support see the card but no mutation controls.

## 6. Deliverable genealogy

Funded by the U3 issue overlay; implemented on base `main` a5321f03. Downstream owners keep their contracts: U4 [#549](https://github.com/Alhiefikri/telegram-bot/issues/549) receiver ownership, U5 [#550](https://github.com/Alhiefikri/telegram-bot/issues/550) rail, U6 [#551](https://github.com/Alhiefikri/telegram-bot/issues/551) intents/top-up targets (must reserve through this service and snapshot the policy/terms versions), U7 [#552](https://github.com/Alhiefikri/telegram-bot/issues/552) settlement/late money (must call `markSettled` in the posting transaction), U8 [#553](https://github.com/Alhiefikri/telegram-bot/issues/553) exception review (mints the typed release resolutions), O2 [#558](https://github.com/Alhiefikri/telegram-bot/issues/558) alert/pause wiring and runbooks.

## 7. Verification evidence

- `tests/Feature/UsdtFundingPolicyTest.php` (SQLite): seeded disabled/uncapped/versioned policy, approved terms v1, no cap columns exist, Owner update + terms append + audit, stale-version conflict with no mutation, no-op update with no audit, strict validation (TTL/fee/terms), role enforcement, pause/resume idempotency and audit, unknown scope 404, Dashboard owner-only controls, and money-row counts unchanged across policy changes.
- `tests/Feature/UsdtFundingReservationTest.php` (SQLite): fail-closed gate (missing readiness, disabled policy), reservation snapshot/expiry, replay and changed-payload conflict, stale policy snapshot, one open target across rails, expiry → `expired_unresolved` still holding the slot, late settlement from the held state, atomic settlement rollback with its ledger posting, settlement entry/reference/customer/kind verification, typed release replay/conflict, pause scoping, spending gate, strict identifiers, model guards, bounded expiry command.
- `tests/Feature/UsdtFundingPostgresConcurrencyTest.php` (disposable PostgreSQL 16, multiprocess): three parallel reservations produce exactly one open target; concurrent Admin updates produce one `Applied` and one `Conflict`; committed pause blocks concurrent reserves and a pause-vs-reserve race never opens two targets; settle-vs-release race keeps exactly one resolution; expiry + late settlement never opens a second slot; named constraints/triggers (partial unique, amount/rail/state checks, identity immutability, DELETE denial, terms append-only, pause scope enum).
- The shared PostgreSQL worker harness moved to `tests/Concerns/RunsPostgresWorkers.php` and is used by both the U1 ledger races and these funding races.

Limitations: SQLite tests prove behaviour, not locking — only the PostgreSQL lane does. No rail/provider/custody is qualified, so no funding can be activated by this change; `funding_enabled` defaults to false. No scheduler is added in this release: expired reservations are held lazily on read and by the bounded operator command, and O2 #558 owns wiring cadence/alerts. Reconciliation does not yet sum open reservations as pending exposure; U2's liability stays the signed ledger sum plus unresolved receipts.
