# Runbook: Balance restore, replay and rollback

Applies to PostgreSQL backup/PITR restore and to compatibility rollback of the Store Balance (IDR) and Customer USDT Balance release. Principles that never change:

- A restore rolls the **database** back; chain transfers, provider settlements and Telegram deliveries already happened. External side effects are never rolled back with the database.
- No ledger entry, receipt, claim, resolution or reservation is ever deleted, truncated or rewritten to "fix" a restore. Production restores must never `TRUNCATE` money tables ([ledger privileges runbook](runbook-usdt-ledger-privileges.md)).
- No automatic refund, no automatic credit and no automatic unfreeze is part of a restore.
- While the restore gate is open, new USDT funding and spending are blocked; settlement, refund, reversal and reconciliation of money already in the system keep running.
- Production deploys stay on GitHub Actions `main` → VPS (`deploy-production.yml`); nothing in this runbook is a Railway procedure.

## 1. Roles and time windows

| Step | Role | Window |
| --- | --- | --- |
| Declare the restore window and open the gate | Owner (or Operator acting for the Owner) | immediately after the incident is contained |
| Take/verify the backup, restore the database | Operator (database) | restore is the only destructive step |
| Replay external evidence | Operator | bounded; run within the same maintenance window |
| Reconcile | Operator | immediately after the replay, before reopening |
| Close the gate / reopen funding + spending | Owner | only after zero unexplained drift |
| Resume deliveries after manual review | Owner/Finance | per-effect, in Admin → Telegram outbound effects |

## 2. Restore drill / PITR procedure

1. **Contain** — stop schedulers and workers writing to the database (`docker compose stop scheduler worker` or scale the service down) so the lost window has a defined end.
2. **Record the window** — `window-start` is the instant the restored backup was taken, `window-end` is the moment writes stopped.
3. **Open the gate before serving traffic again**:

```bash
php artisan usdt-balance:restore-open \
  --window-start="2026-10-06T10:00:00+08:00" \
  --window-end="2026-10-06T10:30:00+08:00" \
  --reason="PITR restore after DB incident" \
  --actor=owner-a --json
php artisan usdt-balance:restore-status --json
```

4. **Restore the database** with the repository tooling (encrypted dump):

```bash
# production backup (already produced by the release process)
./scripts/backup-db.sh
# destructive restore; requires an explicit confirmation flag
RESTORE_CONFIRM=yes ./scripts/restore-db.sh --yes backups/<file>.sql.gz.gpg
# dry run first when validating the procedure
./scripts/restore-db.sh --dry-run backups/<file>.sql.gz.gpg
```

   PITR on the VPS follows the same order: restore to the target recovery point, then `php artisan migrate --force` (additive migrations only), then verify the gate row survived the restore with `php artisan usdt-balance:restore-status --json`. If the restored snapshot predates the gate table, open the gate immediately after the restore — do not resume funding first.

5. **Replay external evidence** for the lost window:

```bash
php artisan usdt-balance:restore-replay --limit=25 --actor=operator-a --json
# with RPC reachable, also observe transfers that arrived inside the window:
php artisan usdt-balance:restore-replay --limit=25 --scan --scan-limit=25 --scan-requests=32 --actor=operator-a --json
```

   The replay (a) re-inspects every non-terminal USDT funding target against authoritative chain evidence, (b) optionally runs the durable receiver scan (`usdt-bsc:scan`) so transfers inside the window are observed again, and (c) quarantines non-terminal balance deliveries as `unknown`. A failed inspection or a failed scan is recorded in the gate evidence and blocks reopening.

6. **Reconcile the restored database completely.** A single bounded page is never enough evidence for reopening:

```bash
php artisan usdt-balance:reconcile --complete --limit=500 --actor=operator-a --json
```

   `--complete` consumes every account, receipt and unreceipted-credit page inside one PostgreSQL repeatable-read snapshot. `--cursor`, `--receipt-cursor`, `--credit-cursor` and `--customer` are not valid with it. Resumed or partial runs remain useful diagnostics but cannot close the restore gate.

7. **Reopen** only with zero unexplained drift (the command refuses otherwise):

```bash
php artisan usdt-balance:restore-close --actor=owner-a --json
```

   `restore-close` requires a replay completed after the gate opened and a complete, beginning-to-end reconciliation run recorded after that replay with `verdict=consistent`, zero account findings, zero receipt findings and `unexplained_micro = 0`. The refusal messages name the missing step exactly (`run usdt-balance:reconcile --complete after the replay`, `still reports unexplained drift`, `evidence replay reported failed inspections`, `receiver evidence scan failed`).

8. **Resume deliveries deliberately** — see §3. Then restart schedulers/workers.


### Verification queries after any restore

```sql
-- Gate state and evidence
SELECT state, window_start, window_end, opened_by, opened_at, replay_completed_at, cleared_at
FROM balance_restore_gates WHERE id = 1;

-- Money history must be present, never deleted or rewritten
SELECT (SELECT count(*) FROM customer_usdt_balance_entries)   AS usdt_entries,
       (SELECT count(*) FROM external_money_receipts)         AS receipts,
       (SELECT count(*) FROM store_balance_entries)           AS idr_entries,
       (SELECT count(*) FROM usdt_receiver_address_claims)    AS receiver_claims;

-- No reopened money: resolutions/settlements stay immutable, no negative balances
SELECT count(*) FROM customer_usdt_balance_accounts WHERE balance_micro < 0;      -- expected 0
SELECT count(*) FROM usdt_balance_reconciliation_runs WHERE checked_at > now() - interval '1 hour';
```

