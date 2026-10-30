# HD preparation verification (#684)

Date: 2026-10-30. Preparation branch: `migration/h0-readiness-684`, based on `d95657b7c20768d8c6579dfbf4c9a21d8591515d`. This revision changes documentation and ignores private H0 work files; it does not implement Django or alter runtime transport policy.

## Current checks

- Actual P01 CLI: `python3 tools/migration_harness/harness.py validate candidate` and `validate laravel` both returned `Admission passed (configuration only)`. No container start/reset was needed.
- `python3 -m unittest discover -s tests/migration_harness -v`: 14 tests passed. Admission, transport denial/redaction, identity checks, money/history invariant preservation, authenticated archive rejection and per-bot mapping boundaries remain covered.
- `git diff --check` passed for tracked edits before staging. Private H0 schema, P01 configuration and P02 key paths matched Git ignore rules.

These checks do not prove Django bootstrap, actual provider/Testing Bot qualification or full production compatibility. No new PostgreSQL restore, production SSH/acquisition, deployment, push or CI/CD was performed during this preparation.

## Historical evidence preserved

[schema-only-result.json](schema-only-result.json) records an earlier schema-only acquisition and local PostgreSQL 16.15 restore, not a check repeated for this commit: 138 empty tables, 28 trigger functions, 43 enabled triggers, 201 foreign keys, 103 sequences and 557 indexes. The dummy identity probe rolled back; the separate P02 rehearsal used its own bounded synthetic schema. See [authorization.md](authorization.md) for historical scope decisions and limitations.

Historical local observations are not current production measurements. They do not qualify deployed roles/grants, actual historical crypto/key coverage, financial data or files. Business rows/files/application keys were not acquired according to the preserved record.

## Current disposition

HD remains open and deferred until H1 #693 explicitly continues. It is required before #710, not before #683/P03/P04/P08. Synthetic PHP/Python compatibility belongs to P03; operator-attested Testing Bot/sandbox qualification belongs to P01/P08 and remains unverified here. Actual complete relevant historical audit and backup restoration/reconciliation remain mandatory before production readiness, without a mandatory full new snapshot or laptop copy.

Only relevant documentation and ignore rules should be committed. `.local/` artifacts, tools, dumps and keys must remain untracked/ignored. The next implementation branch is `migration/p05-django-bootstrap-683`, based on `migration/python-django-experiment`; #683 has not been implemented by this preparation.
