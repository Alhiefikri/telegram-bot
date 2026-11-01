# P11 verification records

Issue [#692](https://github.com/Alhiefikri/telegram-bot/issues/692); canonical
[#679](https://github.com/Alhiefikri/telegram-bot/issues/679). Every command ran
against P01-owned disposable PostgreSQL/Redis and local Docker on this host. No
production target, customer, provider, webhook, deployment, real-money operation
or CI/CD run was used. The frozen contract is in [README.md](README.md);
sanitized artifacts and checksums are in [evidence/](evidence/).

## Environment

- Host driver: Python 3.14, standard library only.
- Candidate image: Python 3.13.18 / Django 5.2.18 (`lhie-p05-candidate:1`).
- Comparator image: existing `lhie-p01-laravel:1` from this revision.
- PostgreSQL 16 (disposable, `max_connections=30`, `shared_buffers=128MB`) and
  Redis 7 inside the P01 2 CPU / 2048 MiB namespace, no container swap.
- Synthetic seed: large profile 20 categories / 120 families / 360 products /
  200 customers / 600 Orders+Payments / 9.616 stock items. Logical SHA256
  `68c6c859de3e25ecadaedd28a04bc4455fdb7177f5c8a33bda28e47d4cb2c08e`
  observed identically by the PHP and Django runs.

## Structural smokes

| Command | Observed |
| --- | --- |
| `runner.py --protocol p11 --implementation django --smoke` | `complete`, 6 implemented scenarios at small/1 user, 12/12 success, 0 errors/timeouts/unknowns, 6 authority audits with 0 changed tables, cleanup `reset`, seed SHA `fd5a8a4c…` matching P04 |
| `runner.py --protocol p11 --implementation laravel --smoke` | `complete`, 10 current-PHP flows, 20/20 success, 0 errors, cleanup `reset` |

## Paired measurement

| Run | Wall | Cells | Attempts | Measured outcomes |
| --- | ---: | ---: | ---: | --- |
| PHP baseline | 51 min | 24/24 complete | 1.440 (1.200 measured) | 1.200 success, 0 error/timeout/unknown |
| Django candidate | 91 min | 18/18 complete | 1.080 (900 measured) | 885 success, 15 error, 0 timeout/unknown |

Both runs completed with `disposable_cleanup = reset` and no `runner_failures`.
The Django run performed **72 authority audits**: zero changed public tables
(`customers` is the only excluded table: the admitted narrow profile write).
Orders, payments, stock, balance entries, effects, reviews, receipts and history
were byte-identical before/after every batch.

PHP evidence-quality gate: 9 exploratory / 15 inconclusive cells, all
inconclusive reasons `unstable_batch_p95` (descriptive batch spread above 20%).
Django: 4 exploratory / 14 inconclusive; 11 `unstable_batch_p95`, 3 also
`non_success_attempts` (the 15 recorded errors below).

### Per-case result

Ratios are Django ÷ current PHP. A case is judged only when both cells are
exploratory with complete batches; otherwise `inconclusive`. Units are ms.

| Scenario | Users | PHP p50 | Django p50 | % | PHP p95 | Django p95 | % | Verdict |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| home_catalog | 1 | 70.88 | 105.39 | – | 433.63 | 290.58 | – | inconclusive |
| home_catalog | 5 | 268.85 | 677.54 | – | 653.50 | 1.643.71 | – | inconclusive |
| home_catalog | 10 | 739.72 | 736.58 | – | 1.655.25 | 1.280.92 | – | inconclusive |
| catalog_plans | 1 | 89.21 | 208.32 | – | 390.28 | 439.17 | – | inconclusive |
| catalog_plans | 5 | 417.64 | 653.63 | – | 1.410.70 | 1.329.77 | – | inconclusive |
| catalog_plans | 10 | 1.109.30 | 1.537.10 | **138.6%** | 2.004.42 | 2.882.55 | **143.8%** | **fail** |
| plan_detail | 1 | 97.42 | 142.53 | **146.3%** | 499.26 | 280.62 | 56.2% | **fail** |
| plan_detail | 5 | 460.71 | 502.92 | – | 1.352.72 | 1.458.61 | – | inconclusive |
| plan_detail | 10 | 1.032.19 | 1.618.70 | – | 2.775.36 | 2.899.52 | – | inconclusive |
| back | 1 | 83.09 | 205.75 | – | 420.66 | 554.16 | – | inconclusive |
| back | 5 | 478.44 | 600.46 | – | 1.697.10 | 1.410.92 | – | inconclusive |
| back | 10 | 1.419.80 | 524.83 | – | 2.391.01 | 1.253.09 | – | inconclusive |
| home | 1 | 82.01 | 162.89 | – | 693.18 | 440.06 | – | inconclusive |
| home | 5 | 415.95 | 307.09 | – | 1.225.25 | 1.130.49 | – | inconclusive |
| home | 10 | 933.21 | 1.117.43 | – | 2.036.70 | 2.218.52 | – | inconclusive |
| quantity | 1 | 76.98 | 261.12 | **339.2%** | 353.89 | 543.89 | **153.7%** | **fail** |
| quantity | 5 | 346.31 | 718.68 | – | 1.157.34 | 1.905.25 | – | inconclusive |
| quantity | 10 | 918.23 | 768.32 | – | 3.021.04 | 2.166.32 | – | inconclusive |
| orders / balance | 1, 5, 10 | retained PHP only | none | – | – | – | – | not_implemented |

**Every quality-qualified case (both cells exploratory) failed the 70% target.**
No paired case passed. The 15 inconclusive cases are not averaged away and are
not counted as passes. The six Orders/Balance cases have no candidate surface;
their PHP measurements remain retained evidence only.

### Engine recommendation

`compare.py` produced `decision = fix` with reasons
`case_below_quality_target`, `inconclusive_evidence`,
`candidate_feature_absent`. `owner_decision = false`; H1 owns continue/fix/stop.

### PHP-baseline reuse (source identity)

The PHP baseline completed before the final probe bookkeeping fix. Source maps
contain 2.482 files; exactly two differ:

- `tools/migration_benchmark/candidate_probe.py` (Django-only probe), and
- `tools/migration_benchmark/runner.py` (added `fresh_clone` phase plumbing and
  evidence naming; the PHP payload gains one ignored key).

`php_measurement_path_delta` is **empty**: `probe.php`,
`protocol_p11.json`, `src/**` and `tools/migration_harness/**` (2.356 files) are
byte-identical. `compare.py` refuses any run whose PHP measurement path differs
and records the delta in `identity`/`baseline_reuse`. The PHP samples are reused
under the issue's explicit "reuse applicable PHP evidence" rule; no PHP rerun was
performed.

### Recorded non-success attempts

15 measured Django attempts (home_catalog users=10: 6; back users=10: 7;
catalog_plans users=5: 2) have a complete ingress → screen ACK timeline but were
classified `error`. Targeted reproduction (small dataset, home_catalog users=10,
same clone) recorded outcomes `{applied: 21, duplicate: 4}` and **no handler
exception**: the sweep processed the row and a stale wakeup later re-entered as a
duplicate no-op, overwriting the terminal outcome used by the probe. The probe now
keeps the first terminal outcome and records handler error types. The 15 attempts
stay exactly as recorded; their cells remain inconclusive. See
[reproduction.json](evidence/reproduction.json).

## Post-gate follow-up fix (outside the measured identity)

Two defects found by the gate were fixed after the measured runs; neither changes
the published samples or the `fix` verdict for the measured revision.

1. **Probe bookkeeping:** duplicate no-op wakeups no longer overwrite the first
   terminal outcome, and handler exception types are recorded privately. The 15
   recorded attempts stay as recorded; their cells remain inconclusive.
2. **Catalog read path:** `navigation/repository.py` now uses one shared Product
   projection for catalog browsing and targeted Product/Family lookups. Detail,
   quantity and Back renders no longer re-scan every active Product with its
   correlated stock/order subqueries. Catalog browsing still loads the active
   catalog for filtering and pagination; presentation code and its PHP parity are
   unchanged. `MemoryCatalog` in `test_catalog_presentation.py` implements the
   two public lookups it substitutes.

Verification of the fix (not a gate rerun): 46 focused candidate tests passed;
`--protocol p11 --implementation django --smoke` completed 6/6 cells with 12/12
success, zero errors and six authority audits with zero changed tables. The
smoke is two attempts per cell: p50 moved −32% to +99% across scenarios
(home_catalog improved, home regressed), so it gives **no performance claim**.
A future paired gate must use a new run identity; no result here is pooled with
the measured run.

Still not fixed because they are product features, not P11 defects: Orders and
Balance navigation (Balance includes funding/money flows) and the candidate
checkout→paid→receipt surface. Those need separate issues, grant/authority
review and H1 authorization before another gate.

## Focused regressions

| Check | Observed |
| --- | --- |
| `python3 -m unittest discover -s tests/migration_harness` | 87 tests passed |
| P11-specific (`test_p11_probe`, `test_p11_compare`) | 13 tests passed |
| `python3 -m py_compile tools/migration_benchmark/*.py` | passed |
| Paired-report mismatch refusals | dataset SHA, protocol, smoke, implementation, PHP measurement-path delta, missing authority audit, critical finding → all covered |

## Reused evidence (not rerun)

- P06 navigation E2E/parity and the operator-confirmed Testing Bot visual QA
  (`@LhieStoreTestBot`, photo/animation/premium-emoji screens, stale-callback and
  returning `/start` checks). P11 changed no candidate UI and ran **no new live
  Testing Bot or device session**.
- P07/P09/P10 disposable PostgreSQL money, stock, reservation, effect-fencing and
  worker-recovery drills. No candidate business module changed; the 72 batch
  audits independently prove navigation cells made no public money/stock/
  effect/history writes.
- P04 retained schema-4 comparator evidence keeps its own identity and is not
  pooled with P11 samples.

## Payment stages and budget proposal

No paired payment stage exists: the candidate has no navigation-to-checkout,
paid-detection or credential-delivery surface. PHP-only retained P04 diagnostic
(small, 1 user, warm/distinct/idle, one batch of 2 attempts — weak by design):

| Boundary | PHP diagnostic p95 | Proposed unratified budget (70%) |
| --- | ---: | ---: |
| checkout_ready (checkout cell) | 2.381,4 ms | 1.667,0 ms |
| checkout_ready (paid_delivery cell) | 1.032,6 ms | 722,9 ms |
| paid_observed | 1.265,8 ms | 886,1 ms |
| paid_committed | 283,8 ms | 198,7 ms |
| receipt_queued | 0,7 ms | 0,5 ms |
| receipt_pickup | 378,2 ms | 264,8 ms |
| credential_ack | 1.368,8 ms | 958,2 ms |
| group_complete | 1.371,5 ms | 960,1 ms |

Status `proposed_unratified`: H1 must ratify or replace these after a qualified
PHP checkout/paid run and candidate parity. No Django payment result was
observed, so nothing here is a post-hoc candidate target.

## Limits

1. Backend ACKs only. PHP `screen_ack` is an in-process `Http::fake` response;
   Django uses the P01 loopback relay and the admitted single-threaded WSGI
   request path, so Django includes a loopback call PHP does not. This is
   conservative against the candidate, but it is a different transport boundary.
2. PHP ingress is concurrent CLI HTTP-kernel clients; Django ingress is loopback
   WSGI. Neither is Nginx/FPM or production capacity.
3. `users` is concurrent submission against one interaction worker per
   implementation; queueing dominates at 5/10 users. It is not a production
   arrival model.
4. Background is `idle` on both sides. PHP provider-verification and notification
   families have no candidate counterpart, so representative per-wave jobs were
   not injected; retained P04 representative-background evidence is not pooled.
5. Batch p95 spread above 20% marks a cell inconclusive. 15/24 PHP and 14/18
   Django cells were inconclusive; the per-batch range is descriptive, not a
   confidence interval. No auto-extension or pass-hunting rerun was done.
6. Django `db_ms` includes per-request connection establishment
   (`CONN_MAX_AGE=0`); database numbers are descriptive, never the gate.
7. Synthetic dataset and local Docker only. No production data, key, provider,
   real money or HD acquisition. Visible latency, live Testing Bot and sandbox
   qualification remain separate H1 obligations.
8. The 15 duplicate-outcome attempts are an acknowledged probe artifact, not a
   candidate handler failure; their cells remain inconclusive.
9. Two unrelated `src/` files (`SupplierProvider` enum and its regression test)
   were modified in the working tree at 17:12 local during the Django run. Both
   run identities had already captured their source maps, and their PHP
   measurement-path hashes agree; the measured source is the captured identity.
   P11 did not modify or revert those files.

## Unresolved findings

| Code | Severity | Finding |
| --- | --- | --- |
| `django_navigation_performance_target_not_met` | high | Every quality-qualified paired case fails the 70% p50/p95 target; no case passes. |
| `candidate_orders_balance_absent` | medium | Orders and Balance handlers are not ported; 6/24 navigation cases are `not_implemented`. |
| `candidate_payment_stages_absent` | medium | No candidate checkout/paid/receipt surface; no paired payment timing or ratified budget. |
| `probe_duplicate_outcome_artifact` | low | 15 attempts recorded as error by duplicate no-op wakeups after a screen ACK; probe corrected, attempts retained, cells inconclusive. |

No unresolved critical/high security finding is recorded by the
[security review](evidence/security-review.json); critical/high findings would
force `stop` in the paired report.

## Context7 references

See [README.md](README.md#context7-references). Django 5.2
`execute_wrapper` (thread-local context manager) and test-client WSGI environ
defaults were used as documented; PostgreSQL lock behaviour is unchanged from
the accepted P06–P10 owners.
