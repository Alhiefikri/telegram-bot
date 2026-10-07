# Balance operational controls, alerts and restore gate (#558)

O2 implements work package **P8** of [#536](https://github.com/Alhiefikri/telegram-bot/issues/536) on top of the closed dependencies U2 [#547](https://github.com/Alhiefikri/telegram-bot/issues/547), U3 [#548](https://github.com/Alhiefikri/telegram-bot/issues/548), U8 [#553](https://github.com/Alhiefikri/telegram-bot/issues/553), U11 [#556](https://github.com/Alhiefikri/telegram-bot/issues/556) and the U10/O1 readiness surfaces. It changes **no money rule**: the Customer USDT Balance ledger, the IDR Store Balance ledger, the receipt registry and every settlement/refund path keep their owners. This package only adds pause scopes, bounded operational metrics, durable alert delivery, the post-restore gate and the runbooks that tie them together.

## 1. What this package adds

| Piece | Owner | Money effect |
| --- | --- | --- |
| `delivery` pause scope in `customer_usdt_funding_pauses` | `CustomerUsdtFundingPolicyManager` (+ `TelegramBalanceReceiptDeliveryPause`) | none: defers receipt messages only |
| `balance_operation_events` | `BalanceOperationEventRecorder`, `BalanceOperationRetry` | none: append-only bounded metrics, written outside money transactions |
| `usdt-balance:metrics` | `BalanceOperationMetrics`, `ReportBalanceMetrics` | none: read-only report + bounded retention prune |
| `balance_operations_alert_episodes` + `admin.balance_operations_alert` | `BalanceOperationsAlertService`, `BalanceOperationsAlertDelivery` | none: durable operator alerts on existing evidence |
| `balance_restore_gates` | `BalanceRestoreGateService`/`Reader` | none: blocks new funding/spending until replay + clean reconciliation |
| `usdt-balance:unfreeze` | `UsdtBalanceAccountUnfreeze` | none: clears `frozen_reason` only, under fresh re-proof |

## 2. Pause semantics

Funding/rail pauses block new targets, the spending pause blocks new USDT checkout debits, and the new delivery pause defers balance receipt messages (`balance.top_up_receipt`, `usdt.top_up_receipt`, `usdt.refund_receipt`). The delivery pause is enforced inside `TelegramOutboundEffectPolicy::canClaim()` and `applyRetryableConstraints()`, so:

- a deferred effect keeps its row, `effect_key` and `attempts = 0`, and is never dropped;
- pause changes apply to running workers on their next claim (no memoized pause state);
- settlement, late money, refunds, reversals, reconciliation, alerting and the creation of the durable effect row inside the money transaction are never gated;
- other Telegram families (order receipts, admin alerts, broadcasts, stock/price notifications) keep delivering.

Exact transition/recovery behavior is proven by `BalanceReceiptDeliveryPauseTest` and the pause-vs-claim race in `BalanceOperationsPostgresConcurrencyTest`.

## 3. Bounded metrics

`balance_operation_events` stores one row per observation: `metric` and `outcome` from `App\Enums\BalanceOperationMetric`, an optional fixed `bucket` (`lt_50ms`, `lt_250ms`, `lt_1s`, `lt_5s`, `gte_5s`) and a bounded integer `value`. PostgreSQL rejects UPDATE for every role; DELETE is reserved for the retention prune (`usdt-balance:metrics --prune-days=N`). Recording never throws and never runs inside a money transaction, so a metrics failure cannot change or roll back money.

Recorded runtime outcomes: ledger mutation (`posted`, `replayed`, `insufficient_funds`, `frozen`, `projection_drift`, `identity_conflict`), database lock retry (`deadlock`/`concurrency` with a duration bucket, attempt index), receipt claim (`claimed`, `replayed`, `conflict`, `review`), funding reservation (`reserved`, `existing_active`, `replayed`, `conflict`) and balance delivery (`claimed`, `sent`, `failed`, `unknown`, `skipped_paused`). Backlog ages, frozen accounts, latest reconciliation drift/coverage, receipt conflicts and control state (pauses, restore gate) are read-time queries against durable evidence, never cached counters.

## 4. Durable alerts

`ops:balance-alerts` runs every five minutes, reconciles the closed alert-key set, and materializes one durable Telegram Outbound Effect (`admin.balance_operations_alert`) per newly opened, reopened or fingerprint-changed episode, plus one resolution effect. Delivery, retry, ambiguity and recovery reuse the shared outbound ledger (`telegram:recover-outbound`); the rendered message is baked into the effect so a later episode change can never rewrite a pending delivery. Messages and metrics contain counts, sanitized aggregate amounts, run ids and scope names only — no customer identity, Telegram id, address, hash or secret. A concurrent evaluation cannot create a second episode or effect (savepoint + bounded retry, proven on PostgreSQL).

Alert keys: `reconciliation_drift`, `reconciliation_receipt_collision`, `treasury_coverage`, `reorg_loss_unresolved`, `receipt_registry_conflict`, `top_up_work_backlog`, `settlement_backlog`, `outbound_backlog`, `restore_gate_open`, `delivery_paused`, `account_frozen`.

## 5. Post-restore gate

`usdt-balance:restore-open` records the declared restore window and blocks new USDT funding and spending (`restore_gate_open`) while reconciliation/settlement/refund/reversal keep running. `usdt-balance:restore-replay` re-inspects every non-terminal funding target against authoritative evidence, optionally re-runs the durable receiver scan, and quarantines non-terminal balance deliveries as `unknown` (`restore_ambiguous_delivery`) so a possibly-delivered message is never blindly resent. `usdt-balance:restore-close` refuses until the replay completed after the gate opened **and** a full-scope reconciliation run recorded after that replay shows `consistent` with zero account/receipt findings and `unexplained_micro = 0`. `usdt-balance:unfreeze` clears only a drift freeze whose fresh re-proof finds no drift; receiver reorg markers, durable receiver evidence and incident rows keep the freeze, including before incident materialization and while an existing drift freeze hides the marker. Unrecognized freeze reasons return `unsupported_freeze` and stay held. No step deletes ledger, receipt, claim or resolution history and no step refunds automatically.

## 6. Documentation

- [runbook-balance-operations.md](runbook-balance-operations.md) — pause scopes, alert catalog, metrics, incident runbooks (reorg, deficit, receipt collision, unfreeze), roles and time windows.
- [runbook-balance-restore-rollback.md](runbook-balance-restore-rollback.md) — PITR/logical restore procedure, stale external side-effect replay, receiver/token and secret rotation, compatibility rollback preserving the registry.
- [ADR-0023](adr/0023-balance-operational-controls.md) — decision record for the control plane.
- `CONTEXT.md` — glossary entries for the pause scope, Balance Operations Alert and Balance Restore Gate.

## 7. Verification evidence

All evidence below was produced locally on this branch; no CI workflow was invoked, no production system was touched, no funds were received and no custody/provider qualification is claimed.

- **SQLite feature lane (new behavior)**: `php artisan test --filter='BalanceRestoreGateTest|BalanceReceiptDeliveryPauseTest|UsdtBalanceUnfreezeTest|BalanceOperationsMetricsTest|BalanceOperationsAlertTest|AdminBalanceOperationsPanelTest'` — 37 tests / 324 assertions passed.
- **Full SQLite regression suite**: `php artisan test` — 3813 tests / 27707 assertions, 3637 passed, 176 skipped, 0 failed.
- **Disposable PostgreSQL 16 race lane (this package)**: `BalanceOperationsPostgresConcurrencyTest` — 5 tests / 39 assertions passed (restore gate vs three concurrent reservations all blocked with zero rows; delivery pause vs two concurrent claims both deferred with `attempts = 0` and delivered after resume; three concurrent alert evaluations producing exactly one episode and one effect; unfreeze race producing one `unfrozen` + one `not_frozen` with one audit row; metric UPDATE rejected by the trigger while the retention DELETE succeeds).
- **Disposable PostgreSQL 16 cross-class run**: `BalanceOperationsPostgresConcurrencyTest` passes on one disposable database together with `UsdtTopUpSettlementPostgresConcurrencyTest`, `UsdtReorgIncidentPostgresConcurrencyTest` and `UsdtReceiverOwnershipPostgresConcurrencyTest`. The repository's aggregate balance race lane is order/flake coupled independently of this change: in one 10-class run three tests failed, the identical three failed on the untouched base commit `dd0acc32` in the same lane, a 3-class run without this package failed a different settlement test, and every one of those classes passes in isolation on this branch. That coupling is pre-existing (the repository documents its PostgreSQL lane as not passing as an aggregate run) and is not claimed as green here.
- **Disposable PostgreSQL restore drill**: `scripts/balance-restore-drill.sh` migrated a throwaway PostgreSQL 16 database, seeded a Customer with `3.000000` USDT of settled liability plus one claimed receipt and one pending balance delivery, took a `pg_dump`, destroyed the database, restored it, then ran `restore-open → restore-replay → reconcile → restore-close`. Observed: restored row counts and balances identical to the dump (`1,1,0,3000000,1`), one ambiguous delivery quarantined, reconciliation verdict `consistent`, gate `cleared` with evidence (`unexplained_micro = 0`, `unquarantined_deliveries = 0`), and the ledger liability unchanged after reopening.
- **Repeated `migrate:fresh` on PostgreSQL** succeeds twice in a row, so the new append-only trigger function never breaks a wiped development or CI database.

## 8. Limitations

- The restore gate governs Customer USDT funding/spending; IDR Store Balance operations are paused through the existing Store Balance controls during a restore.
- The drill registers a fixture custody observation for exactly the seeded liability so the procedure can complete on a disposable database. That fixture is test evidence, never proof of custody backing; H1 custody, provider permission, legal review and production activation remain open, and the production custody provider tag stays empty.
- The scheduled reconciliation covers one bounded window per facet (cursor-paged); a full sweep across cursors remains an explicit operator action documented in the runbook.
- `--scan` in the replay needs reachable allowlisted BSC RPC; a failed scan blocks reopening (fail closed) until it is re-run or the operator records the documented alternative.
- Quarantined `unknown` deliveries stay unknown until a human resolves them in Admin → Telegram outbound effects; this is intentional and never time-based.
- A bounded `usdt-balance:restore-replay --limit=N` may leave deliveries unquarantined; `usdt-balance:restore-close` refuses while any remain and names the command, so the gate cannot be opened over a half-quarantined population.
- SQLite tests prove behavior, not locking; only the disposable PostgreSQL lanes prove the races listed above. Nothing in this package is a production performance benchmark.


## Issue #576: receiver reorg containment before incident materialization

Ordinary unfreeze previously accepted `usdt_receiver_reorg:<receiver>` as soon as the ledger was consistent. It also accepted a repaired drift freeze when the scanner had recorded reorg evidence behind that existing reason. The PostgreSQL reproduction used a settled synthetic BSC top-up, a checkpoint reorg from the real scanner, no incident row yet, and three independent workers calling unfreeze followed by purchase. Before the fix, one worker returned `unfrozen` and all three returned `spent` in both scenarios.

`UsdtBalanceAccountUnfreeze` now checks receiver markers and durable receiver evidence scoped to the Customer (without excluding retired receivers) under the account row lock shared with scanner containment. Incident rows continue to block reopening. Only the closed `UsdtBalanceDriftIssue` reasons can reach fresh drift re-inspection; other reasons return `unsupported_freeze`. Both dry-run and execution enforce these checks. This does not add an incident resolution or production activation path.

Verified on disposable PostgreSQL 16 with synthetic RPC evidence: `UsdtReorgIncidentPostgresConcurrencyTest` passed **5 tests / 74 assertions**. Both new scenarios returned `reorg_hold:frozen` for all three workers before incident materialization, preserving the account balance, ledger entries and external receipts with no unfreeze audit; the monitor then materialized the incident and ordinary unfreeze still refused. The related SQLite lane (`UsdtBalanceUnfreezeTest`, `UsdtReorgIncidentTest`, `BscUsdtTransferIngestionTest`, `CustomerUsdtBalanceLedgerTest`) passed **52 tests / 323 assertions**; the focused unfreeze suite passed **10 tests / 38 assertions**. Pint passed.


The full `scripts/concurrency-deploy-gate.sh` run completed on 2026-10-07. Its JUnit gate report recorded **207 tests / 2,005 assertions / zero skipped / one failure / zero errors**. Both new receiver-reorg scenarios and the existing operational unfreeze race passed. The sole failure was the unrelated `SupplierPostgresConcurrencyTest::test_killed_queue_worker_retains_dispatch_episode_and_recovers_one_evidence_decision` at line 125 (`processed` expected 1, actual 0). A focused rerun reproduced it; a second run with the unfreeze service and status enum restored to their `HEAD` versions reproduced the identical failure (1 test / 8 assertions). The issue #576 source was then restored byte-for-byte. The full gate is therefore **not green** because of this existing supplier recovery failure; this patch does not change that subsystem. Local report and baseline log were retained at `/tmp/issue-576-concurrency-gate/`; the disposable PostgreSQL container was removed after verification.
