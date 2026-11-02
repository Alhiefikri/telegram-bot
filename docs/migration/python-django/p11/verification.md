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

### Read-only Orders port (#717)

`navigation/repository.py` now exposes one customer-scoped successful-Orders
keyset read: paid `processing`/`completed` Orders, newest first by
`(created_at, id)`, at most ten rows plus one lookahead. It selects only the
columns the screen renders and never loads order history into memory.
`navigation/presentation.py` routes `menu:orders`, `orders:open` and the PHP
`orders:older`/`orders:newer` cursors, reuses the PHP invoice/status labels and
Back/Home chrome, and leaves order detail/payment actions unhandled rather than
fabricating a screen. Grants add only the missing SELECT columns on `public.orders`.

Verification of this follow-up (not a gate rerun):

| Check | Observed |
| --- | --- |
| Focused candidate unit lane | 47 tests passed (`test_catalog_presentation`, `test_navigation_ingress`, `test_http`, `test_runtime`) |
| P11 contract/regression lane | 13 tests passed (`test_p11_probe`, `test_p11_compare`) |
| Harness regression lane | 87 tests passed |
| PHP↔candidate Orders fixture and PostgreSQL smoke | `python3 tools/migration_harness/smoke_navigation.py` — `passed`; PHP parity for Home/catalog/Family/detail/Orders, first + older Orders pages, stale callback, Back/Home, grant denial and real PostgreSQL keyset order |
| P11 structural smoke | `.local/migration-p11/django-smoke-orders` — `complete`, 7/7 cells, 14/14 successful attempts, 7 authority audits with 0 changed tables/violations; the `orders` cell was 2/2 success |

The Orders unit lane covers first/second page keyset round-trip, equal-status
label selection, id/en/ms empty copy, malformed/foreign/invalid cursors, DB icon
fallback, and the explicit refusal to render an order-detail callback. The P11
contract plans `orders` at users 1/5/10; the short structural smoke exercises its
single-user protocol cell, and #722 owns the measured 1/5/10 rerun. No
performance claim follows, and no sample is pooled with the measured #692 run.
The local candidate image for this follow-up was assembled from the existing
pinned P05 candidate base plus the current working tree because the canonical
dependency rebuild could not finish against the slow package network; the smoke
result records the current source hashes and image identity.

### Read-only Balance port (#718)

`navigation/repository.py` reads the IDR projection and newest ledger entry in
one statement, applies the USDT cursor-aware health check, and exposes one
customer-scoped IDR ledger keyset read over `(created_at, id)` with a ten-row
page plus one lookahead. `navigation/presentation.py` renders the PHP Saldo
header, USDT balance/frozen/unavailable line, merged recent ledger rows, the
read-only IDR history and its signed customer-scoped older/newer cursors. It
reuses the PHP `balance`, `balance:add`, `balance:activity`, `balance:a:o|n` and
`balance:a:d` identities; funding and detail actions are rejected with an
explicit localized notice and never produce a surface or a balance write.
Grants add only SELECT columns on the two balance/ledger pairs; no INSERT,
UPDATE or DELETE authority exists on balance, ledger or payment rows.

Verification of this follow-up (not a gate rerun):

| Check | Observed |
| --- | --- |
| Focused candidate unit lane | 53 tests passed (`test_catalog_presentation`, `test_navigation_ingress`, `test_http`, `test_runtime`) |
| P11 contract/regression lane | 13 tests passed (`test_p11_probe`, `test_p11_compare`) |
| Harness regression lane | 87 tests passed |
| PHP↔candidate Balance fixture and PostgreSQL smoke | `python3 tools/migration_harness/smoke_navigation.py` — `passed`; PHP parity for Home/catalog/Family/detail/Orders/Saldo and the first + older Saldo history pages, merged recent IDR/USDT ledger rows, stale/foreign callbacks, funding/detail refusal, grant denial and real PostgreSQL keyset order |
| P11 structural smoke | `.local/migration-p11/django-smoke-balance-final` — `complete`, 8/8 cells, 16/16 successful attempts, 8 authority audits with 0 changed tables/violations; the `balance` cell was 2/2 success |

The Balance unit lane covers projection drift and frozen/unavailable honesty,
IDR/USDT recent-row merge order, exact IDR/USDT amount and state copy, id/en/ms
labels, DB icon fallback, first/second page keyset round-trip, foreign/tampered/
malformed cursor rejection, and the explicit refusal of funding and activity
detail callbacks. The P11 contract now plans all eight navigation scenarios at
users 1/5/10 with zero declared navigation gaps; the short structural smoke
exercises the single-user `balance` cell, and #722 owns the measured 1/5/10
rerun. Top-up targets, funding actions and activity details remain #719 scope;
no performance claim follows and no sample is pooled with the measured #692 run.
The local candidate image for this follow-up was assembled from the pinned P05
candidate base plus the current working tree because the canonical dependency
rebuild could not finish against the slow package network; the smoke result
records the current source hashes and image identity.

