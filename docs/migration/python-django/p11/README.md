# P11 paired experiment gate: performance, security, race and parity (#692)

Authority: [#692](https://github.com/Alhiefikri/telegram-bot/issues/692),
[canonical #679](https://github.com/Alhiefikri/telegram-bot/issues/679), and the
[P04 comparator](../p04/README.md). Blockers #686, #687, #689 and #691 are closed
on `migration/python-django-experiment`; this slice stays on that branch family
and performs no production, CI/CD, provider or live-Testing-Bot operation.

Observed commands, raw results and limits: [verification.md](verification.md).

The later [#722 paired rerun](verification.md#p11-fix-722-fresh-paired-gate)
uses fresh PHP and Django identities: 24/24 navigation cases per side,
3 pass and 21 inconclusive. The machine recommendation and Owner/H1 decision
are `fix`. Historical #692 results below retain their original identity.

## What this gate measures

The paired protocol is `tools/migration_benchmark/protocol_p11.json`
(schema 5). It reuses the P04 comparator's 24-case current-PHP navigation plan
(eight transitions × users 1/5/10, two independent batches of 25 measured
attempts after five excluded warmups) and adds the Django candidate as the
second implementation. The target remains per case: candidate p50 and p95
`screen_ack` ≤ 70% of its paired current-PHP value. Cases are never averaged.

Frozen comparison conditions are large synthetic dataset, warm, distinct-chat
traffic and **idle** background. Representative per-wave provider-verification
and notification jobs were deliberately not used: those PHP families have no
candidate counterpart in the P06–P10 slice, so injecting them on one side only
would make the pair incomparable. Both sides still run their own real worker
processes and sweeps.

Candidate scope is navigation only. The measured #692 revision left `orders` and
`balance` callbacks with their existing identities outside the P06 port, and
there is no candidate navigation-to-checkout, paid detection or credential
delivery surface. Those six navigation cells and both payment stages are
reported as `not_implemented` with that declared reason; they are never
substituted with synthetic PHP samples or claimed as candidate coverage.

The separate P11-fix follow-up #717 ports the read-only successful Orders screen
and removes `orders` from the declared gap list. The #718 follow-up ports the
read-only Saldo screen and IDR ledger history and removes `balance` from that
list. The #719 follow-up adds the minimum IDR funding path behind the same Saldo
entry point: DB policy/preset identities, one intent/confirm/cancel owner, a
fake-only sandbox provider seam and settlement through the existing Store
Balance ledger plus global external-receipt claim. That work is outside the
measured #692 identity: its results are not pooled with the published samples,
and the paired-gate rerun belongs to #722. The funding rail is inactive by
default, and full provider/credential activation remains P15/P17. No
Owner/H1/ADR approval is recorded by this change; H1/Owner authorization remains
a blocker for activating or measuring the funding rail.

The #720 follow-up adds the candidate navigation-to-checkout/payment/receipt
surface behind `detail:pay:qris`, `detail:pay:balance` and
`checkout:check:<payment-id>`. It reuses the P07 checkout/inventory owner, the
P08 sandbox lifecycle (one Payment, no provider retarget and no second invoice)
and the P09 ordered receipt ledger. A local fake-sandbox probe measures the
candidate boundaries and proves one Order/Payment per selection, replay and
same-key race, receipt completion and duplicate-delivery refusal. That probe is
single-sample local integration evidence, not a paired measurement. The measured
#692 identity still has no candidate checkout cell, so the protocol continues to
declare both payment scenarios `not_implemented` for that run and no sample is
pooled. #722 owns a new paired run; H1 still owns any payment-stage budget.

The #721 follow-up profiles the measured navigation read path and removes
round trips without changing presentation, authority or money/stock writes.
Products now carry their active Family projection on the same row, targeted
Product/Family/Back reads reuse that row, catalog browsing no longer loads the
same Product or Family set twice, the category picker and Home each use one
bounded statement, and detail/quantity reuse the Product and maintenance/spending
flags already loaded. The processor reads a pending update and takes its chat
advisory lock in one statement, checks earlier/later sequence in one statement,
and verifies root ownership inside the root read/claim instead of a separate
Customer SELECT. `CONN_MAX_AGE=60` plus `close_old_connections()` keeps the one
admitted connection per HTTP/worker process while an expired connection is still
returned. A chat-sequence index bounds the per-chat ordering checks. This is
query/connection work, not a cache: PHP's projection cache has an observer-driven
version bump that the read-only candidate cannot reproduce, so the candidate
keeps live reads and the stock/price invalidation tradeoff stays explicit.
Diagnostic before/after records (small P11 structural smoke and the P06 parity
fixture) are in [verification.md](verification.md#p11-fix-721-navigation-profile).
No sample is pooled with the measured #692 run and #722 still owns the paired
rerun.

## How both sides produce comparable evidence

- The dataset is built once per dataset by the existing PHP domain owners
  (`probe.php` seed), attested by the P04 logical checksum, then a pristine
  PostgreSQL template is cloned before each independent batch. Both runs must
  record the same `dataset_logical_sha256`.
- PHP cells use the P04 in-process HTTP-kernel client cohort and its nine queue
  workers inside the P01 1.25 CPU / 1216 MiB application allocation.
- Django cells run the candidate image in the same P01 network namespace with
  the same allocation: the admitted WSGI request path serves real loopback
  ingress, synchronized warm client processes submit one update each, and the
  real bounded `interaction` worker processes it. The candidate's
  `payment`/`receipt` workers are not started because their handlers require
  money/effect authority that is deliberately absent from the navigation grants.
- `interaction_queued` is observed at the real wakeup enqueue call,
  `pickup`/`screen_ack` inside the real worker, and ingress query/monotonic
  timestamps in the client/server processes on the same host clock. No product
  module gains a benchmark branch; instrumentation exists only in
  `tools/migration_benchmark/candidate_probe.py`.
- Candidate cells may change only `django_candidate` navigation state and the
  already-admitted narrow Customer profile columns. After every batch the runner
  hashes every public table except `customers` and fails the run on any change
  to money, stock, order, payment, effect or history rows.

Both `screen_ack` values are backend acknowledgements, not device-visible
latency. The PHP synthetic Telegram transport is an in-process `Http::fake`; the
candidate uses the P01 loopback relay. That difference is conservative against
the candidate and is restated in the paired report.

## Commands

Prerequisites are the existing disposable images (build outside measurement):

```sh
python tools/migration_harness/harness.py build candidate
python tools/migration_harness/harness.py build laravel --local-dependencies
```

Focused regressions (no Docker):

```sh
python3 -m unittest discover -s tests/migration_harness -p 'test_benchmark*.py' -v
python3 -m unittest tests.migration_harness.test_p11_probe \
  tests.migration_harness.test_p11_compare -v
```

Short structural smoke for the candidate path (small dataset, diagnostic only):

```sh
python3 tools/migration_benchmark/runner.py --protocol p11 --implementation django \
  --smoke --output .local/migration-p11/django-smoke
```

Paired measurement (explicit, one implementation per run identity):

```sh
python3 tools/migration_benchmark/runner.py --protocol p11 --implementation laravel \
  --benchmark --output .local/migration-p11/php-baseline
python3 tools/migration_benchmark/runner.py --protocol p11 --implementation django \
  --benchmark --output .local/migration-p11/django-candidate
python3 tools/migration_benchmark/compare.py \
  --php-run .local/migration-p11/php-baseline \
  --django-run .local/migration-p11/django-candidate \
  --findings docs/migration/python-django/p11/evidence/security-review.json \
  -o .local/migration-p11/paired-report.json
```

Use a security review for the measured revision; the example's review records
the historical #692 scope. When deliberately pairing an applicable PHP run
from an earlier campaign, add `--php-baseline-reused`; the comparator still
refuses a PHP measurement-path source delta.

The runner refuses a new local directory that already exists, a non-default
synthetic configuration, a running disposable stack, or a resume whose
protocol/source/implementation identity changed. It resets only the matching
P01-owned disposable namespace in `finally`.

## Observed result

Full records are in [verification.md](verification.md) and sanitized artifacts in
[evidence/](evidence/). The gate completed: PHP 24/24 cells (1.200 measured
successes, zero errors), Django 18/18 cells (885 successes, 15 recorded probe
bookkeeping errors), 72 authority audits with zero changed public tables, and the
same large-dataset logical checksum on both sides. Paired verdicts: **0 pass,
3 fail, 15 inconclusive, 6 not_implemented**; the engine recommendation is
**fix**, not continue. Every quality-qualified case failed the 70% target. PHP
was not rerun: the only source delta is Django-side probe tooling, with an empty
PHP measurement-path delta recorded in the paired report.

## Verdict rules

`compare.py` pairs only runs whose protocol document, dataset contract, dataset
logical checksums, PostgreSQL settings and excluded-claim limits match. Per case:

1. Missing candidate cell for a declared gap → `not_implemented`.
2. Either side non-exploratory, incomplete batches or missing distributions →
   `inconclusive`.
3. Compare decimal representations of p50 and p95 separately against the
   unchanged 0.70 target. For each reported latency, conservatively allow
   ±0.005 ms, half the 0.01 ms display unit used in the retained
   [verification table](verification.md). A ratio is clearly above only if
   `(Django - 0.005) > 0.70 × (PHP + 0.005)`; it is clearly below only if
   `(Django + 0.005) < 0.70 × (PHP - 0.005)` with a positive lower PHP bound.
   Any clearly above metric fails the case; otherwise, any metric whose interval
   touches or crosses the target is `inconclusive`; only two clearly below
   metrics pass. Reasons identify the affected p50 or p95. Ratios are retained
   without display rounding, as JSON numbers.

This display-precision guard is not a statistical confidence interval or a
change to #679's target. The underlying report retains finer timing values;
the guard avoids treating distinctions hidden in the retained presentation as
decisive, but does not quantify sampling or measurement uncertainty.

The engine recommendation is `stop` for any authority violation or unresolved
critical/high security finding. Otherwise it is `fix`: even if every navigation
case passes, this comparator does not evaluate paired payment stages or
representative-background workloads. The verdict enumerates both missing
evidence classes and the payment scenarios and boundaries. It sets
`owner_decision: false`; H1 owns continue/fix/stop. Previously retained reports
remain historical evidence and are not relabeled by these rules.

## Payment stages and budgets

No paired payment-stage timing exists for the measured #692 identity, and
`compare.py` intentionally continues to report the retained PHP-only diagnostic
set. The #720 surface can now observe candidate `checkout_ready`,
`paid_observed`, `paid_committed`, `receipt_queued`, `receipt_pickup`,
`credential_ack` and `group_complete` locally through
`candidate/smoke_checkout_navigation.py`, but it produces one synthetic sample
per boundary, not a distribution. `compare.py` still emits the
`proposed_unratified` 0.70 × current-PHP diagnostic p95 anchors as a starting
proposal for H1. They are not a candidate target: no paired Django payment
sample has been collected, and #722/H1 must ratify or replace the numbers.
`protocol_p11.json` records the #720 local probe as
`local_single_sample_not_paired` with `target_status = proposed_unratified`; it
does not add payment cells to the measured paired runner.

## Relevant owners

Domain terminology stays in [CONTEXT.md](../../../../CONTEXT.md); the implementation
map is [docs/agents/codebase.md](../../../agents/codebase.md). The probe changes no
business owner: dataset construction, navigation, checkout, receipts and workers
remain owned by their existing P04/P06/P07/P08/P09/P10 modules and ADRs.

## Context7 references

- [Django transactions](https://docs.djangoproject.com/en/5.2/topics/db/transactions/):
  the #719 funding owner uses `transaction.atomic()` plus `savepoint()` /
  `savepoint_commit()` / `savepoint_rollback()` around the global receipt claim
  so a unique conflict never leaves the caller transaction aborted.
- [Django row locking](https://docs.djangoproject.com/en/5.2/ref/models/querysets/#select-for-update):
  Customer/account/top-up locks are real PostgreSQL `SELECT ... FOR UPDATE`
  statements inside the money transaction; SQLite/TestCase does not prove them.
- [Django raw SQL cursors](https://docs.djangoproject.com/en/5.2/topics/db/sql/):
  the #717 Orders, #718 Balance and #719 funding reads/writes use
  `connection.cursor()` with `%s` parameters and bounded `LIMIT`s, never
  string-interpolated customer, amount or provider values. Balance activity
  cursors stay opaque signed application data.
- [Django database instrumentation](https://docs.djangoproject.com/en/5.2/topics/db/instrumentation/):
  `connection.execute_wrapper()` is a thread-local context manager; the probe
  accumulates query duration only, never SQL or parameters.
- [Django test client](https://docs.djangoproject.com/en/5.2/topics/testing/tools/):
  arbitrary keyword arguments set WSGI environ values, which is how the probe
  supplies the admitted `SERVER_NAME`, webhook header and attempt header.
- [PostgreSQL explicit locking](https://www.postgresql.org/docs/16/explicit-locking.html):
  reused candidate session/advisory-lock patterns are unchanged; the #719
  funding settlement locks Customer → balance account → top-up, performs
  provider I/O outside the transaction and revalidates after re-locking.
  The benchmark adds no new locking or money path.
- [PostgreSQL row comparison](https://www.postgresql.org/docs/16/functions-comparisons.html#ROW-WISE-COMPARISON):
  the Orders and Balance keysets compare `(created_at, id)` in the requested
  direction so equal-second rows stay reachable without an offset scan.
- [Django transactions](https://docs.djangoproject.com/en/5.2/topics/db/transactions/):
  the #720 Cart materialization locks the Customer and revalidates the admitted
  root inside one short transaction; provider I/O and checkout retries stay
  outside it.
- [Django `select_for_update`](https://docs.djangoproject.com/en/5.2/ref/models/querysets/#select-for-update):
  the same-key replay/race path uses real PostgreSQL Customer and Cart row locks;
  SQLite/TestCase cannot prove that boundary.
- [Django persistent connections](https://docs.djangoproject.com/en/5.2/ref/databases/#persistent-connections):
  `CONN_MAX_AGE=60` reuses the one admitted connection in a single-threaded
  HTTP/worker process; `CONN_HEALTH_CHECKS=True` validates a reused connection,
  and `close_old_connections()` after each worker task returns an expired or
  unusable slot without adding a pool.
- [PostgreSQL multicolumn indexes](https://www.postgresql.org/docs/16/indexes-multicolumn.html):
  the B-tree on `navigation_updates (chat_id, update_id)` leads with the equality
  column and then bounds the per-chat sequence range used by the processor.
