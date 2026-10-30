# P03: synthetic PHP ↔ Python compatibility (#685)

Authority: [issue #685](https://github.com/Alhiefikri/telegram-bot/issues/685) and
[canonical parent #679](https://github.com/Alhiefikri/telegram-bot/issues/679).
Blockers #682 and #683 were closed before execution. Implementation belongs on
`migration/p03-compatibility-685`, based on `migration/python-django-experiment`,
never directly on `main`. No commit, push, merge, production access or CI/CD is
implied by these local verification commands.

## Contracts

- [Crypto](crypto-contract.md): authenticated Laravel CBC envelopes, explicit
  string/JSON/scalar-array PHP variants, current-key writes and previous-key reads,
  bounded parsing and safe failure without object loading.
- [Identity and hashes](identity-contract.md): Customer scheme before KDF,
  admin/Recovery Key verification, exact key representations, identity normalizers,
  HMAC/digest domains, signed URL/cursor map and session/HD boundaries.
- [Schema and rollback](schema-contract.md): unmanaged mappings, complete
  repository migration baseline, exact money/IDs/Jakarta timestamps, database
  guard/role behavior, Laravel rollback reader and additive Python-only design.
- [Observed verification](verification.md): versions, commands, artifact identity,
  outcomes and limits. Read this before interpreting a synthetic pass as coverage.

## Reproduce locally

No key/data input flag is accepted by these runners. Synthetic keys and test
inputs live in fixture source; they are **not production keys**. Public reports
contain only scenario IDs, counts, source-file checksums and outcomes. Never run
an internal PHP oracle directly into a public evidence/log file: its captured
stdin/stdout protocol contains synthetic plaintext, ciphertext and test hashes.

```sh
uv venv --python 3.13.18 candidate/.venv
uv pip sync --python candidate/.venv/bin/python --require-hashes candidate/requirements.lock
PYTHONPATH=candidate candidate/.venv/bin/python -m unittest discover -s candidate/tests -v
candidate/.venv/bin/python tools/migration_compat/crypto_vectors.py
candidate/.venv/bin/python tools/migration_compat/identity_vectors.py
```

The pinned runtime is the candidate container. If the host installer cannot fetch
that exact Python patch, a supported host 3.13 patch can run offline vectors;
record its exact version rather than claiming the container pin. PHP needs the
locked installed `src/vendor` tree, OpenSSL, mbstring and bcrypt/Argon support.
Both vector CLIs accept `--php` for a local executable/oracle wrapper, not a
remote endpoint or arbitrary source dataset.

For the real PostgreSQL/ORM/Eloquent rollback scenario, first stop any P01 stack.
The runner refuses a running stack, nondefault fake config and arbitrary endpoints.
Build the sanitized PHP image through the existing verified dependency profile,
not the production entrypoint:

```sh
python3 tools/migration_harness/harness.py build laravel --local-dependencies
python3 tools/migration_harness/harness.py build candidate
python3 tools/migration_compat/schema_rehearsal.py
```

Existing admitted default `laravel` config is required; on a new workspace use
`harness.py init laravel` first. This rehearsal resets **only labelled disposable
Laravel volumes**, starts boundary/PostgreSQL/Redis in sticky quarantine, executes
one bounded PHP schema oracle and one bounded Python probe sequentially, then
runs the PHP reader. No HTTP application, worker, scheduler, external provider or
Telegram producer is started. Runtime fixture schema/data/roles are removed in
`finally`; no production Compose or DB can be selected. It retains only the
schema-only dump/report under ignored `.local/migration-p03/`, mode 0700/0600 via
umask. Sanitized committed evidence belongs under `evidence/`.

A failed child emits only a generic failure publicly. Its private diagnostic is
`.local/migration-p03/last-failure.txt`; treat that file as synthetic-internal and
delete it after diagnosis. No production input is admitted, but raw DB/framework
exceptions are still not public reports. The synthetic schema baseline contains
no row plaintext or password hash. Remove local artifacts after the next gate or
within 30 days; no operator backup/key-retention policy is established here.

## Explicit nonclaims

This is not a runnable migrated login/checkout/money service, complete model port,
actual historical key audit, production schema/role qualification, provider/bot
qualification, benchmark, full rollback rehearsal or final PHP-free installer.
Those consumers keep their existing domain owners and downstream issue scopes.
Session import is prohibited; P19/H2 must explicitly approve reauthentication and
legacy grant expiry policy. No hash/fingerprint regeneration, force reset or
Recovery Key replacement is used to hide incompatibility. HD #684, after an
explicit H1 continue decision, owns actual schema drift and all historical
key/hash/identity coverage before P28 #710. P03 does not close a human gate.