### Minimum IDR funding/top-up (#719)

`funding/` now owns one DB-backed `store_balance_funding_policy` read, the exact
PHP `balance:p:<version>:<identity>` preset signature, one pending intent per
Customer, the existing `store_balance_top_up*` rows and a sandbox-shaped
Sakurupiah provider seam. No second ledger or provider lifecycle was added:
`StoreBalanceLedger.credit_top_up()` remains the only IDR credit owner, and
`payments/receipts.py` is the shared global provider-reference claim also used
by P08 checkout. Provider calls run outside transactions; settlement re-locks
the Customer, account, top-up and receipt before posting. The provider/request
factory is absent from the default worker, so the rail is inactive and fails
closed without a balance write. `funding/grants.sql` is applied explicitly after
the #718 read-only proof and never at startup.

Verification of this follow-up (not a gate rerun):

| Check | Observed |
| --- | --- |
| Focused candidate unit lane | 64 tests passed (`test_funding`, `test_catalog_presentation`, `test_navigation_ingress`, `test_http`, `test_runtime`, `test_p08_callback`) |
| PostgreSQL 16 disposable fake-provider smoke | `python smoke_funding.py` — `passed`; one intent/confirm/top-up, a pending provider observation left the balance unchanged, one ledger credit and one receipt claim, duplicate check unchanged, synchronized two-process check race still one credit, second target reusing the same provider reference held as `invalid_provider_result` with no second credit |
| Runtime grant denial | `provider_payload`, immutable ledger amount, ledger DELETE, receipt status and account creation columns denied with SQLSTATE `42501` |
| Provider safety | fake only; QRIS URL restricted to `sakurupiah.id` HTTPS, total must equal amount + observed fee, expired provider invoice rejected |

The smoke used a local disposable PostgreSQL 16 database, Redis and the pinned
`lhie-p05-candidate:1` image on host-network loopback; it was not the P01
namespace harness and makes no performance claim. `smoke_navigation.py` now
applies the funding grants only after its read-only proof and runs the funding
probe as a separate step, so the #718 navigation authority remains SELECT-only.
Full QRIS/manual/compensation/reconciliation remains P15, and USDT/Binance
funding remains P17.

**Approval is not claimed.** No Owner/H1/ADR approval is recorded by this
change, the provider seam is inactive in the default worker, and no payment
stage was measured or added to the protocol. ADR-0019 remains Proposed; H1 and
the Owner must record activation approval (and P15 must qualify the provider/
credentials) before this slice can be activated or measured.

The #720 follow-up below closes the candidate checkout→paid→receipt surface
behind the navigation detail pay callbacks. It does not retroactively change the
#719 approval statement or the measured #692 identity.

### Navigation checkout, payment and receipt (#720)

`candidate/lhie_candidate/checkout/navigation.py` is a bridge above the existing
owners, not a new payment writer. A `detail:pay:qris` or `detail:pay:balance`
selection locks the Customer, revalidates the admitted navigation root, reuses
or creates exactly one canonical Cart item for the selected Product/quantity,
then delegates to P07 `CheckoutService` with its existing preflight/locks/pricing/
inventory rules. The QRIS rail uses the P08 `SakurupiahLifecycle` for one sandbox
invoice and authoritative `refresh`/`recover`; the Balance rail uses
`StoreBalanceLedger` and the existing spending/restore gates. `checkout:check:
<payment-id>` observes the same Payment, never creates a second invoice, and
settlement wakes the existing P09 receipt materializer. The presenter keeps the
PHP `detail:pay:qris|balance` identities and renders `id`/`en`/`ms` copy through
the existing catalog keys, the primary button convention and DB icon slots. The
only new write authority is Cart/CartItem insert plus the already-reviewed
P07/P08/P09 columns; provider references/totals/status still have one owner.

Verification of this follow-up (not a gate rerun):

