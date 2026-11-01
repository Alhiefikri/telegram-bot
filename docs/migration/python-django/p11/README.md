# P11 paired experiment gate: performance, security, race and parity (#692)

Authority: [#692](https://github.com/Alhiefikri/telegram-bot/issues/692),
[canonical #679](https://github.com/Alhiefikri/telegram-bot/issues/679), and the
[P04 comparator](../p04/README.md). Blockers #686, #687, #689 and #691 are closed
on `migration/python-django-experiment`; this slice stays on that branch family
and performs no production, CI/CD, provider or live-Testing-Bot operation.

Observed commands, raw results and limits: [verification.md](verification.md).

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

Candidate scope is navigation only. `orders` and `balance` callbacks keep their
existing identities outside the P06 port, and there is no candidate
navigation-to-checkout, paid detection or credential delivery surface. Those
six navigation cells and both payment stages are reported as
`not_implemented` with that declared reason; they are never substituted with
synthetic PHP samples or claimed as candidate coverage.

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
3. Otherwise `pass` when both ratios are ≤ 0.70, else `fail`.

The engine recommendation is `stop` for any authority violation or unresolved
critical/high security finding, `fix` for failing/inconclusive/gap evidence and
`continue` only when every expected case passed. It sets `owner_decision: false`;
H1 owns continue/fix/stop.

## Payment stages and budgets

No paired payment-stage timing exists. Retained PHP checkout/credential
diagnostics from P04 are reported separately with their actual (weak)
conditions, and `compare.py` emits a `proposed_unratified` budget anchor set at
0.70 × current-PHP diagnostic p95 per boundary. Those anchors are a starting
proposal for H1, not a candidate target, because the candidate has no payment
surface and no Django payment result was observed or used.

## Relevant owners

Domain terminology stays in [CONTEXT.md](../../../../CONTEXT.md); the implementation
map is [docs/agents/codebase.md](../../../agents/codebase.md). The probe changes no
business owner: dataset construction, navigation, checkout, receipts and workers
remain owned by their existing P04/P06/P07/P08/P09/P10 modules and ADRs.

## Context7 references

- [Django database instrumentation](https://docs.djangoproject.com/en/5.2/topics/db/instrumentation/):
  `connection.execute_wrapper()` is a thread-local context manager; the probe
  accumulates query duration only, never SQL or parameters.
- [Django test client](https://docs.djangoproject.com/en/5.2/topics/testing/tools/):
  arbitrary keyword arguments set WSGI environ values, which is how the probe
  supplies the admitted `SERVER_NAME`, webhook header and attempt header.
- [PostgreSQL explicit locking](https://www.postgresql.org/docs/16/explicit-locking.html):
  reused candidate session/advisory-lock patterns are unchanged; the benchmark
  adds no new locking or money path.
