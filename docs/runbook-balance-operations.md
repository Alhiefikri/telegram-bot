# Runbook: Balance operations — pauses, alerts, metrics, incidents

Applies to the Store Balance (IDR) and Customer USDT Balance (micro-USDT) subsystems. Every command below runs inside the application container/scheduler host; none of them takes money authority, deletes ledger/claim history or performs an automatic refund.

Roles used below: **Owner** (all operational controls, Admin Dashboard + per-action password where required), **Finance/Support** (read-only views), **Operator** (the human executing CLI commands, recorded as `--actor`).

## 1. Pause scopes

Pause state is one row per scope in `customer_usdt_funding_pauses` (presence = paused), owned by `CustomerUsdtFundingPolicyManager` and audited (`customer_usdt.funding_pause.created` / `.deleted`). The sanctioned write path is the Admin Dashboard → **Stats** tab → *Kebijakan Top-up USDT* card (`admin.store-balance.usdt-pauses.store` / `.destroy`, Owner only).

| Scope | Blocks | Never blocks |
| --- | --- | --- |
| `funding` | new USDT funding targets (reservation + confirmation) | settlement, late money, refund, reversal, reconciliation, delivery |
| `rail_bsc_usdt`, `rail_binance_pay` | new targets on that rail | other rails, money already observed |
| `spending` | new USDT checkout debits | credits, refunds, reconciliation |
| `delivery` | claiming/dispatching balance receipt messages (`usdt.top_up_receipt`, `usdt.refund_receipt`, `balance.top_up_receipt`) | the money effect and its durable effect row, operator alerts, every other Telegram family |

Delivery pause semantics: receipt effects stay `pending` with `attempts = 0`; nothing is resent and nothing is lost. On resume the same effect (same `effect_key`, no duplicate) is claimed and delivered. A claim refused while the pause is active records the bounded metric `outbound_delivery_outcome=skipped_paused` for both the IDR and USDT receipt pipelines (see §3).

Effective windows: funding/spending/rail pauses take effect inside the next availability check (the reservation transaction serializes on the policy row). The delivery pause is re-read on every claim, so it applies to already-running queue workers on their next claim.

Verify:

```bash
php artisan usdt-balance:metrics --json          # controls.pauses
php artisan tinker --execute="dump(app(App\\Services\\UsdtFunding\\CustomerUsdtFundingPolicyManager::class)->pauses());"
```

Break-glass (database unreachable through the Dashboard; bypasses the audit log, record the incident manually): `INSERT INTO customer_usdt_funding_pauses (scope, reason, paused_by_user_id, paused_at, created_at, updated_at) VALUES ('funding', '<reason>', NULL, now(), now(), now());` and `DELETE FROM customer_usdt_funding_pauses WHERE scope = 'funding';`.

## 2. Durable operational alerts

`ops:balance-alerts` (scheduled every 5 minutes) evaluates durable evidence, keeps one episode row per bounded `alert_key` in `balance_operations_alert_episodes`, and materializes one durable Telegram Outbound Effect (`admin.balance_operations_alert`) per new/changed episode against the configured admin chat. Delivery is retried and recovered by `telegram:recover-outbound`; a failed/unknown alert delivery is visible at **Admin → Telegram outbound effects**.

```bash
php artisan ops:balance-alerts --json          # evaluate now, print counts
php artisan telegram:recover-outbound --chunk=25 --queue=notifications
```

Manual evaluation is idempotent: running it twice does not duplicate an effect for the same episode + fingerprint. When no admin chat id is configured the episode still opens and `delivery_pending` counts it; configure the admin target, then re-run.

| Alert key | Severity | Trigger | First response |
| --- | --- | --- | --- |
| `reconciliation_drift` | critical | latest full reconciliation run has account findings | inspect the run findings, keep the drifted account frozen, follow §4.4 after repair |
| `reconciliation_receipt_collision` | critical | latest full run has receipt findings | §4.3 |
| `treasury_coverage` | critical | liability > 0 with missing/unavailable custody evidence, or unexplained variance | §4.2 |
| `reorg_loss_unresolved` | critical | `usdt_reorg_incidents` rows exist | §4.1 |
| `receipt_registry_conflict` | critical / warning | open `registry_conflict` reservations (critical) or receipts under review (warning) | §4.3 |
| `top_up_work_backlog` | warning > 10 min, critical > 30 min | oldest due preparation work | check BSC RPC reachability, `usdt-funding:recover` |
| `settlement_backlog` | warning > 10 min, critical > 30 min | oldest due settlement work | check RPC/rail readiness, then `usdt-funding:recover` |
| `outbound_backlog` | warning > 5 min, critical > 15 min | oldest non-terminal balance delivery effect | check Telegram delivery + `telegram:recover-outbound`; a `delivery` pause explains it |
| `restore_gate_open` | critical | `balance_restore_gates.state = 'open'` | §3 of the restore runbook |
| `delivery_paused` | warning | `delivery` pause row exists | confirm the pause is intentional |
| `account_frozen` | warning | any `frozen_reason` on a USDT account | §4.4 |

Time windows: evaluation every 5 minutes; thresholds as tabled; episode resolution notice is sent once when the condition clears.

## 3. Bounded metrics

```bash
php artisan usdt-balance:metrics --json                    # 60-minute window
php artisan usdt-balance:metrics --window-minutes=1440 --json
php artisan usdt-balance:metrics --prune-days=30 --json    # bounded retention prune (scheduled daily)
```

