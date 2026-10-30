# HD: deferred production-data readiness (#684)

Historical path: `h0/`. The former H0 gate is now **HD**, after H1 #693 explicitly decides to continue and before full acceptance #710. It remains open and grants no current production access. Canonical contracts: [#679](https://github.com/Alhiefikri/telegram-bot/issues/679), [#684](https://github.com/Alhiefikri/telegram-bot/issues/684).

## Initial experiment

#683/P03/P04/P08 do not wait for this gate or a full production snapshot. Use repository schema, synthetic/representative fixtures, test keys and matching dataset manifests for PHP/Django. Unknown actual historical compatibility must remain unknown, not a synthetic pass.

P01/P08 own operator-attested Testing Bot/sandbox target verification. Actual external smoke remains required before H1 passes; missing credentials cannot be replaced by fake success. The P01 transport identifier `H0-approved-test-targets-only` is unchanged and does not imply #684 must close first. Do not invent operator attestations or bypass sticky quarantine.

Visual validation uses safe screenshots/recordings, navigation flow, copy/locale, Unicode/custom emoji IDs, button styles and reviewed assets. Exclude customer information, account credentials, payment proofs and payable QR originals. Testing Bot media may require qualified re-upload; a production file_id is not a portable asset.

## HD work after H1

1. Obtain explicit operator authorization for scope/location/resource budget/custody/retention. Historical permissions in [authorization.md](authorization.md) do not authorize new collection. Avoid loading production with experiments; read-only scans also need load/stop limits.
2. Verify actual schema/grants/roles/extensions/sequences and file dependencies against the P03 baseline. Audit complete relevant historical crypto/key/hash/identity and money/stock/history obligations; samples aid diagnosis, not complete coverage.
3. Prove actual backup/files/grants/keys restore and reconciliation in an approved isolated environment. Existing backups may be used only with consistency/provenance and restore evidence. A new full dump or laptop restore is not mandatory if the approved method supplies complete proof.
4. Correct mismatches before #710. Owner explicitly records acceptance or no-go. Do not close HD from procedure availability, synthetic tests or JSON statuses.

Use [P02 contracts](../p02/contracts.md) for custody, immutable source, quarantine without egress/workers/scheduler, and transform-before-INSERT. Never disable guards, modify source ledger history, copy production ENV as runtime config, or feed actual archives into the synthetic-only rehearsal. Test keys remain separate from source keys. Production logical DB continues; no stale local clone replacement.

## Evidence already recorded

[schema-only-result.json](schema-only-result.json) records an earlier operator-authorized schema-only restore: 138 empty application tables, 28 trigger functions, 43 enabled triggers and a rolled-back synthetic identity probe. This is historical evidence, not a newly executed check or full compatibility proof. Actual business rows/files/application keys were not acquired according to that record. Actual roles, historical data/key coverage and Testing Bot/sandbox remain unqualified.

[readiness.json](readiness.json) tracks deferred checks without secrets or runtime authority. [authorization.md](authorization.md) preserves earlier decisions and observations, explicitly superseded by the current synthetic-first decision. [snapshot-deferral-impact.md](snapshot-deferral-impact.md) describes the revised scope. [verification.md](verification.md) separates historical observations from current preparation checks.

Private schema dumps, key material and local tools stay under ignored `.local/migration-h0/`; never stage that directory. No application code, production access, deployment or CI/CD is part of this packet.
