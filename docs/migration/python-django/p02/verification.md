# P02 verification (#682)

Executed locally on 2026-10-30 from child branch `migration/p02-quarantine-data`,
based on integration HEAD `d08232a6f62f714187257aa8af5bbd066f07d7a9`.
The implementation was uncommitted during verification; exact executed tool hashes
are in [evidence/synthetic-result.json](evidence/synthetic-result.json), not inferred
from HEAD. P01's current runtime manifest is
`.local/migration-p01/candidate/1793362867015496674-manifest.json`.

## Executed commands and outcomes

```sh
python3 tools/migration_harness/harness.py reset candidate
python3 tools/migration_harness/harness.py start candidate --quarantine
python3 tools/migration_data/rehearse.py
python3 -m unittest discover -s tests/migration_harness -v
```

The actual rehearsal completed and printed
`.local/migration-p02/synthetic-356d09c0c650b7e2/manifest.json`.
PostgreSQL 16.15, Python 3.14.7 and cryptography 50.0.1; UTC, UTF8,
`en_US.utf8` collation/ctype, installed `plpgsql` 1.0. P01 supplies the existing
local PostgreSQL/Redis/firewall runtime; no candidate application is started.

The regression suite passed **14 tests**, including the existing nine P01
admission/transport tests and five P02 invariant/crypto/schema/file-mapping tests.
The real PostgreSQL rehearsal is separate runtime proof, not a unit-test mock.

| Acceptance obligation | Observed evidence |
| --- | --- |
| Private immutable source, quarantined restore | AES-GCM source dump authenticated before `pg_restore`; source bytes/checksum unchanged after derivation; 0400 source dump, 0700 run directory and 0600 separate source/derived key files verified; raw DB read-only setting observed |
| No workers/network side effects | Admission observed exactly boundary/database/Redis and a healthy firewall; actual connections from UID 1000 in its network namespace to external IP 1.1.1.1:443 and api.telegram.org:443 failed; normal P01 application startup refused under sticky quarantine |
| Derived checksum/provenance/retention | Completed manifest/checksum verified against file bytes and exact current source hashes; encrypted-source, derived-dump, file-manifest, invariant and guard hashes recorded; separate 7-day raw / 30-day derived expiry and operator deletion plan |
| Active constraints/triggers/grants | Restored schema/trigger enabled state/functions/indexes/constraints/grants matched before/after; runtime role INSERT allowed, UPDATE denied; owner ledger UPDATE and immutable identity DELETE rejected; FK injection rolled back all inserted rows |
| Encrypted identity consistency | Legacy and structured source ciphertexts decrypted/authenticated; original fingerprints matched identity registry; derived ciphertexts and remapped registry fingerprints matched under isolated derived keys; drift/wrong key rejected |
| History/ownership/money preservation | Complete synthetic historical projection compared before/after; IDs/FKs, ownership, allocated/retired credentials, integer IDR/micro-USDT, balances, sources, statuses, chronology, deadlines and stable effect keys unchanged; creation_unknown, late_review and unknown effect retained; sequence last_value/is_called matched (`3|t`) |
| File/bot scope | Classified test banner and redacted test receipt with size/checksum and pending per-bot reupload references; pending, wrong-bot, changed-content and source-ID reuse refused; positive in-memory synthetic mapping accepted; no actual upload |
| No production acquisition | Tool accepts no source input flags; generates its own synthetic schema/archive; H0 actual catalogs/files/keys/data remain unknown and unacquired |

Additional actual local probes:

- A committed extra `fixture.unclassified_contact` table caused database-shape
  admission to refuse; the probe table was removed and original guard definitions
  rechecked unchanged. No source history was edited.
- Re-running the CLI with existing P02 databases refused, without overwriting a
  completed dataset or dropping any existing database.
- All 466 inventory source SHA-256 values and every one of its 1,938 line anchors
  matched current files. Installed framework supporting-source hashes also matched.
  This verifies discovery provenance, not complete production schema/dataflow coverage.
- Manifest SHA-256, tool hashes and private artifact permission modes were checked
  against the successful run's current files.

During development the first rehearsal exposed a mismatch between asset-manifest
`sha256` and mapping validator `asset_sha256`. The manifest now uses
`asset_sha256` consistently; subsequent full real-PostgreSQL rehearsal passed.
Failed attempts remained quarantined without a completed admission manifest.

## Limits and operator handoff

- This proves the deliberately bounded synthetic schema, not full Laravel data
  compatibility, production scale or every historical encryption/auth format.
  P03 owns PHP/Python bidirectional vectors, previous-key/hash/session qualification;
  H0 must supply actual reviewed source materials and column/object mappings.
- Role definitions are explicitly provisioned locally; schema-only restore preserves
  and exercises their grants. Actual cluster-global role/default-privilege and
  extension restore has not been performed. H0 must provide the private role/ACL/
  extension catalog, not infer it from no-owner/no-privileges backups or this fixture.
- Source immutability is procedural plus byte/row verification and read-only defaults.
  A trusted local owner/root/Docker administrator can override modes and DB defaults.
  H0 needs stronger separate read/write-role custody for real data.
- No actual Telegram upload, test-bot message, sandbox provider request, money/stock
  race, benchmark, browser/UI or production operation was performed. No UI changed.
- Runtime containers retain P01's envelope, but host driver/crypto memory is outside
  cgroups. This is not performance/resource-capacity acceptance.
- H0 remains a human gate; its acquisition, retention, test-bot/sandbox identities,
  credentials and approval have not been supplied or approved by this implementation.
  Read [contracts.md section 7](contracts.md#7-hd-operator-handoff-and-downstream-gates).
- No CI/CD, workflow, SSH, production ENV/data/file access, push, deployment,
  webhook change, funding activation or real customer message occurred.

Private artifact paths are in the README. Retention is an operator-owned plan,
not a running expiry scheduler. P01 reset removes plaintext restored databases,
not encrypted source archives, separate key directories or sanitized evidence.
The runtime remains unapproved for application release even after this proof.

After these probes, `python3 tools/migration_harness/harness.py reset candidate`
completed successfully, removing the disposable restored database volumes and
stopping the stack. The final encrypted source and derived archive checksums
still matched their evidence. Earlier failed/superseded synthetic run directories
and their keys were removed; only the documented final private run is retained.
All P02 local documentation links resolved. No public artifact contains source
rows, key values or decrypted credentials.