## 3. Stale external side-effect replay (Telegram deliveries)

After a restore the database cannot prove whether a balance message was delivered inside the lost window. `usdt-balance:restore-replay` therefore moves every non-terminal balance delivery effect (`usdt.top_up_receipt`, `usdt.refund_receipt`, `balance.top_up_receipt`, `admin.balance_top_up_owner_alert`, `balance.owner_compensation_notification`, `balance.adjustment_notice`, `admin.balance_operations_alert`) to state `unknown` with code `restore_ambiguous_delivery`:

- `unknown` effects are never automatically retried, so a customer never receives the same receipt twice.
- They appear in **Admin → Telegram outbound effects** for manual delivery review: the operator checks Telegram and either marks the effect sent (with the real message id) or deliberately resends it as a new effect.
- Effects created **after** the restore point do not exist in the restored database at all; their external side effects are reconciled from the operator's Telegram history and provider records, never by re-running money flows.
- Money is untouched: no ledger entry, receipt claim or balance changes because of the quarantine.

```bash
php artisan usdt-balance:restore-replay --dry-run --json   # preview the quarantine count first
```

## 4. Receiver and token rotation (immutable snapshots)

Receiver rotation never rewrites history:

1. Register a new exclusive receiver: `php artisan usdt-receivers:register --customer-id=<id> --address=<0x…> --actor=<owner> [--manual-verified]`, then `php artisan usdt-receivers:verify --receiver=<id> --challenge=<id> --wallet-type=eoa --signature=<sig> --actor=<owner>`.
2. Retire the old one after it has no live invoice assignment: `php artisan usdt-receivers:retire --receiver=<id> --reason="rotation" --actor=<owner> --json`.
3. Existing `usdt_bsc_rail_invoices` keep their immutable receiver/token/network snapshot and are still inspected; retired receivers stay enrolled in the scan so late money is still observed (`monitoredReceivers` includes retired rows). A retired receiver is never reassigned to another Customer.
4. Token/contract changes follow the same rule: they are new snapshots for new invoices only; historical invoices keep verifying against their original contract/decimals.

## 5. Secret and credential rotation

- Provider/custody API credentials live in `ENV` (secrets policy) and rotate per provider runbook; rotation must not rewrite stored snapshots (`payment_setting_version`, receiver snapshots, invoice snapshots).
- The credential identity fingerprint key (`storage/app/private`) is a persisted operator secret: rotate with a re-key procedure, never by deleting the key file while Input Akun is enabled.
- Receiver ownership challenges are signed by the external wallet; the application stores public addresses and verification evidence only — no seed, private key or signing capability exists in the app, so a rotation never involves key material in the database.
- After any credential rotation, re-run `php artisan usdt-balance:reconcile --json` and confirm the alert episodes clear.

## 6. Compatibility rollback (preserving the registry)

1. **Pause first**: `funding` + `rail_bsc_usdt` (and `spending` if the release changed checkout). Rollback never starts with a code revert.
2. **Keep the registry**: do not drop or re-create `external_money_receipts`, its aliases/parent claims, reservations, ledger tables, `customer_usdt_top_up_resolutions` or the operational control tables. Every migration in this release is additive; production rollback is an image rollback, not a migration rollback.
3. **Deploy the previous image** through the existing GitHub Actions pipeline (`deploy-production.yml`) and verify `ops:health --db --queue-schema --queue-depth --supervisor`.
4. **Never reopen writers that bypass the registry**: if the previous image does not understand the receipt registry, keep USDT funding paused for the duration of the rollback window instead of letting old writers move money without a claim.
5. **Redis is not money authority**: a Redis restart/flush is not a reason to recreate invoices, credit balances or resend receipts. Durable work is rediscovered from PostgreSQL (`usdt-funding:recover`, `telegram:recover-outbound`).
6. **Nobody deletes money history during rollback**, and no step in this runbook issues an automatic refund.

```bash
php artisan usdt-balance:metrics --json        # confirm paused scopes and backlog
php artisan telegram:recover-outbound --chunk=25 --queue=notifications
php artisan usdt-funding:recover --limit=25 --json
```

## 7. Disposable restore drill evidence

`scripts/balance-restore-drill.sh` executes this whole procedure against a disposable PostgreSQL 16 container: it migrates a fresh database, seeds one Customer with a settled USDT credit, one pending balance delivery effect and one unsettled funding target, takes a `pg_dump`, destroys and restores the database, then runs `restore-open → restore-replay → reconcile → restore-close` and finally re-checks that the ledger/receipt/claim row counts and balances are exactly what the dump contained. It never touches production data:

```bash
scripts/balance-restore-drill.sh            # prints a sanitized JSON report
CONCURRENCY_POSTGRES_PORT=55433 scripts/balance-restore-drill.sh
```

## 8. Limits

- The gate governs **Customer USDT** funding and spending. IDR (Store Balance) operations during a restore must be paused through the existing Store Balance maintenance/pauses; the drift evidence used to reopen is USDT subledger + receipt + treasury evidence.
- The receiver scan (`--scan`) needs reachable, allowlisted BSC RPC. A failed scan is fail-closed: the gate stays open until the scan succeeds or the operator accepts the documented alternative (settlement replay without scan) and records it in the incident log.
- Quarantined `unknown` effects stay unknown until a human resolves them; this is intentional and never time-based.
- SQLite does not prove PostgreSQL restore behavior: the drill above is the evidence lane.
