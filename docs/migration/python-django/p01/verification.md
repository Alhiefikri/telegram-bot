# P01 verification — issue #681

Verified locally on 2026-10-30, on `migration/python-django-experiment`.
Base revision: `cdfda12f3048dfe1e2748b64e3e860713d6a8790`; implementation was
uncommitted during verification. Exact tested harness source hashes are retained
in both manifests; every hash was compared with the final working files, with
**no drift**. Admission test source SHA256:
`46e50411e60a66af8bb7a7f6072813d95f59295c85fe482f4ed323287924a0d9`.
The canonical requirements are [#681](https://github.com/Alhiefikri/telegram-bot/issues/681)
and [#679](https://github.com/Alhiefikri/telegram-bot/issues/679).

## Executed evidence

| Command | Outcome |
| --- | --- |
| `python3 -m unittest discover -s tests/migration_harness -v` | **9 tests passed**, including bot identity rejection before transport readiness, production/ambiguous endpoints, attested production provider URLs, private chat routing, purchase-route denial, secret-free errors, mail sink and reset ownership |
| `python3 tools/migration_harness/harness.py build candidate` | Passed; dedicated Python boundary image with namespace firewall |
| `python3 tools/migration_harness/harness.py build laravel --local-dependencies` | Passed; current tracked source, all installed packages matching lock, no network/RUN build steps; exact input hashes/base image in Laravel image labels |
| `python3 tools/migration_harness/smoke.py > .local/migration-p01/smoke-final.json` | **Both stacks passed**, real Docker/PostgreSQL/Redis; [result](evidence/smoke-result.json) |
| `python3 -m py_compile tools/migration_harness/*.py` | Passed |
| `git diff --check` | Passed |
| Final `docker ps -a --filter label=lhie.p01=disposable` and `docker volume ls --filter label=lhie.p01=disposable` | Empty: no remaining harness containers or disposable data volumes |

Runtime identities and resource settings:
[candidate manifest](evidence/candidate-manifest.json),
[Laravel manifest](evidence/laravel-manifest.json). Docker Engine 29.7.2,
Compose 5.5.0, PostgreSQL 16.15, Redis 7.4.11, Python 3.13.18, PHP 8.4.27.
Local host `MemTotal: 7972028 kB`; this is not the production VPS or a capacity test.
Dataset: **synthetic-empty**, plus a synthetic `p01_isolation` test table and a
synthetic original-file sentinel. No production snapshot, files, credentials or
customer identifiers were read. No CI/CD, SSH, webhook mutation or deployment.

## Requirement-by-requirement audit

| #681 requirement | Evidence / resulting behavior |
| --- | --- |
| Separate PostgreSQL, Redis, storage, network and ports | Two fixed Compose project identities and separately named database/storage volumes and network namespaces; distinct loopback HTTP ports. Manifests retain actual mount, network and published-port identities. Each stack uses its own namespace-loopback DB/Redis, never host localhost. |
| Reject production DB/Redis and bot token before startup, without printing token | Strict admission rejects every DB/Redis endpoint except generated disposable endpoints and rejects real tokens in fake mode. Live bot mode requires H0 scope attestation and exact expected testing identity; `getMe` mismatch prevents boundary readiness, so no dependent DB/application starts. Negative tests inject a different/production bot and assert transport listeners never start. |
| Default deny egress, safe admin/chat routing, mail sink | Actual unprivileged namespace probes cannot connect to public IP/Telegram/host DNS targets or Docker embedded DNS; fake relay and SMTP discard work. Unit HTTP tests reject foreign chats, extra Telegram routing fields, webhook and supplier-purchase routes, and redact upstream exceptions. No real token reaches the app. |
| Explicit sandbox/test-bot allowlists cannot open supplier purchase/live payment | Only a plain test-chat message method and an exact attested GET under known sandbox path prefixes exist. Production provider paths are refused even with an otherwise matching attestation; redirect/private-address/alternate-port URL tests fail closed. No generic proxy, payment-create or supplier-purchase route. Actual credentials/qualification remain H0/P08. |
| Whole stack <=2 CPU/2 GiB, headroom caveat and alternation | Each full-stack manifest sums to **2,000,000,000 NanoCPUs** and **2,147,483,648 bytes**. Smoke independently reads every container's cgroup v2 `cpu.max`, `memory.max`, `memory.swap.max`, matches Docker settings and requires zero swap. Starting the other stack while one is active fails. The runbook explains the separate OS/daemon/driver headroom and the ~1.9 GiB VPS caveat. |
| Start/stop/reset disposable data; preserve originals | Real PostgreSQL sentinel survives stop/start; reset removes its table. Final smoke creates an unexpected unlabelled project-prefix volume, confirms reset refuses and the volume survives, then removes only that test-created volume. A synthetic original file outside volumes survives reset. Unit tests also reject an expected-name volume lacking disposable labels before any delete command. |
| No scheduler on raw restore | Quarantine starts boundary/DB/Redis only, requires fake mode, and sets a sticky marker before startup. Smoke observes no app container, verifies retained DB state, and proves normal restart remains refused until disposable reset. No scheduler/worker/import command exists; P02 owns sanitized release. |
| Manifest and reproducible commands | Runbook contains init/build/validate/start/stop/reset, prerequisites, fixed config/egress contract, resource allocations and commands. Manifests retain revision, source hashes, versions, dataset identity/config digest, image/input identities, actual limits and OOM/restarts, with no ENV/payloads. |

## Failures, adjustments and limits

- Initial full Laravel image build suffered repeated registry/CDN resets and slow
  package downloads. It was explicitly stopped and replaced for local verification
  with the documented `--local-dependencies` profile. The full production-Dockerfile
  build profile is **not claimed as verified** here. Local profile includes development
  dependencies and inherited built assets; P04 must distinguish profiles and rebuild
  current assets before application/visual/latency acceptance.
- Initial boundary startup could not read the host-owner's private 0600 configuration
  after capability dropping. Startup now has the narrowly scoped DAC_OVERRIDE capability
  on its sole config mount, then drops UID/groups/capabilities before serving.
- The initial internal-only bridge suppressed published loopback ports on this Engine.
  A dedicated bridge plus namespace default-drop firewall passed the real host HTTP
  and egress probes. Port mappings in configuration alone were insufficient proof.
- Docker DNS denial returned immediate `EPERM` on this host; smoke correctly treats
  that and timeout as denial. A small readiness marker avoids launching a second
  heavyweight Python interpreter for every health probe under the 0.10 CPU cap.
  Container init forwards termination signals so stop/reset can finish promptly.
- Live Testing Bot and real sandbox calls were **not run**: no H0 credentials or
  acquisition authorization were supplied. Bot identity/error/route/URL policies
  were tested with synthetic injected upstream observations. GET status allowlisting
  does not assert the provider offers that endpoint or qualify a payment rail.
- Candidate is the P05 application slot, not a Django implementation. A fresh Laravel
  DB has no imported schema; HTTP availability is transport smoke and can return an
  application error. No application parity, money/stock races, benchmark performance,
  representative background workload, production activation or human gate is claimed.

H0/H1/H2/H3 remain separate human gates. P01 provides the isolated local runtime;
P02/P04/P05/P08 retain data, comparator, candidate and provider integration ownership.