| Check | Observed |
| --- | --- |
| Focused candidate unit lane | 70 tests passed (`test_navigation_checkout`, `test_catalog_presentation`, `test_navigation_ingress`, `test_http`, `test_runtime`, `test_funding`) |
| P11 contract/regression lane | 14 tests passed (`test_p11_probe`, `test_p11_compare`) |
| PHP↔candidate navigation/checkout smoke | `python3 tools/migration_harness/smoke_navigation.py` — `passed`; the PHP parity, read-only navigation and #719 funding stages passed first, then the #720 probe completed |
| QRIS fake-sandbox flow | one Order/Payment, `provider_creates = 1` after replay, `paid_observed` settled once, one external receipt claim, one receipt effect, `credential_ack = sent`, `group_complete = true`, second delivery `not_ready` |
| Store Balance flow | one completed/paid Order, balance debited exactly once, one receipt effect, duplicate delivery `not_ready` |
| Same-key race | two concurrent threads, one Order, one Payment, same Order identity, zero errors |
| Authority denials | six runtime statements rejected with SQLSTATE `42501`: order total, payment amount, product price, receipt status, ledger delete and CartItem quantity |
| Runtime source hashes | 63 files recorded in `.local/migration-p06/verification.json`; no production/CI/provider operation |

The one-sample local boundary observations for QRIS were `checkout_ready`
71.06 ms, `paid_observed` 20.57 ms, `paid_committed` 0.99 ms, `receipt_queued`
323.45 ms, `receipt_pickup`/`credential_ack` 190.01 ms and `group_complete`
190.02 ms. The same run's Balance sample recorded `checkout_ready` 64.80 ms,
`receipt_queued` 197.45 ms, `receipt_pickup`/`credential_ack` 217.81 ms and
`group_complete` 217.81 ms. They carry
`target_status = proposed_unratified`: the sample is synthetic, single-attempt,
in-process fake provider, and not a paired PHP/Django comparison. The probe emits
no device-visible latency and no performance claim. #722 must run the new paired
identity; H1 must ratify or replace any budget.

The local checkout probe ran with `lhie-p09-candidate:local` while mounting the
current working tree at `/opt/candidate`, because the older
`lhie-p05-candidate:1` image does not contain Pillow and therefore cannot import
the receipt invoice renderer. The dependency image identity is recorded here as
a limitation, not as a production artifact. The fake provider returns only
synthetic `https://sakurupiah.id` presentation URLs; there is no network,
real money, live Testing Bot send or provider credential. `checkout_fixture.php`
adds three isolated Customer/Product pairs (`navigation_qris`,
`navigation_balance`, `navigation_race`) after the existing P07 fixtures so no
prior economic fixture identity changes.

## Focused regressions

| Check | Observed |
| --- | --- |
| `python3 -m unittest discover -s tests/migration_harness` | 88 tests passed |
| P11-specific (`test_p11_probe`, `test_p11_compare`) | 14 tests passed |
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

## P11-fix #721 navigation profile

Raw before/after records are in
[evidence/p11-fix-721-profile.json](evidence/p11-fix-721-profile.json). The
profile reused the existing P11 small structural smoke and the P06 PHP parity
fixture; no second benchmark and no PHP rerun were added. Both sides of each
comparison used the same host, fixture, candidate image tag and source-tree
mount. The only changed variable was the candidate source.

### Query count per screen

The parity fixture renders each screen directly and then exercises the same
callback through the real processor/root path. `queries` is the number of SQL
executions; it does not count the Telegram relay HTTP call.

| Screen | Before | After | Change |
| --- | ---: | ---: | ---: |
| Home | 8 | 2 | -75% |
| Catalog | 5 | 3 | -40% |
| Family / Plans | 4 | 3 | -25% |
| Detail | 8 | 5 | -37% |
| Orders | 2 | 2 | 0% |
| Saldo | 6 | 6 | 0% |
| Saldo history | 5 | 5 | 0% |

The end-to-end P11 structural smoke counts ingress plus worker execution:

| P11 scenario | Before | After | Change |
| --- | ---: | ---: | ---: |
| `home_catalog` | 23 | 15 | -35% |
| `catalog_plans` | 24 | 15 | -38% |
| `plan_detail` | 28 | 18 | -36% |
| `back` | 23 | 16 | -30% |
| `home` | 26 | 14 | -46% |
| `quantity` | 28 | 17 | -39% |
| `orders` | 20 | 14 | -30% |
| `balance` | 23 | 17 | -26% |

### Database time and connection reuse

The parity fixture called `close_old_connections()` before each profiled
render, mirroring a request boundary, and measured SQL time only:

| Screen | Before `db_ms` | After `db_ms` |
| --- | ---: | ---: |
| Home | 75.3 | 13.2 |
| Catalog | 18.9 | 8.9 |
| Family / Plans | 57.5 | 3.8 |
| Detail | 27.5 | 7.1 |
| Orders | 57.9 | 1.5 |
| Saldo | 13.9 | 3.6 |
| Saldo history | 17.0 | 2.8 |

