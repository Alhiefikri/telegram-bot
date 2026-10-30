# P05: isolated Django bootstrap (#683)

Authority: [#683](https://github.com/Alhiefikri/telegram-bot/issues/683),
[live canonical parent #679](https://github.com/Alhiefikri/telegram-bot/issues/679),
[P00 inventory](../p00/README.md), and [P01 admission](../p01/README.md).
Both blockers #680 and #681 were closed before implementation. Work belongs on
`migration/p05-django-bootstrap-683-ready`, based on the updated
`migration/python-django-experiment` (`339539bb`), not `main`.

This is the local foundation, not a port of checkout, authentication, catalog or
customer dashboards. Laravel production entrypoints, Compose and workflows remain
unchanged. No migrations, workers, scheduler, provider activation or webhook setup
run automatically. No production data, keys or credentials are needed. The live
parent's synthetic-first decision takes precedence over older frozen P00/local
planning copies that require a production snapshot. HD #684 follows H1; it does
not block this bootstrap or synthetic P03 work.

## Supported dependency contract

Selection checked against official sources on 2026-10-30:

| Dependency | Pin | Support decision |
| --- | --- | --- |
| CPython | 3.13.18 | Stable security release; 3.13 security support ends October 2029 per the developer guide |
| Django | 5.2.18 | Supported LTS, extended security/data-loss support through April 2028 |
| psycopg / psycopg-binary | 3.3.4 | Stable PostgreSQL adapter, CPython 3.13 support; no independent LTS promise |

`candidate/.python-version` and the candidate Dockerfile pin Python;
`requirements.in` pins direct dependencies and `requirements.lock` pins every
resolved transitive dependency and distribution hash. Install with hash checking.
The first limiting framework horizon is April 2028; update supported patches and
rebuild/reverify before that date. A pinned version is reproducibility, not a
promise that it stays secure without maintenance. No speed guarantee follows
from framework selection. Python's release page has an inconsistent October 2028
sentence; the version lifecycle table is the horizon used here.

Sources: [Django supported releases](https://www.djangoproject.com/download/),
[Django Python compatibility](https://docs.djangoproject.com/en/5.2/faq/install/),
[Python release](https://www.python.org/downloads/release/python-31318/),
[Python lifecycle](https://devguide.python.org/versions/),
[psycopg release metadata](https://pypi.org/project/psycopg/3.3.4/).
Context7 was consulted via its public library-resolution and context API:
`/websites/djangoproject_en/5.2`, separate queries for
[per-thread connections](https://docs.djangoproject.com/en/5.2/ref/databases/),
[error-report filtering](https://docs.djangoproject.com/en/5.2/howto/error-reporting/),
and synchronous transaction boundaries. Sensitive POST decorators alone do not
cover URLs, arbitrary exception text, headers or nested payloads; the bootstrap
instead uses payload-free diagnostics and generic responses.

Regenerate intentionally, review dependency changes, then run all lanes below:

```sh
uv pip compile candidate/requirements.in --python-version 3.13 --generate-hashes --output-file candidate/requirements.lock
uv venv --python 3.13.18 candidate/.venv
uv pip sync --python candidate/.venv/bin/python --require-hashes candidate/requirements.lock
```

## Ownership and dependency direction

One Django application; no duplicate ledger, effect store or generic repository
framework. Existing canonical owners and ADRs retain authority until their own
migration ticket proves parity. Modules do not imply activation.

| Boundary | Canonical authority retained | Python successor |
| --- | --- | --- |
| Catalog / presentation | Family/Product/Plan, DB icons, pricing and locale snapshots | P06 #687; no catalog ENV settings |
| Checkout / Order Lifecycle | Idempotent intent, lifecycle, cancel/expiry/refund | P07 #688, P12 #694; service owns synchronous atomic transaction |
| Inventory | Credential reservation/allocation/sale/retirement | P07 #688; never edit stock projections directly |
| IDR / USDT / receipt claims | Existing ledger/history, source claims, reconciliation | P07 #688, P15 #699, P16 #700; no float or second authority |
| Payments | Preflight, immutable invoice, authoritative observation | P08 #689, P13 #696, P14 #697; network outside locks |
| Telegram | Canonical root/update dedupe and existing effect ledger | P06 #687, P09 #690; preserve unknown delivery, no blind resend |
| Customer / owner / API auth | Separate existing identities and object ownership | P19 #695; no automatic merge or framework-admin replacement |
| Supplier / warranty | Unknown purchase and historical entitlement | P18 #698; disabled suppliers remain disabled |
| Operations | Recovery, scheduling, audits and safe health | P10 #691, P26 #708; bounded isolated capacity |

Implemented foundation lives in `candidate/lhie_candidate`: domain contracts are
framework-independent; injected Protocol ports receive trusted facts and return
explicit outcomes. HTTP, Telegram and job adapters must delegate to the same
service, never wrap business writes in independent transactions. Pure purchase
assessment is not a debit or permission to fulfill. Future money writers use one
synchronous `transaction.atomic()` service closure, deterministic lock order and
bounded retries of DB-only work. Never retry network calls within money locks.

Python job envelopes contain versioned stable identities only. They carry no ORM
objects, money snapshots, credentials or serialized PHP payloads. The consumer
reloads authoritative state and delegates to the owning service; Redis is only a
wakeup. Stable ID does not by itself grant authorization or replace durable
idempotency/lease/fencing. Worker runners remain P10, not an idle placeholder
process shipped here.

## Local startup and schema admission

Use only P01 lifecycle commands, never production Compose. All endpoints remain
inside the P01 default-deny namespace; the host publishes candidate HTTP on
`127.0.0.1:18802`. The candidate has no bot/provider token. SMTP remains the
namespace-local discard sink, and transport capabilities remain P01's explicit
fake/attested testing-bot/sandbox boundary. There is no general outbound client.

```sh
python3 tools/migration_harness/harness.py init candidate
python3 tools/migration_harness/harness.py validate candidate
python3 tools/migration_harness/harness.py build candidate
python3 tools/migration_harness/harness.py start candidate
curl --fail http://127.0.0.1:18802/health
curl --fail http://127.0.0.1:18802/ready
python3 tools/migration_harness/harness.py manifest candidate
python3 tools/migration_harness/harness.py stop candidate
```

`init` intentionally refuses existing config. For an existing P01-only candidate
volume, explicitly reset its **disposable** data before P05 startup so PostgreSQL
runs the candidate-only role/schema initializer. Never apply it to legacy data or
silently upgrade an existing restore. `stop` retains data; `reset` destroys only
P01-labelled disposable volumes and leaves source archives untouched.

After P01 admission and the sticky-quarantine check, the harness projects only
local dataset/identity/endpoints/limits into private `candidate.json` and creates
a separate random private `django-key` in its ignored state directory. Neither
shell ENV nor .env files override application settings. Missing/unknown fields,
nonlocal endpoints, raw datasets, quarantine and broadened concurrency fail
closed without echoing values. Bind-mounted keys are read-only; do not publish
state/Compose/key files. Startup validates exact money contracts and database
identity/privileges plus Redis PING before binding HTTP. Readiness fails safely
when dependencies become unavailable; liveness does not disclose connection data.

Fresh candidate PostgreSQL creates `candidate_runtime` without superuser,
createdb or createrole and with a four-connection role ceiling, plus the additive
`django_candidate` schema. Runtime search path never includes legacy `public`.
The P01 `harness` role is a disposable administrator/test setup role, not the
application role. No Customer/admin tables are remapped, created or migrated.
Framework auth/admin/session apps are not installed and the migration boundary
refuses unqualified schema writes; enabling metadata/auth later requires P19's
reviewed additive schema and compatibility contract. Django admin is not a
customer/owner dashboard.

Templates use Django's escaped server templates and staticfiles/collectstatic;
plain CSS/vanilla JavaScript only. No page, navigation, assets or customer-visible
redesign is introduced by P05. Asset collection is a build/development step,
not a reason to expose arbitrary files with DEBUG or a production dev server.

## PostgreSQL connection and process budget

The local HTTP server is deliberately one process and one thread with no reload
or worker children. `CONN_MAX_AGE=0`; no pool is added. Each request can hold at
most one runtime connection; readiness closes its connection. The role ceiling
of four leaves bounded room for manual diagnostics, not permission to spawn
four background workers. PostgreSQL's existing `max_connections=30` retains
three reserved slots: budget one HTTP + four synchronized test processes + one
coordinator + at most two operator connections = eight normal connections,
leaving nineteen normal slots unused. Concurrency smoke is a diagnostic alongside
HTTP, never benchmark load. Future queue allocation must explicitly account for
processes × threads × aliases/pools, keep slow work separate from receipts and
interactions per ADR-0030, and stay inside P01's total 2 CPU/2048 MiB cap. Host OS,
Docker and test drivers are outside that cap. This server is not a throughput or
production-deployment choice.

## Test entry points

Pure rules and boundary tests run without production or a database:

```sh
cd candidate
.venv/bin/python -m unittest discover -s tests -v
cd ..
python3 -m unittest discover -s tests/migration_harness -v
```
If uv has no binary for the selected security-only Python release, use the
hash-locked Docker image instead of silently substituting an older interpreter:

```sh
docker run --rm --network none --read-only --tmpfs /tmp --cap-drop ALL --entrypoint python \
  lhie-p05-candidate:1 -m unittest discover -s tests -t . -v
```


Real PostgreSQL concurrency runs **inside the already admitted candidate app**:

```sh
docker compose --env-file /dev/null -f .local/migration-p01/candidate/compose.json exec -T \
  -e CANDIDATE_POSTGRES_SMOKE=disposable-local-only app python smoke_postgres.py
```

This explicit test-only opt-in is not a runtime content setting or credential.
The harness refuses endpoint overrides and uses synchronized spawned processes,
real transactions and a unique disposable fixture schema. It proves one winning
claim and rollback behavior, then removes only its own schema. It is not proof
of migrated money, inventory or receipt races; those tickets must reuse this
harness with their real owners and invariants. No SQLite lane substitutes for
PostgreSQL. Fault injection remains disposable/local. Never run fault tests on
an operator-acquired quarantine or production database.

Full P05 runtime acceptance (requires the built image and default P01 candidate
configuration) starts and resets only the labelled disposable candidate:

```sh
python3 tools/migration_harness/smoke_candidate.py
```

It exercises actual HTTP error/HEAD behavior, five secret-safe startup refusals,
Redis loss/recovery, runtime schema-write denial, template escaping/static
collection and the PostgreSQL multiprocess harness. P01's separate
`python3 tools/migration_harness/smoke.py` covers both stacks' cgroups, transport
isolation, alternation, persistent stop, quarantine and reset boundaries.

Use P01's smoke for real cgroups, egress/mail isolation, alternation and sticky
quarantine. Record sanitized revision, dependency/image versions, dataset identity,
commands, outcomes/skips/unknowns and evidence paths; do not retain ENV, payloads,
request strings, exception repr or credentials. See [verification.md](verification.md)
for the observed run and its limits.
