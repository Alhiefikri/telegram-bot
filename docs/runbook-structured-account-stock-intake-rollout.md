# Structured Account Stock Intake Rollout

This runbook enables **Input Akun** additively while preserving Input Cepat, existing structured-row allocation/delivery, and credential identity history.

## Safety contract

- Keep `STRUCTURED_STOCK_INPUT_ENABLED=false` during schema/code rollout and backfill.
- Keep `CREDENTIAL_IDENTITY_FINGERPRINT_KEY` stable after the registry has rows. It is a keyed fingerprint secret, not a value to print or copy into evidence.
- Never select decrypted `secret` or `credential_payload` values into command output, logs, screenshots, issue comments, or CI artifacts.
- Do not delete `credential_identities` during rollback. Its retained fingerprints are the no-reuse history.

## 1. Additive deployment

Deploy the migrations and application code with Account Input disabled. Run the normal migration and config-refresh procedure:

```bash
cd src
php artisan migrate --force
php artisan optimize:clear
```

Verify the application, queue workers, scheduler, Input Cepat, and existing paid Telegram/Web delivery before proceeding. Record only command status and safe counts.

## 2. Backfill retained identities

Use a bounded dry-run first. The command reports only counts, the last processed row ID, and unresolved/conflicting Product Stock Item IDs.

```bash
cd src
php artisan credentials:backfill-identity-registry --chunk=100 --dry-run
```

The dry-run must exit `0` with `unresolved=0` before commit mode. A non-zero result is a hard stop: inspect only the reported row IDs through the authenticated admin repair workflow, resolve retained duplicate identities, and rerun the dry-run. Do not paste credential values into the incident record.

Commit the registry in the same bounded shape:

```bash
cd src
php artisan credentials:backfill-identity-registry --chunk=100
```

The commit run must exit `0` with `ready=true`. Re-running from the beginning is safe and idempotent; `--after-id=<last_id>` may be used for an interrupted bounded run. Enablement is blocked while any retained duplicate or malformed structured row remains unresolved.

Safe evidence consists of the command, exit code, `scanned/registered/already_registered/skipped_unrecognized/conflicts/unresolved/last_id`, and the reported row IDs. It must not contain credential content.

## 3. Controlled non-production proof

With the same additive code and a non-production database:

1. Create one synthetic Structured Account Credential through the Owner/Admin Account Input review flow; confirm exactly one Product Stock Item, one positive adjustment, one safe audit event, and one notification transition.
2. Verify a duplicate operation key replays metadata without adding a second item, movement, audit, or notification.
3. Reserve the item through checkout, release it through the existing cancellation/expiry path, and confirm numeric stock, `reserved_stock`, Product Stock Item availability, and identity history remain consistent.
4. Edit the Available structured row, then repeat the reservation check. Confirm an allocated/reserved/paid row cannot be edited or deleted.
5. Complete one controlled paid Order and verify the same account grouping through Store Bot receipt delivery and Web Recovery Key/order access. Verify an ambiguous Telegram send is Manual Delivery Review and is never blindly resent or restocked.
6. Inspect only safe counts, row IDs, status values, and redacted operational metadata. Do not save credential-bearing screenshots or response bodies.

Run the relevant gates before enabling the flag:

```bash
cd src
php artisan test tests/Feature/CredentialIdentityFoundationTest.php tests/Feature/ProductStockIntakeTest.php tests/Feature/CredentialDeliveryPresentationTest.php tests/Feature/Telegram/StructuredCredentialDeliveryTest.php tests/Feature/StructuredStockSecurityAndRolloutTest.php
composer test:concurrency-gate
npm run build
```

Also run the supported responsive browser suites and the two-axis Standards + Spec review. The PostgreSQL gate is mandatory for enablement; SQLite alone cannot prove the lock/unique-conflict races.

## 4. Enable Account Input

After the proof and backfill are green, set the runtime environment variable:

```text
STRUCTURED_STOCK_INPUT_ENABLED=true
```

Refresh cached configuration and restart PHP-FPM, queue workers, and the scheduler through the normal deployment mechanism. Confirm the admin dashboard shows Input Akun while Input Cepat remains present. The feature switch does not migrate, rewrite, or delete existing credentials.

## Rollback

Set `STRUCTURED_STOCK_INPUT_ENABLED=false`, refresh cached configuration, and restart the application processes. This hides/rejects new Account Input submissions while preserving:

- Input Cepat;
- existing structured Product Stock Items and their identity history;
- checkout allocation, release, paid delivery, and Recovery Key access;
- Manual Delivery Review behavior.

Do not roll back by dropping structured columns, deleting registry rows, or deleting structured Product Stock Items. Those actions are destructive and can break no-reuse and paid-delivery invariants.