`counters` are append-only rows in `balance_operation_events` (`UPDATE` is rejected for every role; `DELETE` is reserved for the retention prune). Cardinality is closed by construction: metric/outcome come from `App\Enums\BalanceOperationMetric`, `bucket` is one of `lt_50ms`, `lt_250ms`, `lt_1s`, `lt_5s`, `gte_5s`, and no customer id, Telegram id, address, transaction hash or secret is ever stored. Recorded coverage: ledger mutation outcome, database lock retry (deadlock/serialization with a duration bucket), receipt claim outcome, funding reservation outcome, balance delivery outcome. `backlog`, `drift`, `coverage`, `conflict` and `controls` are read-only queries against the durable tables (no cache).

Instrumented retry seams: `CustomerUsdtBalanceLedger`, `ExternalMoneyReceiptClaims`, `CustomerUsdtFundingReservationService` and the operator-control services. Retries are recorded after the transaction ends, so a metrics insert can never participate in a money transaction.

## 4. Incident runbooks

### 4.1 Reorg incident (credited funding lost canonical evidence)

1. Confirm the customer account freeze is `usdt_reorg_incident:<id>` and that `usdt-balance:reconcile --json` reports `reorg_incident_count > 0` / alert `reorg_loss_unresolved`.
2. Do **not** delete ledger entries, receipts or resolutions, and do not debit the customer. The freeze is the protection.
3. Re-run the recovery sweep: `php artisan usdt-funding:recover --limit=25 --json` (re-derives canonical evidence, keeps original receipts).
4. Owner decision: request + password-confirm an authorized recovery case (U8 `UsdtReorgIncidentService`), which records `authorized_unresolved`; the loss stays visible as unresolved treasury exposure.
5. Reopening: `usdt-balance:unfreeze` refuses any account whose freeze reason starts with `usdt_reorg_incident:` or `usdt_receiver_reorg:`, that has an incident row, or whose Customer has durable receiver reorg evidence (including retired receivers). Scanner evidence keeps the hold before the monitor creates an incident and when a pre-existing drift freeze hides the reorg marker. The freeze is cleared only after the loss is resolved through the authorized process, never by clearing a string.

### 4.2 Treasury deficit / coverage shortfall

1. Alert `treasury_coverage` or `TREASURY verdict=deficit` from `usdt-balance:reconcile`.
2. Pause new exposure: `rail_bsc_usdt` (and `funding`) from the Dashboard.
3. Verify custody input: the treasury asset provider tag `usdt-treasury-asset-providers` must contain the approved adapter. An empty tag means *no custody evidence*, never zero assets.
4. Record audited bookkeeping for real movements: `php artisan usdt-balance:record-treasury-movement --direction=in|out --amount=<decimal USDT> --reference=<tx hash> --reason=<category> --actor=<operator>`.
5. Re-run `php artisan usdt-balance:reconcile --json`; the ledger is never repaired or auto-corrected. Escalate to the H1 custody decision owner.

### 4.3 Receipt collision (one external transfer claimed twice)

1. Detection: alert `receipt_registry_conflict`, `external_money_receipts.status = 'review'`, a `external_money_receipt_parent_claims` row under review, or a reservation with reason `registry_conflict`.
2. Pause `funding`. Never re-claim the same money to a second target and never convert a held receipt into a credit.
3. Inspect the reservation/receipt rows (`external_money_receipt_reservations`, `external_money_receipts`, `external_money_receipt_parent_claims`); the evidence is sanitized and append-only.
4. Resolve after review: `php artisan balance:resolve-money-receipt-reservation <id> --note="<review note>" --actor=<operator>`.
5. Confirm with a fresh full reconciliation run before resuming funding.

### 4.4 Frozen USDT account (drift or incident)

```bash
php artisan usdt-balance:unfreeze --customer=<id> --reason="drift repaired" --actor=<operator> --dry-run --json
php artisan usdt-balance:unfreeze --customer=<id> --reason="drift repaired" --actor=<operator> --json
```

The command locks the account row and re-inspects it: a remaining drift (`status=drift_remains`) or any receiver reorg marker/evidence or incident (`status=reorg_hold`) keeps the freeze. Only recognized `reconciliation_drift:<issue>` reasons are eligible; every other reason stays held (`status=unsupported_freeze`). Dry-run applies the same checks. A successful unfreeze clears only `frozen_reason` and writes audit action `usdt_balance.account_unfrozen`; projection balances, entries and receipts are never written.

## 5. Scheduled cadence (owner: this ticket)

| Command | Cadence |
| --- | --- |
| `usdt-balance:reconcile --limit=500 --json` | every 5 minutes, mutex 10 min |
| `usdt-balance:aggregate-accounting --limit=2000 --json` | every 5 minutes, mutex 10 min |
| `ops:balance-alerts --json` | every 5 minutes, mutex 10 min |
| `usdt-balance:metrics --prune-days=30 --json` | daily |
| `usdt-funding:recover --limit=25 --json` | every minute (unchanged, U6/U7) |
| `telegram:recover-outbound --chunk=25 --queue=notifications` | every minute (unchanged) |

A full-scope reconciliation sweep beyond the scheduled window is an explicit operator action:

```bash
php artisan usdt-balance:reconcile --limit=1000 --json
# continue with the printed cursors: --cursor= --receipt-cursor= --credit-cursor=
```

## 6. Limits

- SQLite/synchronous tests prove behavior, not PostgreSQL locking; only the disposable PostgreSQL lane does.
- The scheduled reconciliation covers one bounded window per facet; it is not a full-history scan on every tick.
- Delivery pause defers messages only; it never suppresses settlement, refund or alerting, and it never drops an effect.
- Alerts carry counts, run ids and scope names only — never customer identity, addresses, hashes or secrets.