The end-to-end structural smoke separately observed most after-change `db_ms`
readings between 16 and 39 ms versus 36–143 ms before. Its two-attempt spread
also produced scheduler outliers (for example one 106.8 ms `balance` attempt),
so those numbers are not a p50/p95 distribution and are not used as a target.

Before #721 every profiled render reported `connection_reused = false` and
`connection_opened = true`; after #721 every render reported
`connection_reused = true` and `connection_opened = false`. The changed setting
is `CONN_MAX_AGE=60` with `CONN_HEALTH_CHECKS=True`, and the bounded worker now
calls `close_old_connections()` after a successful job/sweep while discarding
the connection after a timeout or handler failure. The admitted runtime still
owns at most one connection per HTTP/worker process and four
`candidate_runtime` connections in total; no pool or extra process was added.

### What changed

- One Product projection carries its active Family columns, so Product,
  Family, Detail, quantity and Back no longer issue a second Family SELECT.
- Catalog browsing uses the same single Product scan for grouping; it no longer
  reloads Product and Family sets or resolves the same Category twice.
- The category picker returns categories plus the uncategorized flag in one
  statement. Home returns metrics, wallet, channel, banner asset and maintenance
  in one statement. Detail/quantity share one maintenance+spending flag read.
- The `family:select` and quantity routes reuse the Product/Family row already
  loaded for validation instead of loading it again for the render. This is
  request-scoped presentation reuse only: checkout still revalidates stock and
  price under its existing locks, so no write authority or oversell path changes.
- The processor takes the update row and its chat advisory lock in one
  statement, combines the earlier/later ordering checks, verifies Customer/chat
  root ownership inside the root read and claim, and never re-reads the root for
  `match()` after it has already been read under the chat lock.
- `navigation_updates (chat_id, update_id)` adds the bounded per-chat sequence
  index used by the combined ordering check.
- No cache was added. PHP's `TelegramCatalogProjectionCache` uses a 30-minute
  projection plus an observer-driven version bump; the read-only candidate has
  no counterpart observer and must not serve stale stock, price or plan copy.
  Keeping live reads is the documented tradeoff, not an unmeasured cache claim.

### Verification and limits

- `python3 tools/migration_harness/smoke_navigation.py` passed the full PHP
  surface parity, Orders/Balance pages, stale/foreign callbacks, root protocol,
  grant denial and PostgreSQL checks before and after the change.
- `python3 tools/migration_benchmark/runner.py --protocol p11 --implementation
  django --smoke` completed 8/8 cells with 16/16 successful attempts and zero
  probe errors after the change.
- Focused candidate tests passed: 28 catalog/presentation tests, plus
  `test_navigation_ingress`, `test_http`, `test_runtime`; P11 contract tests
  passed 14/14.
- These are small-dataset structural diagnostics. They do not produce a paired
  p50/p95 verdict, do not replace #722, and are not pooled with the measured
  #692 samples. The high finding `django_navigation_performance_target_not_met`
  remains open until a new paired gate rerun judges it.

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
6. The measured #692 Django identity used `CONN_MAX_AGE=0`, so its `db_ms`
   includes per-request connection establishment. The #721 follow-up uses one
   reused per-process connection; its diagnostic numbers are descriptive and
   never the gate.
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

## P11-fix #722: fresh paired gate

This is a new schema-5 campaign, not a relabeling or pooling of #692. Both
implementations were built locally and measured sequentially using
`runner.py --protocol p11 --implementation laravel|django --benchmark`, then
`compare.py` with the [scoped security review](evidence/issue722/security-review.json).
Both runs completed and reset their owned disposable namespaces.

| Run | Run ID | UTC start–finish | Measured outcomes |
| --- | --- | --- | --- |
| PHP | `00e7213d515547f3a3a8b532b27a30de` | 00:38:20–01:21:46 | 1,200 success; 0 error/timeout/unknown |
| Django | `c13071a32a86447587c3bbccfe388100` | 01:21:47–02:35:50 | 1,200 success; 0 error/timeout/unknown |

Each of the 24 cases per implementation has two batches of 25 measured
attempts and five excluded warmups per batch. Both used revision
`c60b34a84c5a466e6cb428bf4f5bfc6f775a9cc4`, protocol SHA256
`c7d553dd8f97d8ed7fdac6b48c8ce319354d8e9a0fa2c9f60d187d2b9d2a12f9`,
and logical dataset SHA256
`68c6c859de3e25ecadaedd28a04bc4455fdb7177f5c8a33bda28e47d4cb2c08e`.
The source maps agree, including an empty PHP measurement-path delta. PHP was
measured anew, not reused from #692. No auto-extension or targeted rerun occurred.

