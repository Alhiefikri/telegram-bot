# P00 local verification evidence

Date: 2026-10-30. Baseline: `d830709bb98cffacc6369847383c850ff89088f2`.
Tools observed: Python 3.14.7; Git 2.55.0. Dataset: immutable tracked Git source,
not a database snapshot. No running application/dependency version is inferred.
Artifacts: this directory; inventory generator in `scripts/migration-source-inventory.py`.

Executed:

```sh
python3 scripts/migration-source-inventory.py d830709bb98cffacc6369847383c850ff89088f2 > docs/migration/python-django/p00/source-inventory.json
python3 scripts/migration-source-inventory.py HEAD > /tmp/p00-inventory-recheck.json
cmp docs/migration/python-django/p00/source-inventory.json /tmp/p00-inventory-recheck.json
git diff --check
```

Results: regeneration identical at the same HEAD; 2,576 unique source records.
An independent local cross-check compared tracked paths against inventory membership
for routes, models, all migrations, jobs, commands, tests (including browser), ADRs,
resources, public assets and Nginx; no missing paths in those categories. Dockerfile,
Supervisor, Compose and container entrypoint/scheduler/recovery scripts were present.
Sampled SHA-256 recomputation and nonempty declaration anchors passed for web/API/console
routes and TelegramMessageRouter. Every named prior-test basename resolved to existing
source (duplicates expanded in prior-tests.md). Local Markdown links resolved. Forty
matrix rows each carry input/output, canonical owner, schema anchors, prior tests,
successor tickets and evidence requirements. Whitespace check passed.

Requirement audit:

| #680 requirement | Evidence |
| --- | --- |
| Freeze evidence/source inventory | immutable baseline, source hashes, frozen issue/spec bodies, reproducible generator and README assumptions |
| Feature input/output, owner, entry point, tests, successor/evidence | F01–F40 matrix plus full source declaration/path inventory and prior-tests expansion |
| Pending/late, old buttons, API clients, signed URLs/sessions/files and retired supplier/pin history | F04/F12–F20/F24–F30/F36–F40; G03–G05 and G09/G10 |
| No implicit UI/provider/funding changes | all rows parity unproven, activation unknown; explicit retirement/qualification restrictions; no runtime patch |
| Explicit unknowns, no silent scope removal | status vocabulary, G01–G13, first-slice deferrals remain mandatory full-parity scenarios |
| Drift/benchmark/scenario contract | README refresh procedure; acceptance scenario/statistics/resource/race/security/browser contract and human gate ownership |
| Glossary/current and superseded ADR review | preserved ADR corpus; icon/pin/warranty supersession and stale KlikQRIS/glossary/adjustment status conflicts recorded |

Not run: application unit/integration/browser/performance tests (no runtime change),
PostgreSQL race tests, provider/Testing Bot experiments, production probes, CI/CD.
None is claimed as passing. Those proofs belong to the successor tickets and gates;
P00 freezes what they must prove. H0/H1/H2/H3 remain open human responsibilities.
