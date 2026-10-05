# USDT reconciliation: projection, receipts, liability dan treasury (#547)

U2 implements work package **P3/P8** of [#536](https://github.com/Alhiefikri/telegram-bot/issues/536) on top of the closed dependencies U1 [#546](https://github.com/Alhiefikri/telegram-bot/issues/546) (exact USDT ledger) and R2 [#542](https://github.com/Alhiefikri/telegram-bot/issues/542) (receipt writer cutover). It is a bounded, read-only reconciliation. It never repairs a projection, never writes a ledger entry, never settles a top-up and never signs or moves custody funds. USDT funding stays **disabled**: no approved custody adapter exists (H1 [#539](https://github.com/Alhiefikri/telegram-bot/issues/539) is still open/no-go), so a store with USDT activity reports `unverifiable` custody rather than fabricated backing.

## 1. What one run checks

| Facet | Reads | Detects |
| --- | --- | --- |
| Projection/cursor/history | `customer_usdt_balance_accounts` vs signed sum, entry count, sequence range and newest entry of `customer_usdt_balance_entries`, in one SQL statement per page | `balance_mismatch`, `cursor_mismatch`, `history_gap` |
| Receipts vs settlement | `external_money_receipts` targeted at `customer_usdt_top_up` vs the funding entries that share the same target id | `orphan_claimed_receipt`, `held_receipt`, `credit_without_receipt`, `currency_mismatch`, `receipt_amount_unrepresentable`, `unsettled_difference`, `overcredited_receipt` |
| Liability and treasury | Canonical signed ledger sum + unresolved deposits + audited treasury movements vs approved custody observations | coverage `consistent` / `deficit` / `surplus` / `unverifiable` / `not_required` |
| Accounting aggregates | `usdt_balance_accounting_aggregate_claims` vs `usdt_balance_accounting_aggregates` and the event stream | claim/amount divergence, pending backlog |

Exactness rules:

- every amount is an integer micro-USDT string or a Brick `BigInteger`; nothing is rounded, summed as float or converted to IDR;
- liability is the **signed ledger sum** (canonical), with the mutable projection sum reported alongside so a mismatch is visible twice — once per account and once as a total;
- receipt amounts are converted from raw token units with the U1 `UsdtMicroAmount::fromRawTokenUnits` contract; a non-representable amount is a finding and is never summed;
- receipt partitioning is exact: only `target_type = customer_usdt_top_up` and `currency = USDT` enter the USDT subledger partition, and each finding carries its own customer (when attributable), provider (rail) and currency.

The whole read phase runs inside one read-only `REPEATABLE READ` transaction on PostgreSQL, so a concurrent posting can never be observed as a false drift. Custody observations are resolved **before** that snapshot opens, so no provider/RPC call ever extends a database transaction.

## 2. Commands

### `usdt-balance:reconcile`

```
--customer=        inspect only this existing Customer id
--cursor=          resume accounts after this account id
--receipt-cursor=  resume receipts after this receipt id
--credit-cursor=   resume unreceipted funding credits after this entry id
--limit=200        rows inspected per facet (1-1000)
--no-freeze        report drift without freezing the scoped accounts
--actor=           operator identity recorded on the run evidence
--json             sanitized JSON report
```

- Exit `0` only when every facet is clean; exit `1` on any finding (drift, receipt divergence, coverage deficit/surplus/unverifiable, accounting divergence).
- By default every drifted account is **frozen** (scoped to that account) by re-proving the drift under the same account row lock every ledger posting takes. A stale finding never freezes a healthy account, an already frozen account is not frozen twice, and a failed freeze is isolated per account (`failed`) so the rest of the run completes. Freezing writes only `frozen_reason`: no entry, projection or accounting row is created or repaired. Unfreezing is a deliberate operator action (O2 [#558](https://github.com/Alhiefikri/telegram-bot/issues/558)).
- Every run appends one sanitized `usdt_balance_reconciliation_runs` row (component totals, verdict, findings, freeze outcomes, actor). The row contains no names, Telegram ids, e-mail, receipt evidence payloads, secrets or chain hashes; it is the durable alert/evidence artifact that #558 consumes. Rows and treasury movements are append-only (PostgreSQL triggers plus model guards).
- Pagination is exact: each facet fetches `limit + 1` rows, so `has_more`/`next_cursor` never claim a page that does not exist. The three cursors are independent internal ids, not capabilities.
- With `--customer`, custody coverage is reported as `not_evaluated` (custody is a store-wide fact; a per-customer comparison would be fabricated). Orphan receipts are unattributable until #551 links top-up targets to Customers, so they stay visible in scoped runs instead of being hidden.

### `usdt-balance:aggregate-accounting`

Bounded, idempotent catch-up of the accounting aggregates for one consumer (`--consumer`, default `reporting-v1`, `--limit` events per run). Claims and aggregate deltas commit in the same transaction: a duplicate delivery, crash or Redis loss can only replay unclaimed events, never double count. Aggregates are keyed by balanced leg pair, so no single global clearing row is locked or summed on the posting path. Exit `1` if claims and aggregates diverge.

### `usdt-balance:record-treasury-movement`

Records one audited custody movement (`--direction=in|out`, exact `--amount`, unique `--reference`, `--reason`, `--actor`, optional `--occurred-at`). One `(direction, reference)` identity produces exactly one row; a replay returns the existing movement and a changed amount conflicts. This is bookkeeping only — the application never signs, sends or credits anything here.

## 3. Treasury adapter contract

```php
interface UsdtTreasuryAssetProvider
{
    public function providerKey(): string;               // e.g. bsc_primary_custody
    public function observe(): UsdtTreasuryAssetObservation;
}
```

`UsdtTreasuryAssetObservation` is a closed typed statement:

- `available($provider, BigInteger $assetsMicro, $observedAt)` — a proven exact amount (zero is a proven amount);
- `unavailable($provider, $reason)` / `unsupported($provider, $reason)` — cannot be proven at all; the report carries `null` assets, never zero;
- provider keys and reasons are restricted to sanitized tokens, so observations are safe to store and log.

Coverage math (all `BigInteger`, global scope only):

```
expected custody = liability (signed ledger sum)
                 + unresolved deposits (orphan/held receipts + positive receipt differences)
                 + movements in - movements out
unexplained      = observed custody - expected custody
```

`unexplained == 0` is `consistent`; negative is `deficit`; positive is an unexplained `surplus` that must be explained by recording the movement (an operator gas/reserve top-up) or investigated. When `liability + unresolved == 0`, coverage is `not_required` even without an adapter, so an idle store can still reconcile cleanly.

Adapters are registered only through the container tag `usdt-treasury-asset-providers` (bound in `AppServiceProvider`), which is intentionally empty in this release. **A fixture adapter in a test is not proof of actual backing**; an approved adapter requires the H1 custody/capacity/provider-permission and treasury qualification gates plus a production registration, which this ticket does not authorize.

## 4. Accounting aggregates and restore replay

`usdt_balance_accounting_aggregates` (per consumer, currency and debit/credit leg pair) plus `usdt_balance_accounting_aggregate_claims` (unique consumer/event) implement ADR-0019's "aggregates consume events asynchronously with a unique consumer/event claim". The event stream stays the authority; the aggregates are derived evidence that can be rebuilt from it.

Restore replay behaviour:

- aggregate rows and claims survive a normal restart; `aggregate-accounting` re-run processes zero events;
- if a restore loses claim rows but keeps aggregate rows, the next catch-up re-processes the events and reconciliation reports `accounting.consistent = false` instead of silently double counting; the operator rebuilds the derived rows for that consumer from the immutable events, after which reconciliation is consistent again. No money row is ever touched;
- if a restore loses aggregate rows but keeps claims, the same divergence is visible; `aggregate-accounting` re-claims/re-applies nothing (claims exist), so the rebuild step is the recovery;
- reconciliation runs recorded before and after the replay are append-only evidence of the restore.

## 5. Schema (additive)

| Table | Purpose |
| --- | --- |
| `usdt_treasury_movements` | append-only audited custody movements, unique `claim_key`, `USDT`/direction/positive-amount CHECKs |
| `usdt_balance_accounting_aggregates` | per `(consumer, currency, debit_account, credit_account)` totals with `entries_count`, `amount_micro`, first/last event ids |
| `usdt_balance_accounting_aggregate_claims` | unique `(consumer, accounting event)` claim, RESTRICT FK to the immutable event |
| `usdt_balance_reconciliation_runs` | append-only sanitized run evidence: scope, cursors, verdict, component totals, custody state/assets, movement totals, pending aggregates, actor, JSON findings |

PostgreSQL triggers reject UPDATE/DELETE on the two append-only tables; checks enforce the USDT currency, positive amounts, direction and scope/state enums. IDR tables, writers and readers are untouched.

The receipt target `customer_usdt_top_up` was added to `ExternalMoneyReceiptTargetType`/`MoneyReceiptTarget` as the canonical namespace for a USDT top-up target: its target id is the top-up id, which is also the `customer_usdt_top_up` USDT ledger source id, so a claimed funding receipt and its settled entry share one economic identity (#551 will mint top-ups under this contract).

## 6. Limitations and downstream ownership

- No custody/provider/adapter is approved; every store with USDT liability reports `unverifiable` until H1 passes. That is the intended fail-closed state, not a defect.
- Reconciliation never freezes on receipt or coverage findings (they have no safe account scope yet); it reports and exits non-zero.
- Durable alert delivery, metrics, pause wiring and restore/rollback runbooks belong to O2 [#558](https://github.com/Alhiefikri/telegram-bot/issues/558); this ticket provides the run evidence they consume.
- Settlement of a top-up (and therefore the ledger's `VerifiedUsdtFunding` call) is U7 [#552](https://github.com/Alhiefikri/telegram-bot/issues/552); the receipt/funding linkage implemented here is the contract that settlement must satisfy.
- Unreceipted-credit scanning only sees `customer_usdt_top_up` sources; other funding rails would need their own partition before activation.
- SQLite/sync tests prove behaviour, not locking. Only the PostgreSQL lanes prove the snapshot, claim races and trigger enforcement.

## 7. Verification evidence

Feature (`tests/Feature/UsdtBalanceReconciliationTest.php`): bounded pagination/scoping/redaction, exact sums above the signed-integer range, projection/cursor/history drift, scoped and idempotent freeze, stale-finding protection, orphan/held/wrong-currency/unrepresentable receipts, unmatched and overcredited differences, missing/unavailable/throwing custody adapters, deficit/surplus/unexplained variance, accounting aggregate idempotency and divergence, restore replay, movement replay/conflict, command validation.

PostgreSQL (`tests/Feature/UsdtReconciliationPostgresConcurrencyTest.php`, disposable PostgreSQL 16, multiprocess): reconciliation during live posting never reports false drift (repeatable-read snapshot), concurrent accounting catch-up claims every event exactly once, concurrent duplicate movement recording commits one row.

Recorded run evidence (base `main` f2e8d3dc):

- focused `UsdtBalanceReconciliationTest` 31 tests / 218 assertions pass (SQLite); the three PostgreSQL tests skip outside the concurrency lane by design;
- `php artisan test --parallel` (SQLite) 3.495 tests / 26.063 assertions, 132 skipped, 0 failures;
- `UsdtReconciliationPostgresConcurrencyTest` 3 tests / 77 assertions pass on disposable PostgreSQL 16 (`RUN_CONCURRENCY_TESTS=1`, `phpunit.concurrency.xml`);
- full `scripts/concurrency-deploy-gate.sh` on disposable PostgreSQL 16: gate report 159 tests / 1.496 assertions with 0 skips, 0 failures, 0 errors (phpunit 179 tests / 1.655 assertions), i.e. the entire PostgreSQL concurrency lane including the three U2 races;
- `vendor/bin/pint --test` passes.
