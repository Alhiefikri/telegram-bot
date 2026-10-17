# Balance O3 release readiness and staged canary preparation

This document is the release-gate contract for #561. It prepares evidence and
operator steps; it does **not** authorize a production deployment, customer
funding, spending, or a live transfer. Those remain the human-operated #562
gate after every required check reports `pass`.

## Read-only release report

Run the report from the candidate image against its intended database after
the migrations and backfill rehearsal have completed:

```bash
cd src
php artisan balance:money-receipt-cutover-report --require-ready --json
php artisan usdt-balance:reconcile --limit=1000 --json
# Continue every printed account / receipt / credit cursor until all three
# `has_more` values are false. The final run must be verdict=consistent.
php artisan balance:release-readiness-report --candidate-sha="$(git rev-parse HEAD)" --evidence-manifest="$BALANCE_RELEASE_EVIDENCE_MANIFEST" --max-reconciliation-age=1440 --json
php artisan balance:release-readiness-report --candidate-sha="$(git rev-parse HEAD)" --evidence-manifest="$BALANCE_RELEASE_EVIDENCE_MANIFEST" --max-reconciliation-age=1440 --require-ready
```

`balance:release-readiness-report` is read-only and secret-free. It combines
the current receipt cutover, complete full reconciliation (ledger, receipt,
accounting and treasury), restore gate and USDT policy/readiness state with the
candidate-bound verification evidence below. `--require-ready` exits non-zero
for any `blocked` check. A full reconciliation is accepted only when its
bounded account, receipt and unreceipted-credit cursors are all exhausted; a
clean first page cannot be used as release evidence. The recorded run must be
no older than the explicit `--max-reconciliation-age` window.

The evidence manifest is an operator-recorded JSON index with
`schema_version: 1`, the exact 40-character `candidate_sha`, and `checks.v1`,
`checks.v2`, `checks.restore_drill`, and `checks.receipt_cutover`. Each result
must carry the same candidate SHA, `exit_code: 0`, a retained `artifacts/...`
reference, and its SHA-256. V2 must use the `full` profile. The report hashes
the retained files and blocks missing, failed, diagnostic, mismatched, or
unverifiable evidence; repository paths and fixed historical SHAs alone never
pass the check.

The report intentionally returns **NO-GO** on the current release because the
H1 custody/legal/token-provenance/receiver-capacity/treasury evidence is still
unqualified. It also confirms that `funding_enabled` is false and that no
qualified BSC funding readiness provider is registered. A closed tracker issue
or a passing synthetic test never converts either condition into activation
authority.

| Report check | Evidence and interpretation |
| --- | --- |
| `money_receipt_registry` | All known writers use the global receipt registry; every backfill source is ready and no reservation remains open. It is the mixed/cold-worker compatibility gate: drain old workers before an activation window. |
| `full_reconciliation` | Latest complete, fresh `usdt_balance_reconciliation_runs` row is `consistent`, with no remaining account/receipt/credit page. It covers ledger projection/cursor, receipt attribution, accounting aggregate and treasury result in one recorded run. |
| `restore_gate` | The post-restore gate is closed. An open gate always blocks new USDT funding and spending. |
| `release_evidence` | V1's PostgreSQL/crash matrix ([balance-v1-verification.md](balance-v1-verification.md)), V2's bounded workload ([balance-v2-benchmark.md](balance-v2-benchmark.md)), the existing restore drill, and the receipt-cutover command are part of the candidate evidence set. |
| `idempotent_idr_adjustment_control` | H0-v1's accepted single-Owner exception is recorded in [ADR-0020](adr/0020-single-owner-balance-adjustments.md); per-action password confirmation, lockout, immutable history and linked reversal remain mandatory. |
| `beta_allowlist` | H0-v1 deliberately selects all registered bot Customers and no quota/allowlist. This is an accepted decision, not a missing setting. |
| `primary_bsc_activation` | Fails closed until H1 and A1 qualifying evidence is complete. The current status is NO-GO. |
| `optional_binance_funding` | Disabled because C2C lacks authoritative beneficiary/reference binding. Its disabled state does not block a qualified primary BSC rail. |

The report carries only counts, IDs, sanitized reasons, and repository paths.
It must never include a receiver address, transaction hash, Customer/Telegram
identity, credential, raw provider payload, or an environment dump.

## Staging cutover and restore rehearsal

Run these only against a disposable staging or local database, never against
production as part of #561:

