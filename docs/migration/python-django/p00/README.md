# P00 — frozen feature and historical parity contract

Issue: [#680](https://github.com/Alhiefikri/telegram-bot/issues/680), parent/spec
[#679](https://github.com/Alhiefikri/telegram-bot/issues/679). Integration branch:
`migration/python-django-experiment`. Baseline: `d830709bb98cffacc6369847383c850ff89088f2`.
Audit date: 2026-10-30. This is a source contract, not production qualification.

Read [parity matrix](parity-matrix.md), [acceptance and gaps](acceptance.md), and
[source inventory](source-inventory.json) together. Exact prior-test paths are
expanded in [prior-tests.md](prior-tests.md). Frozen canonical issue bodies are
[parent-spec.json](parent-spec.json) and [issue.json](issue.json); runtime facts remain unknown. The inventory preserves all tracked
source paths and hashes, entry-point declarations, schema/model, jobs, tests, assets,
configuration and ADR references; it is not a runtime route dump or proof of coverage.
No production data, credentials, ENV, file contents or live provider observations were acquired.
The pre-existing untracked planning documents are not part of the frozen source revision.
The canonical issue spec takes precedence over those local planning copies.

## Status vocabulary

Implementation and activation are independent. `implemented` means a source owner exists;
`active` requires dated operator/runtime evidence; `disabled/unqualified` means an explicit
retirement or qualification gate, never an inferred lack of traffic; `unknown` means evidence
is missing. All production activation is **unknown** in this audit. Do not convert unknown
into inactive or remove it from parity. Python parity is **unproven for every row**.
Retired behavior has preservation requirements without reactivation requirements.

## Frozen evidence and refresh

`source-inventory.json` is built from Git objects, not the possibly dirty working tree:

```sh
python3 scripts/migration-source-inventory.py d830709bb98cffacc6369847383c850ff89088f2 > docs/migration/python-django/p00/source-inventory.json
```

The script reads tracked source only and makes no network, DB, framework boot or provider calls.
Its declarations are search anchors, not a PHP/JavaScript parser. Route prefixes/middleware,
conditional schedule registration and callback validation must be read in their owning source.
All source files remain present even when they have no extracted declaration.

Before each experiment gate and release rehearsal:

1. Record integration HEAD and exact main revision (`git rev-parse main`); fetching remote main
   is a separate explicit read if a fresh remote identity is needed. Never replace the original baseline.
2. Run `git diff --name-status <old-baseline> <new-main>` and regenerate an inventory to a **new**
   evidence directory with that revision. Include added/deleted routes, callback formats, migrations,
   scheduled commands, jobs, tests, UI assets, configs and ADR status changes.
3. Review each changed path against the matrix; split rows when a distinct input/output or authority
   appears. Record old/new owner, schema/crypto/wire compatibility, successor ticket and required tests.
   Unmapped paths are gaps, never silently ignored. Reconcile integration without changing main.
4. Refresh sanitized dataset/schema/file manifests through P02/H0 as necessary; do not acquire raw
   data as an agent shortcut. Re-run affected comparator scenarios on both revisions with equal budgets.
5. Record reviewer, command outcomes/skips and new assumptions. P11/H1 and P28/H2 cannot pass
   on stale evidence. P31 retains both original and refreshed manifests and dispositions.

## Cross-check method and limits

Route declarations are captured from all `src/routes` files, commands from command classes and
scheduler declarations from `routes/console.php`. Telegram command/callback anchors include router,
intent and handler source rather than only HTTP routes. Models and **all** migrations retain schema
history, including retired pin/supplier columns; test paths include feature/unit/browser/fixtures.
The glossary and ADR corpus are frozen alongside implementation. Source hashes identify evidence;
counts do not establish parity. Prior test names in the matrix are entry points, not claims that they
were executed or that their assertions alone prove a migration. P03 must inspect real PostgreSQL
constraints/triggers/grants and vectors; P04 measures runtime. No runtime tests are needed for this
documentation-only P00 change, and no CI/CD was inspected or invoked.
