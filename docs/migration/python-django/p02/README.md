# P02: acquisition and synthetic quarantine rehearsal (#682)

Authority: [issue #682](https://github.com/Alhiefikri/telegram-bot/issues/682),
[canonical parent #679](https://github.com/Alhiefikri/telegram-bot/issues/679),
[P00 inventory](../p00/README.md), [P01 isolation](../p01/README.md).
Prerequisite #681 is closed. Work belongs to the
`migration/python-django-experiment` integration branch, never main.

[contracts.md](contracts.md) defines acquisition, field/schema/file inventories,
separate key transfer and the deferred HD operator handoff. [inventory.json](inventory.json)
records source paths, hashes and discovery anchors. [verification.md](verification.md)
records the exercised scope and remaining human gates.

HD's [operator readiness record](../h0/README.md) tracks actual-material checks
after H1 decides to continue, before full acceptance #710. It does not block
synthetic experiments or #683. Testing Bot/sandbox readiness belongs to P01/P08.
A completed P02 rehearsal does not approve acquisition or release quarantine.

## Run locally

Requires the P01 Docker images, local Docker/Compose, Python 3.11+ and
`cryptography==50.0.1` from `tools/migration_data/requirements.txt`. Install the
pinned dependency in an isolated developer virtual environment if needed. P05
still owns application Python/Django selection; this tool is not the candidate app.

```sh
# First use only, if the candidate config does not exist:
python3 tools/migration_harness/harness.py init candidate

# Requires no other running P01 stack and the default synthetic-empty fake config:
python3 tools/migration_harness/harness.py start candidate --quarantine
python3 tools/migration_data/rehearse.py
python3 -m unittest discover -s tests/migration_harness -v

# Quarantine stays sticky; this is the explicit disposable DB cleanup:
python3 tools/migration_harness/harness.py reset candidate
```

The rehearsal refuses existing `p02_source`, `p02_raw` or `p02_derived` databases.
A repeat requires the explicit P01 reset above followed by quarantine startup.
It never drops an existing database itself. Do not run reset against data still
needed for another local task. Failed runs retain quarantine and private evidence;
there is no successful dataset admission without the completed manifest/checksum.
Normal application startup remains blocked. No release command is added.

The tool uses the P01 lifecycle lock, validates the default candidate configuration,
local Docker context, disposable ownership labels, healthy boundary and exact
running service set (boundary, PostgreSQL, Redis). An application, alternate stack,
live transport config or missing sticky marker causes refusal. P01 enforces runtime
IPv4/IPv6 and DNS egress denial. No workers or scheduler are started. The host
Python driver/crypto memory is outside container cgroups and is not benchmark evidence.

## Data path and invariants

1. Generate a labeled synthetic schema and fixture in `p02_source`, including
   separate non-login migrator/runtime roles, FK/CHECK/unique constraints, identity
   sequence, grants and immutable ledger/identity triggers.
2. Export an owner/privilege-preserving PostgreSQL custom archive. Encrypt it with
   AES-256-GCM using a fresh 12-byte nonce, authentication tag and versioned associated
   data. Store only the encrypted source archive on disk. Decryption is in memory
   and piped to `pg_restore --single-transaction --exit-on-error`.
3. Authenticate before restore. Restore into `p02_raw`; make new sessions read-only.
   Compare every restored row to the source and audit credentials, identity
   fingerprints, ledger sequence/balances and account projections.
4. Restore schema into empty `p02_derived`, retaining ownership, grants, functions,
   triggers, indexes and constraints. Transform only classified contacts, encrypted
   credentials/fingerprints, disabled auth hashes and provider/chat targets before
   INSERT. Use the restricted runtime role for INSERT. No UPDATE/DELETE of immutable
   history, no direct projection edits and no trigger/constraint bypass. Initial
   snapshot account rows retain exactly the original values, not recalculated money.
5. Compare persisted historical columns, cardinality, ownership, integer amounts,
   currency, chronology, status, stable effect keys and unknown delivery state.
   Restore and compare sequence state. Compare schema guards/grants before/after.
   Export the audited derived database and record its SHA-256.

The fixture has legacy and structured encrypted credentials, allocated/retired
stock, IDR and micro-USDT ledger history, pending/creation-unknown/late-review Orders
and an unknown outbound effect. Customer IDs and ownership never merge. Credential
email normalization and its linked registry fingerprint change together. Password
hashes are replaced by an unusable marker, not a silently weakened password.
All customer email targets use reserved `example.invalid`; provider calls point to
the denied fake relay path and outbound chat targets are the fake-only chat 1.

This is a **synthetic-only restore/sanitization implementation**, not an approved
production-schema sanitizer. It has no input archive/key/path flags and cannot import
operator data. Unknown tables/columns/nested structured fields fail closed. Production
identity formats, immutable receipt aliases, arbitrary encrypted payload variants,
legacy PHP serialization and source-specific guard dependencies need reviewed
HD mappings before any derived production dataset can be admitted. Do not edit
this allowlist until failures disappear, disable guards, or reuse original keys
in a running application. P03 owns synthetic PHP/Python crypto qualification;
HD checks actual historical coverage after H1.

## Private artifacts and retention

Each successful run prints only the path to:

`.local/migration-p02/synthetic-<id>/manifest.json`

The run directory is 0700. Source and derived dumps are 0400. Source archive content
is immutable by procedure and checked against its original SHA-256 after sanitization;
read-only mode is not protection against the trusted local owner/root changing it.
The restored raw DB is also read-only by default, not a boundary against the trusted
Docker administrator. Use encrypted host storage and no shared/cloud-synced directory.
The tool keeps plaintext source dumps out of files, command arguments and errors.

| Artifact | Purpose | Retention plan |
| --- | --- | --- |
| `source.dump.aesgcm` | Authenticated encrypted source, never overwritten | Delete within 7 days, sooner after compatibility needs finish |
| Sibling `synthetic-<id>-keys/source.json` | Synthetic source encryption/fingerprint and archive keys, 0600 | Transfer/store separately; delete with raw source |
| `derived.dump` | Sanitized synthetic PostgreSQL archive | Delete within 30 days or refresh earlier on schema drift |
| Sibling `synthetic-<id>-keys/derived.json` | Separate derived encryption/fingerprint keys, 0600 | Delete with derived dataset |
| `files.json`, test banner/receipt | Classified synthetic asset checksums and per-bot mapping state | Delete with derived dataset |
| `manifest.json`, `manifest.sha256` | Version/revision/source hashes, provenance, safe counts, outcomes and expiry | Retain only sanitized evidence needed for the next gate |

Expiry timestamps are a plan, not an automatic deletion service. The local operator
owns deletion and its record. P01 reset removes disposable database volumes but
never host artifacts. After rehearsal, reset those volumes so plaintext restored
source databases do not outlive the raw retention boundary. Delete the exact run's
source archive/source key at raw expiry, then its remaining files/derived key at
derived expiry. Never use a broad filesystem cleanup glob for actual acquired data.
Earlier failed synthetic runs have no admission manifest; inspect and remove their
exact private run/key directories as part of local housekeeping.

`files.json` has `asset_sha256`, byte size, classification, relative path,
source/target bot identity and source/target file ID. Entries start as
`requires-reupload`, with no testing-bot ID. `mapping_file_id` fails closed on pending,
cross-bot, changed asset bytes or source-ID reuse. The positive synthetic mapping
probe is not an upload. P01/P08 qualify approved safe test files and Testing
Bot uploads with real ACKs and new target IDs. Never overwrite original
production references or assume a production `file_id` works with the testing bot.
Customer receipt/proof originals remain isolated, not copied into public test assets.

## Context7 decisions

Consulted on 2026-10-30, library IDs `/websites/postgresql/16` and `/pyca/cryptography`:

- [pg_dump security warning](https://www.postgresql.org/docs/16/app-pgdump.html):
  restore executes source-selected code. Real acquisition requires dump/extension/
  function review in quarantine, not trust based on a checksum alone.
- [pg_restore](https://www.postgresql.org/docs/16/app-pgrestore.html):
  single transaction plus exit-on-error; owner/grant preservation; never
  `--disable-triggers` or replication-role bypass for anonymization.
- [pg_dumpall](https://www.postgresql.org/docs/16/app-pg-dumpall.html):
  database dumps omit cluster roles; `--roles-only --no-role-passwords` is a separate
  reviewed operator artifact. Existing no-owner/no-privileges backups cannot prove
  original grants. Synthetic roles here are explicitly provisioned locally; this
  does not prove actual cluster-global restoration or production least privilege.
- [AESGCM](https://cryptography.io/en/latest/hazmat/primitives/aead/#cryptography.hazmat.primitives.ciphers.aead.AESGCM):
  fresh nonce per key, authenticated associated data, reject `InvalidTag` before
  passing restore bytes. The custom archive envelope is version-bound by AAD;
  it is not Laravel encrypted-field serialization.