```bash
cd src
composer test:concurrency-gate
cd ..
scripts/balance-restore-drill.sh
scripts/balance-v2-benchmark.sh
```

The restore drill exercises additive migration, database dump/restore,
`restore-open → restore-replay → reconcile → restore-close`, and verifies that
ledger/receipt/claim history and balances are preserved while ambiguous
Telegram effects are quarantined. The V2 harness is the representative
performance and backlog evidence lane; smoke and quick profiles are only
harness diagnostics. Preserve the generated sanitized artifacts with the
candidate commit SHA and report their result rather than copying secrets or
customer data into an issue.

For a cold/old-worker rehearsal, deploy the candidate to a disposable mixed
worker pool only after the receipt report is ready. First drain workers that
can write an external-money effect without the registry; then start candidate
workers, run the report again, and verify there are no open registry
reservations, delivery backlog, or reconciliation findings. A rollback is an
image rollback only: retain every additive money table, pause `funding` and
`rail_bsc_usdt` first, and keep incompatible old writers paused. Follow the
full procedure in [runbook-balance-restore-rollback.md](runbook-balance-restore-rollback.md).

## Proposed #562 canary sequence

The following sequence is a prepared checklist for the authorized operator;
it is not executable authorization from this ticket.

1. Record the candidate commit/image, report JSON, dependency evidence, and
   an Owner decision to begin. Confirm the release report has no blocked check
   and that the production deployment workflow's image rollback path is ready.
2. Run the IDR adjustment canary first for an existing Customer found through
   exact numeric Telegram ID. Record the immutable preview, per-action
   password confirmation, audit row, ledger entry, accounting, and durable
   notification. Exercise a replay and, only when funds remain available, the
   linked full reversal. Reconcile the Store Balance and receipt registry with
   zero drift before continuing.
3. Only after an approved H1/A1 qualification update changes
   `primary_bsc_activation` to `pass`, enable one small-value USDT funding
   canary using a dedicated,
   permanently exclusive Customer receiver. Preserve authoritative finalized
   deposit evidence and verify one ledger credit, receipt claim, accounting
   event and receipt delivery. Test a late finalized deposit and a wrong-chain,
   wrong-token, wrong-receiver or wrong-amount case: each must stay unresolved
   or under review and must not create an available balance.
4. Keep funding and spending scoped to the approved canary Customer(s). After
   the funding evidence remains clean, perform one small full-price USDT spend
   and one verified refund entitlement. Verify the original-currency debit and
   linked refund cap, durable outbox, receipt/registry claim, and reconciliation
   result; no currency conversion or partial tender is allowed.
5. For the proposed seven healthy days, run a complete reconciliation and
   daily treasury backing check at least once per day. Record the candidate
   SHA, run ID, time, ledger liability, unresolved deposits, custody
   observation state, aggregate pending count, alert state, delivery/recovery
   backlog, and any pause/restore state. A missing run, stale evidence,
   unexplained variance, open receipt reservation, reorg incident, or alert is
   a NO-GO and pauses the next expansion.
6. The Owner signs off only after all seven records are healthy and the H2
   evidence includes real finalized success plus late/wrong cases. Expansion
   is incremental and retains the same daily backing/reconciliation checks.

## Rollback trigger and evidence

Pause new funding and spending before rollback when any release report check
changes to `blocked`, reconciliation finds drift, the custody observation is
unavailable/deficit, a receipt collision appears, or delivery/recovery backlog
does not drain. Preserve the report, reconciliation run IDs and sanitized
diagnostics; do not delete ledger, receipt, claim, reservation, resolution, or
outbound history. The existing rollback runbook governs replay and reopening.

## Customer API qualification boundary (S11 #628)

[S11 integrated acceptance](customer-api-s11-acceptance.md) records disposable
cross-surface financial/security/recovery evidence, local query plans and
measured HTTP diagnostics for the Customer API. It fixes the API Cart
FK/Product lock inversion and removes sold-out audience expansion from the
money transaction by using the existing durable stock handoff.

This report is not a candidate-bound V1/V2 full-profile manifest, a production
capacity acceptance, H1/A1/provider/custody qualification, H2 live-money
evidence or an Owner activation decision. The proposed API admission rates
remain unqualified for production. Financial/API exposure stays NO-GO until
the existing operator/provider gates and intended-runtime capacity review
pass; no closed legacy ticket or synthetic provider response waives them.
No production readiness command, CI run, deployment or setting change was
performed as part of S11.

