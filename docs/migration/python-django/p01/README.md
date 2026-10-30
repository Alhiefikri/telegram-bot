# P01 — disposable local comparison harness (#681)

Authority: [#681](https://github.com/Alhiefikri/telegram-bot/issues/681), canonical
[parent #679](https://github.com/Alhiefikri/telegram-bot/issues/679), and the
[P00 contract](../p00/README.md). P00 #680 was closed before implementation.
Integration is `migration/python-django-experiment`; never run this from production.
No production Compose files, workflows, application domain owners or provider
activation settings are changed. No data/ENV/snapshots are acquired by these commands.

## Lifecycle

Requires a local Linux Docker Engine using cgroup v2, Compose v2+, Python 3.11+,
and a local Unix Docker socket. Remote contexts and Docker endpoint environment
overrides are refused. Image builds/downloads use the developer host's network;
they are outside runtime measurements. There are no host ports for DB/Redis/mail.

From the repository root:

```sh
python3 tools/migration_harness/harness.py init candidate
python3 tools/migration_harness/harness.py init laravel
python3 tools/migration_harness/harness.py validate candidate
python3 tools/migration_harness/harness.py build candidate
python3 tools/migration_harness/harness.py build laravel
python3 tools/migration_harness/harness.py start candidate
python3 tools/migration_harness/harness.py manifest candidate
python3 tools/migration_harness/harness.py stop candidate
python3 tools/migration_harness/harness.py start laravel
python3 tools/migration_harness/harness.py stop laravel
python3 tools/migration_harness/harness.py reset candidate
python3 tools/migration_harness/harness.py reset laravel
```

`init` refuses to overwrite existing configuration. State is private (umask 077),
ignored by Git, under `.local/migration-p01/{candidate,laravel}`. The generated
configuration is a strict, versioned JSON contract; unknown fields, raw/ambiguous
dataset identities, arbitrary DB/Redis endpoints, unapproved transport credentials
and group/channel recipients are rejected without echoing values. Do not publish
config, generated Compose, image inspection output, shell ENV or transport payloads.
The CLI suppresses subprocess output because errors can contain credentials.

If image downloads are unavailable, an explicit setup alternative is
`python3 tools/migration_harness/harness.py build laravel --local-dependencies`.
It requires the installed local `telegram-bot:php-security-8.4.27` runtime and
`src/vendor`. Every installed production **and development** package/version/source
and distribution reference must exactly match `composer.lock`; symlinks escaping
vendor are refused. Only tracked current application source (excluding ENV files
and symlinks) and that verified vendor tree enter a temporary build context.
Build has no network and no RUN steps. The local PHP base is tagged by its image
digest. Image labels retain the base ID, source/dependency tree hashes and lock hash;
start manifests retain those labels and exact resulting image identity. This profile
includes development dependencies and inherited built assets; P04 must report it
separately from the full production-Dockerfile profile and build current visual
assets before any parity/latency comparison. Package metadata equality alone does
not authenticate local package bytes; tree hashes preserve the actual inputs.

Only generated localhost DB/Redis endpoints are accepted. They are **container
namespace loopback**, never a host DB. No `.env` is loaded by Compose. No source,
raw file, snapshot, host data directory, Docker socket or host network is mounted.
Laravel uses a separately tagged build of the repository Dockerfile, overrides the
production entrypoint, removes cached config/ENV in its disposable writable layer,
and runs only the HTTP development server as UID 1000. It neither migrates nor
boots Supervisor, workers, scheduler or webhook setup. PostgreSQL is fresh and
unmigrated; application DB routes can fail until P02/P05 provide schema/data.
Candidate is an explicit HTTP readiness slot, **not a Django application**; P05
#683 owns supported Django selection/bootstrap. Neither server is a throughput
baseline; P04 must record representative workers and comparator profiles.

Laravel HTTP: `127.0.0.1:18801`; candidate HTTP: `127.0.0.1:18802`.
Both retain separate networks, PostgreSQL volumes and storage volumes.
A host lifecycle lock and Docker running-container check refuse simultaneous
P01 stacks. The lock covers operations, not the runtime. Do not bypass the CLI
with direct Compose operations or run multiple clones against the same daemon.
Docker-admin/root access is trusted; this is not a sandbox for hostile operators.

## Default deny transport and explicit modes

All services in a stack share its boundary's network namespace. The boundary
installs IPv4 **and** IPv6 INPUT/OUTPUT/FORWARD default-drop rules before becoming
healthy. Only established replies, the HTTP ingress and loopback service ports
are allowed. Each stack has its own bridge network. Docker's
embedded DNS is denied to application UIDs, preventing DNS egress around the
HTTP boundary. DB/Redis listen on namespace loopback only. Application/DB/Redis
drop all capabilities, cannot change the rules, and run with fixed unprivileged
UIDs. Only the boundary initializes NET_ADMIN rules, then drops to UID 10001. Its
brief root startup also has SETUID/SETGID and DAC_OVERRIDE to read the host-owner's
private 0600 config bind mount; these capabilities clear on the UID transition.

The boundary exposes namespace-local HTTP `127.0.0.1:8025` and a discard-only SMTP
sink `127.0.0.1:1025`. No SMTP content, recipients, relay body or upstream errors
are logged or retained. Container logging is disabled; Laravel uses the null
log channel. HTTP body size, SMTP message size/line count and I/O time are bounded.

- `fake` is the generated default. No real token or upstream URL is accepted.
  `POST /telegram` accepts exactly `{"chat_id":1,"text":"synthetic"}` when chat 1
  is explicitly listed in `safe_chat_ids`; it returns a fake ACK. All provider,
  supplier, purchase and arbitrary routes are denied.
- `testing-bot` is an **operator-attested P01/P08** option. It requires a separate token,
  numeric bot identity, exact `LhiestoreTestingBot` username, and positive private
  test/admin chat IDs. Before admitting dependent services, `getMe` must prove
  that token belongs to that exact testing identity. A production/other bot token
  fails startup without being printed. The relay only permits `sendMessage` to
  listed private chats with plain bounded text; no webhook changes, arbitrary
  Telegram method, group/channel, inline routing, media URLs or keyboards.
- `sandbox` is another **operator-attested P01/P08** option. It permits only an exact,
  attested HTTPS GET status endpoint, on `klikqris.com/api/sandbox/…/status` or
  `sakurupiah.id/api-sanbox/…/status`. Production paths, alternate ports, credentials
  in URLs, query strings, redirects, path traversal and non-public DNS resolutions
  are refused. The resolved public IP is pinned while TLS verifies the original
  hostname. A bearer sandbox credential stays in the boundary config. Only
  `POST /sandbox/status` with `{}` invokes this GET; payment create and supplier
  purchase have no route. These path restrictions are an admission contract,
  **not proof that a provider offers that status endpoint**; P01/P08 must qualify
  the actual sandbox API before adapting the relay for checkout.

Live modes require `approval` with exactly `gate: H0-approved-test-targets-only`
and `scope_sha256`: SHA256 of Python `json.dumps(scope, sort_keys=True).encode()`,
where scope is all configuration fields except `approval`. This binds the operator's
attestation to the exact dataset, credentials, bot, recipients and destination;
changing any field invalidates admission. It is a recorded human attestation,
not a cryptographic authorization system. The `H0-approved-test-targets-only` value
is the existing transport contract identifier, not a requirement to close #684.
P01/P08 own this scoped target attestation; agents must not invent it. HD (#684)
now follows H1 and concerns actual production data, not initial test targets.
If the approved testing username differs, reconcile the P01/P08 target contract;
never substitute a production bot to make startup pass.

Live mode grants outbound TCP 443 and Docker DNS only to the relay UID. The
application still has no general outbound access and never receives the real
token. No generic CONNECT proxy exists. Existing Laravel transports have not
been redirected to this new relay: P04/P08 integrate adapters explicitly, preserving
the existing business authorities. Importing a DB setting cannot bypass the
network policy. These restrictions intentionally exclude provider purchase and
real-money tests from P01.

## Quarantine and reset

```sh
python3 tools/migration_harness/harness.py start candidate --quarantine
python3 tools/migration_harness/harness.py stop candidate
python3 tools/migration_harness/harness.py reset candidate
```

Quarantine requires fake mode and starts only boundary, PostgreSQL and Redis.
It sets a sticky marker **before** starting services: a later normal start remains
refused even after stop/restart. P02 owns the audited sanitize/release seam;
P01 only clears this marker after a successful disposable reset. There is no raw
restore/import command or automatic scheduler startup. HD owns later actual-data acquisition.

`stop` retains data. `reset` regenerates Compose from the admitted fixed contract,
then checks exact project volume names plus disposable stack ownership labels and
all existing project container labels before `down --volumes`. An unexpected or
unlabelled volume/container fails closed. Only the two named disposable volumes
can be removed; there are no configurable bind-mounted data targets or external
volumes. Config/evidence are retained. Reset never scans or deletes host files,
raw snapshots or original backups. Never attach originals as project volumes.

## Whole-stack resource envelope

| Service | CPU limit | Memory / total memory+swap |
| --- | ---: | ---: |
| Boundary / relay / SMTP | 0.10 | 64 MiB |
| PostgreSQL | 0.50 | 640 MiB |
| Redis | 0.15 | 128 MiB |
| Application and future child workers | 1.25 | 1216 MiB |
| **Total, one stack at a time** | **2.00** | **2048 MiB** |

`memswap_limit == mem_limit` prevents runtime swap. Workers must remain children
inside the application cap, or consume an explicitly reallocated part of this
same envelope. Redis retains `noeviction`, 96 MiB data limit and no AOF/RDB;
PostgreSQL remains durable authority. No database durability settings are relaxed.
The application uses a writable layer only for Laravel runtime/cache; persistent
application storage is the owned disposable volume. Per-container PID limits bound
process growth; they are not benchmark worker counts.

These are ceilings, not reservations or a capacity promise. A ~1.9 GiB VPS cannot
give containers 2 GiB plus OS, Docker, filesystem cache and observation headroom.
This is a local normalized envelope; lower limits and measure host pressure before
any capacity conclusion. Builds, Docker daemon, OS and external test drivers are
outside container sums and must be reported separately by P04. Current PHP behavior
and resource-normalized PHP remain distinct comparator profiles.

## Verification and evidence

```sh
python3 -m unittest discover -s tests/migration_harness -v
python3 tools/migration_harness/smoke.py
git diff --check
```

Smoke requires both images built, default fake/synthetic-empty configs and no
other P01 stack running. It **resets both disposable stacks**. It proves actual
cgroup v2 memory/swap/CPU enforcement, IP/DNS transport isolation, fake relay/mail,
loopback HTTP, alternation refusal, stop persistence, quarantine without producers,
sticky restart refusal and disposable reset using real PostgreSQL and Redis.
It does not prove money/stock races, application parity or throughput.

Each start/manifest records revision, exact harness source hashes, runtime/image
identities, Docker/Compose/runtime versions, dataset identity/config digest,
resource ceilings, OOM/restarts, workers/scheduler policy and host memory. Only
allowlisted inspection fields are emitted. Config/ENV/payloads are excluded.
P02 supplies sanitized dataset/file provenance; P04 supplies measurements and
background policy. Live test-bot/sandbox observations remain separate and require
operator target verification in P01/P08; fake success never qualifies them.
See [verification.md](verification.md).

Context7 consulted on 2026-10-30: resolve `Docker Compose`, then `/docker/compose`,
separate queries for namespace/resource semantics and network/security semantics.
Official references: [network_mode](https://docs.docker.com/reference/compose-file/services/#network_mode),
[resource controls](https://docs.docker.com/reference/compose-file/services/#mem_limit),
[internal networks](https://docs.docker.com/reference/compose-file/networks/#internal),
[startup dependencies](https://docs.docker.com/compose/how-tos/startup-order/).
An internal bridge alone still has a host-owned gateway. On the tested Engine,
an internal-only network also suppressed the requested loopback port publication;
the harness therefore uses a dedicated bridge and enforces all egress denial in
the namespace firewall, including host/gateway access. Runtime smoke is required
alongside config checks; generated port bindings alone did not prove exposure.
