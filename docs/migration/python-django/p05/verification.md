# P05 verification (#683)

Date: 2026-10-30. Branch: `migration/p05-django-bootstrap-683-ready`.
Base revision: `339539bb141f66fa439e570e34581f96895f6e7b` plus the uncommitted P05
source identified by the SHA-256 map in the evidence manifests. No commit, push,
merge, deployment, CI/CD operation or production access was performed.
Dataset: P01 `synthetic-empty`, fake transports, fresh disposable PostgreSQL.
No operator archive, production schema, key or customer identity was used.

## Environment and reproducibility

- Candidate runtime: Python 3.13.18, Django 5.2.18, psycopg/psycopg-binary 3.3.4,
  asgiref 3.13.0, sqlparse 0.6.0. Dependencies installed with `--require-hashes`.
- Python base pinned to digest
  `sha256:281d39ff02e3b389401586134ff9e6572954687822a58055ec07a60ded4e0b7f`.
- Real PostgreSQL 16.15; Redis 7.4.11; Docker 29.7.2; Compose 5.5.0.
- Linux cgroup v2, host `MemTotal: 7972028 kB`. Host memory is not the VPS memory
  budget. P01 limits the alternating stack to 2 CPU / 2048 MiB with no swap;
  OS/Docker/builds/test drivers are outside the stack cap. No capacity inference.
- No HTTP worker children or scheduler; one synchronous HTTP process/thread.
  Four temporary synchronized claim processes run only during explicit smoke.

`uv python install 3.13.18` reported no available standalone download. Tests and
runtime proof therefore used the pinned Docker interpreter, not an older uv
interpreter. The Dockerfile build and hash-checked install succeeded. No host
virtualenv is required by the Docker runbook.

## Exercised commands and results

| Command | Observed result |
| --- | --- |
| `python3 tools/migration_harness/harness.py validate candidate` | Existing default local configuration admitted |
| `python3 tools/migration_harness/harness.py build candidate` | Boundary and pinned candidate images built locally |
| `docker run --rm --network none --read-only --tmpfs /tmp --cap-drop ALL --entrypoint python lhie-p05-candidate:1 -m unittest discover -s tests -t . -v` | 36 tests passed on Python 3.13.18 |
| `python3 -m unittest discover -s tests/migration_harness -v` | 14 existing admission/sanitization regressions passed |
| `python3 tools/migration_harness/smoke_candidate.py` | Real startup, HTTP, admission, DB and Redis scenarios passed |
| `python3 tools/migration_harness/smoke.py` | Both candidate and Laravel real isolation/lifecycle lanes passed |
| `python3 -m compileall -q candidate tools/migration_harness` | Python syntax compilation passed |

P05 smoke observed `/health` and `/ready` 200, bodyless HEAD, generic 400/404/405
responses without supplied path/query/header sentinels, and security headers on
early refusals. Django checks and static collection ran through guarded
`manage.py`; an actual template render escaped HTML. The runtime role's attempt
to create a table in `public` failed; both public and metadata schemas contained
zero tables afterward. No framework auth/admin/session migrations ran.

Five separate no-network candidate launches rejected quarantine, foreign identity,
raw dataset, foreign DB host and broadened thread count. Each exited 1 with exactly
`candidate startup refused` and no config/secret values. Unit boundary cases also
cover duplicate/unknown/malformed JSON, unsafe key permissions, money precision,
ID-only jobs, fixed errors and request/log sentinel exclusion. Docker logging is
actually `none`; the smoke does not claim it read nonexistent Docker logs.

Real Redis pause produced readiness 503 while liveness stayed 200; unpause
restored readiness 200. PostgreSQL smoke used four spawned processes synchronized
with a barrier and four distinct backend connections. Exactly one claimant and
one effect persisted. A real CHECK failure after two writes rolled both back.
Only its generated temporary schema was created and removed; no legacy business
tables were read or written. The smoke uses the explicit disposable administrator
fixture role, not the runtime role. Runtime privilege denial is verified separately.

P01 smoke checked actual cgroup CPU/memory/swap enforcement, forbidden IP/DNS
outbound, fake relay and SMTP sink, alternating stacks, stop persistence, sticky
quarantine without an app/worker and guarded reset for both implementations.
Containers and labelled disposable volumes were removed by smoke cleanup.
Private ignored admission/key files and sanitized local manifests remain; no
operator archives or unrelated local files were deleted.

## Findings corrected before acceptance

1. Real PostgreSQL rendered `inet_server_addr()::text` with a `/32` mask. Startup
   correctly refused the mismatch, but the local address comparison was wrong.
   Runtime and smoke now select `host(inet_server_addr())`; the same end-to-end
   startup path passed afterward. Identity and privilege checks were not weakened.
2. Payload middleware returned before SecurityMiddleware, omitting security headers
   on early 400/405 refusals. SecurityMiddleware now wraps the payload boundary;
   sentinel/error tests and the actual HTTP smoke cover those response headers.
3. The smoke initially attempted `docker logs` with P01's `none` logging driver.
   Docker correctly refused. The smoke now verifies the actual driver and uses
   captured no-network launches plus boundary tests for payload-free diagnostics.

## Issue acceptance mapping

| Criterion | Current evidence |
| --- | --- |
| Laravel production paths/workflows remain unchanged; isolated app, no quarantine producers | Candidate-only image/role/config branch; both P01 lifecycle/isolation smokes passed; quarantine has no app |
| Startup validates local config/identity, exact money and redaction | Strict runtime admission, money startup self-check, actual DB privilege/identity and Redis checks; five refused launches plus 36 boundary/domain tests |
| Domain interfaces return outcomes; adapters do not own business transactions | `domain/purchase.py` returns exact affordability/shortfall through injected reader; ID-only job adapter delegates; no business DB writers exist in P05 |
| Additive separate auth/session metadata; default admin does not replace dashboards | Empty `django_candidate` schema, restricted runtime role, migration denial, no auth/admin/session apps or customer/owner routes; no legacy identity changes |

## Artifacts and limits

- [Bootstrap manifest](evidence/bootstrap-manifest.json): runtime/image identities,
  source hashes, dataset, versions and resource ceilings, without ENV/payloads.
- [Candidate isolation manifest](evidence/isolation-candidate-manifest.json).
- [Laravel isolation manifest](evidence/isolation-laravel-manifest.json).
- [Runbook, module ownership and Context7 sources](README.md).

These are local bootstrap/mechanics results, not migrated money/stock/receipt
race proof. No production activation, schema compatibility, crypto historical
coverage, live Testing Bot, real sandbox, customer UI/browser parity, latency or
throughput claim is made. No visual page was added; template escaping/static
collection are runtime pipeline checks, not visual QA. P03/P06 onward must prove
their actual domain owners and invariants. Human gates remain open; HD follows
H1 and does not block this synthetic bootstrap.