### Screen ACK by case

Values are milliseconds, nearest-rank p50/p95 of successful measured attempts.
Each row is judged independently. `inconclusive` means batch-p95 spread exceeded
the protocol's 20% threshold on at least one side; it is not a pass or failure.
The compressed paired report retains the individual batch ranges and reasons.

| Scenario | Users | PHP p50 | Django p50 | PHP p95 | Django p95 | Verdict |
| --- | ---: | ---: | ---: | ---: | ---: | --- |
| home_catalog | 1 | 63.03 | 30.68 | 298.82 | 107.65 | inconclusive |
| home_catalog | 5 | 221.96 | 137.83 | 612.81 | 380.44 | inconclusive |
| home_catalog | 10 | 464.91 | 444.48 | 3103.27 | 899.34 | inconclusive |
| catalog_plans | 1 | 80.39 | 19.41 | 402.26 | 90.23 | inconclusive |
| catalog_plans | 5 | 222.77 | 79.89 | 593.37 | 307.98 | inconclusive |
| catalog_plans | 10 | 763.95 | 185.83 | 2823.85 | 418.89 | inconclusive |
| plan_detail | 1 | 60.54 | 22.69 | 227.43 | 90.65 | pass |
| plan_detail | 5 | 350.41 | 97.81 | 801.81 | 372.15 | inconclusive |
| plan_detail | 10 | 805.80 | 156.53 | 1898.14 | 356.60 | pass |
| back | 1 | 62.99 | 17.53 | 186.62 | 92.76 | inconclusive |
| back | 5 | 306.06 | 160.37 | 595.14 | 547.68 | inconclusive |
| back | 10 | 743.92 | 257.19 | 1352.26 | 682.85 | inconclusive |
| home | 1 | 106.91 | 16.78 | 423.47 | 86.42 | pass |
| home | 5 | 303.86 | 77.87 | 620.66 | 311.63 | inconclusive |
| home | 10 | 710.45 | 160.03 | 6090.44 | 332.28 | inconclusive |
| quantity | 1 | 47.56 | 19.15 | 180.54 | 86.81 | inconclusive |
| quantity | 5 | 127.80 | 90.99 | 251.26 | 315.29 | inconclusive |
| quantity | 10 | 386.52 | 196.26 | 1082.22 | 384.40 | inconclusive |
| orders | 1 | 37.77 | 14.63 | 98.99 | 74.41 | inconclusive |
| orders | 5 | 118.02 | 77.41 | 283.84 | 289.44 | inconclusive |
| orders | 10 | 260.97 | 238.16 | 774.09 | 580.68 | inconclusive |
| balance | 1 | 43.36 | 17.11 | 108.94 | 87.64 | inconclusive |
| balance | 5 | 139.19 | 86.02 | 289.63 | 258.04 | inconclusive |
| balance | 10 | 316.40 | 673.30 | 909.60 | 1288.34 | inconclusive |

The machine recommendation is **fix**: 3 pass, 0 fail, 21 inconclusive, 0
navigation gaps. PHP had 15 inconclusive cells and Django had 16; all of the
21 paired inconclusive cases include an unstable batch p95. This is not a
blanket performance pass. All 96 candidate phase audits found zero changed
audited public tables. `customers` is excluded from row hashing; candidate
profile writes are bounded by its column grants instead. The scoped security
review records no unresolved critical/high finding; it does not approve money
activation or substitute for the separately owned money/effect proofs.

### Payment and decision boundary

`checkout` and `paid_delivery` remain `not_implemented` **in the paired
runner**, which provisions navigation-only grants and does not start payment or
receipt workers. The candidate surface exists; #720's one-sample fake-provider
probe is separate integration evidence, not a paired distribution. The
comparator retains the historical two-attempt PHP payment diagnostic and its
`proposed_unratified` budget, explicitly outside this campaign. Funding stage
measurement additionally requires the Owner/H1/ADR approval recorded as
outstanding in #719. No provider, production, live Testing Bot, customer or
real-money operation was used.

**H1 decision: fix.** The Owner accepted `fix` on 2026-11-02 and requested
recording the result. The machine recommendation and this Owner decision do not
authorize production migration, provider activation or real-money payments.
Payment-stage budgets remain unratified.

Sanitized run identities, per-case reports, samples, phase/authority accounting,
resource observations, scoped review and SHA256 checksums are retained in
[`evidence/issue722/`](evidence/issue722/). The run metadata is allowlisted;
private payloads, secrets, filesystem evidence paths and host boot IDs are not
published. Backend synthetic ACKs do not prove device-visible latency or
production compatibility.
