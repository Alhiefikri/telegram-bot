# P03 observed verification

Date: 2026-10-30. Issue [#685](https://github.com/Alhiefikri/telegram-bot/issues/685).
Branch `migration/p03-compatibility-685`, based on integration revision
`2e3100929dde7f340e9584d8337b9c446002145f`. Blockers #682/#683 were closed.
Changes are uncommitted; [the source manifest](evidence/manifest.json) identifies
the exercised working-tree files rather than pretending that the base revision
contains this implementation. No push, merge, deployment, CI/CD or human-gate
closure was performed.

## Runtime and commands

| Lane | Observed runtime | Outcome |
| --- | --- | --- |
| Candidate regression suite | Host Python 3.13.14, Django 5.2.18, PHP 8.5.9 | 64 tests passed |
| Existing P01/P02 regressions | Same locked host candidate environment | 14 tests passed |
| Crypto vector CLI, host PHP | Real installed Laravel framework / Eloquent casts | 71 scenario records passed |
| Identity vector CLI, host PHP | Real source PHP hash/identity methods, bcrypt/Argon2i/id | 30 password vectors and 30 wrong-password checks each direction; 17 primitive groups each direction passed |
| Both vector CLIs, pinned PHP oracle | PHP 8.4.27, network-none/read-only disposable container, same installed source dependencies | Both passed; committed safe manifests use this oracle |
| Schema, ORM and rollback reader | PostgreSQL 16.15; candidate Python 3.13.18 / Django 5.2.18; PHP 8.4.27 | Real repository-schema and Eloquent rollback scenario passed |
| PHP syntax | Host PHP 8.5.9 | All three PHP oracle files passed `php -l` |

Dependency changes are hash-locked: `cryptography==46.0.7`, `bcrypt==5.0.0`,
`argon2-cffi==25.1.0`, with resolved transitive pins in `candidate/requirements.lock`.
These supply CBC/PKCS7 and legacy KDF compatibility, not Django-native hash
conversion or a new authentication policy. The pinned container was rebuilt.
The host `uv` downloader did not know Python 3.13.18; host vectors used installed
3.13.14 explicitly, while the actual ORM/schema smoke used pinned 3.13.18.

Executed from repository root:

```sh
uv pip compile candidate/requirements.in --python-version 3.13 --generate-hashes --output-file candidate/requirements.lock
uv venv --python python3 candidate/.venv
uv pip sync --python candidate/.venv/bin/python --require-hashes candidate/requirements.lock
PYTHONPATH=candidate candidate/.venv/bin/python -m unittest discover -s candidate/tests -v
candidate/.venv/bin/python -m unittest discover -s tests/migration_harness -v
candidate/.venv/bin/python tools/migration_compat/crypto_vectors.py
candidate/.venv/bin/python tools/migration_compat/identity_vectors.py
python3 tools/migration_harness/harness.py build laravel --local-dependencies
python3 tools/migration_harness/harness.py build candidate
python3 tools/migration_compat/schema_rehearsal.py
php -l tools/migration_compat/crypto_vectors.php
php -l tools/migration_compat/identity_vectors.php
php -l tools/migration_compat/rollback_reader.php
```

The additional pinned-oracle runs used each vector CLI with
`--php .local/migration-p03/php-oracle`. That temporary executable forwarded its
arguments/stdin with `docker run --rm -i --network none --read-only --cap-drop ALL
--security-opt no-new-privileges:true --user 1000:1000 --cpus 1.25 --memory 1216m
--memory-swap 1216m`, a read-only repository mount at its original absolute path,
`--entrypoint php lhie-p01-laravel:1`. The wrapper was removed after recording the
safe outputs. No raw PHP protocol stream was written to an evidence file.

## Acceptance evidence

| Acceptance requirement | Current evidence and boundary |
| --- | --- |
| Bidirectional synthetic Unicode/null/JSON/legacy credentials | [crypto-vectors.json](evidence/crypto-vectors.json): 24 PHP-origin cases decoded by Python, re-encoded and independently verified by PHP; 23 additional rejection/legacy-format cases. Real Eloquent encrypted-string/array and Encrypter serialization APIs are the oracle. |
| Wrong key, tamper, rotation, malformed payload | Wrong current/absent previous keys, IV/value/MAC edits, malformed shape/base64/JSON, unsupported tag/object/reference/enum/serializer, authenticated padding/UTF-8 failure and bounded parser regressions all passed. Cipher construction is tested to occur only after successful MAC authentication. |
| Hash/identity compatibility without regeneration | [identity-vectors.json](evidence/identity-vectors.json): source-owned Customer sha384-v1/raw, Admin and Recovery Key consumers across bcrypt/Argon2i/id; byte-boundaries, wrong-password rejection, HMAC key representation, normalizers, token/OTP/API/signed-URL/cursor primitives. Stored-hash bytes remain unchanged in the real rollback fixture. No forced reset or regenerated Recovery Key. |
| Repository schema baseline and numeric mapping | [schema-result.json](evidence/schema-result.json): 250 migration source checksums, 138 tables, 591 constraints, 43 noninternal triggers, 544 indexes, 31 public functions and 103 sequences. Actual ORM round trip preserves IDs above 2^53, signed bigint limits, all 78 numeric digits, null/default values and Jakarta wall-time/UTC instants. |
| Active constraints/guards/role behavior | Real enum/CHECK, unique, missing/restricted FK and canonical Telegram ownership violations rejected; entry/accounting UPDATE/DELETE rejected even for fixture owner. Initial large account snapshot matches its ledger/accounting amount, sequence and cursor. Runtime DDL, ownership-column UPDATE, DELETE, ledger/projection changes, sequence reseed and role escalation rejected. |
| Sequence and unmanaged lifecycle | Explicit-ID insert leaves sequence state unchanged; owner-only reseed produces the next exact ID. Django `CreateModel.database_forwards()` leaves the legacy catalog unchanged under unmanaged/router admission. Candidate startup and public-schema runtime grants remain unchanged. |
| Rollback write contract | Python-created Customers and Telegram Identity are read by the real Eloquent Customer model; PHP checks exact Telegram ID, password verification, scheme, local timestamp, binding and zero account snapshot. A failed two-row writer rolls back both rows. Not a checkout/money/auth-endpoint parity claim. |
| Plaintext excluded from evidence | Public vector reports contain only opaque scenario IDs/outcomes/counts and source checksums; schema dump is schema-only. No plaintext value, key, stored password hash or low-entropy secret digest appears in these reports. Synthetic inputs and key definitions remain explicit fixture source, not production secrets. |
| Additive/Python-only design and HD boundary | [schema contract](schema-contract.md) retains one schema owner, deny-all automatic legacy migration and additive metadata separation; describes the reviewed SQL baseline/Python history installer and existing-DB delta strategy. [Identity contract](identity-contract.md) maps downstream/session policies and HD historical obligations. |

## Findings, artifact identity and cleanup

The initial restricted-role identity INSERT failed: its unchanged source trigger
locks the Customer `FOR UPDATE`, which needs column UPDATE privilege as well as
SELECT. A diagnostic rerun localized the same failure without printing values.
The fix grants only `customers.updated_at` for that locking requirement, not
ownership or money updates; no schema guard was changed. The corrected rehearsal
passed, then the final provenance/role-attribute rehearsal passed again.

Final dataset ID: `p03-repo-schema-synthetic-v1`. The schema-only artifact is
`.local/migration-p03/schema-baseline.sql`, SHA-256
`88a805b3b89afab8df09a08e6c9168074eec3361c1b979cf0cfdbfc541110d2e`.
The corresponding safe report is committed as `evidence/schema-result.json`.
Every recorded migration/probe source checksum and the actual schema artifact
checksum were compared with current bytes before publication. Vector manifests
record their source checksums; `evidence/manifest.json` additionally pins identity,
legacy field, test, dependency and oracle source files and runtime distinctions.
The schema dump's randomized pg_dump restriction token can change on a fresh run.

The harness's `finally` reset removed its disposable PostgreSQL/Redis/boundary
containers and labelled volumes after each attempt. No application server,
worker or scheduler was started by this rehearsal. The diagnostic was deleted
after success. The schema-only artifact/report may remain locally for the next
gate, with deletion due within 30 days; no raw database, test-key file or plaintext
vector dump is retained. This is not a production backup-retention policy.

## Unknowns and mandatory HD handoff

**Not verified:** actual production schema, timezone/collation/config history,
role memberships/ACLs/default privileges/extensions/sequences, deployed image
contents, all historical current/previous cipher keys, fingerprint-key continuity,
KDF schemes/costs, Unicode/filter-version edge cases, signed-host/cursor variants
or real backup restoration. These are explicit HD #684 requirements after H1
continues and before P28 #710. Synthetic success does not close HD or any human
gate. No production acquisition is needed or authorized for P03.

The schema lane uses a complete repository-generated catalog but focused behavior
probes, not exhaustive operation coverage for every table. The reviewed ORM
classes are projections, not a port of all 138 tables. Signed URLs/cursors are
mapped and their primitives are proven; downstream adapters still own canonical
encoding, expiry, actor/Customer/message/currency binding and authorization.
Laravel sessions are not imported; P19/H2 must approve reauthentication/grant
expiry policy. Business races, bot/provider readiness, full rollback rehearsal,
performance targets and final no-PHP installer acceptance remain their own gates.
No production, CI/CD, live-money, Telegram delivery or external provider claim is
made by this evidence.
