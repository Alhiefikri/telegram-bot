# Runbook: Customer USDT ledger runtime privileges

The Customer USDT ledger (U1 #546) is append-only. Two layers protect it:

1. **PostgreSQL triggers** `customer_usdt_balance_entries_immutable` and `customer_usdt_balance_accounting_immutable` reject every UPDATE/DELETE on the entry and accounting-event tables, for every role, including the table owner.
2. **Runtime role privileges** (defense in depth) keep the application role from owning the tables so a future mistaken `UPDATE`/`DELETE` fails even if a trigger is dropped or disabled.

No migration creates roles; provisioning stays an operator action outside the application, consistent with the repository's ENV/secrets policy. The default single-connection deployment uses the migration role for both DDL and runtime. This runbook is the documented split for when production moves to a least-privilege runtime role; do not change production credentials or roles as part of a normal deploy.

## Least-privilege runtime role

Run as the migration/owner role (for example `postgres` or the deployment user that ran `php artisan migrate`):

```sql
-- Provision once, with a credential managed outside the repository.
CREATE ROLE lhie_app_runtime LOGIN PASSWORD '<from secret manager>';

GRANT USAGE ON SCHEMA public TO lhie_app_runtime;

-- The projection row is updated by the ledger inside its posting transaction.
GRANT SELECT, INSERT, UPDATE ON customer_usdt_balance_accounts TO lhie_app_runtime;

-- Ledger history is append-only for the application.
GRANT SELECT, INSERT ON customer_usdt_balance_entries TO lhie_app_runtime;
GRANT SELECT, INSERT ON customer_usdt_balance_accounting_events TO lhie_app_runtime;

-- Sequences used by the three tables (adjust to the actual sequence names).
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO lhie_app_runtime;

-- Explicitly remove mutation rights in case an earlier grant included them.
REVOKE UPDATE, DELETE ON customer_usdt_balance_entries FROM lhie_app_runtime;
REVOKE UPDATE, DELETE ON customer_usdt_balance_accounting_events FROM lhie_app_runtime;
```

`customer_usdt_balance_entries.customer_usdt_balance_account_id` and the accounting FK require `SELECT` on the referenced tables (covered above). The ledger never updates or deletes entries or accounting events, so no other privilege is needed.

## Verification queries

```sql
-- Expected: false, false (append-only history).
SELECT has_table_privilege('lhie_app_runtime', 'customer_usdt_balance_entries', 'UPDATE') AS can_update_entries,
       has_table_privilege('lhie_app_runtime', 'customer_usdt_balance_entries', 'DELETE') AS can_delete_entries;

SELECT has_table_privilege('lhie_app_runtime', 'customer_usdt_balance_accounting_events', 'UPDATE') AS can_update_events,
       has_table_privilege('lhie_app_runtime', 'customer_usdt_balance_accounting_events', 'DELETE') AS can_delete_events;

-- A runtime attempt must fail with "permission denied" before the trigger runs:
-- UPDATE customer_usdt_balance_entries SET amount_micro = 1 WHERE id = 1;
```

## Operational notes

- `TRUNCATE` bypasses row-level `BEFORE DELETE` triggers by PostgreSQL design. It is reserved for disposable test databases (`CustomerUsdtBalancePostgresConcurrencyTest` cleanup). Production restores must never truncate the USDT ledger, registry or IDR ledger tables; restore a full backup/PITR set instead.
- After any restore, freeze mutations and run the reconciliation owners ([U2 #547](https://github.com/Alhiefikri/telegram-bot/issues/547), [O2 #558](https://github.com/Alhiefikri/telegram-bot/issues/558)) before reopening funding or spending.
- Dropping a role that has been granted ledger privileges requires `DROP OWNED BY <role>` (or revoking the grants) before `DROP ROLE`.
- The trigger function `protect_customer_usdt_balance_history()` must remain installed; a future migration may `CREATE OR REPLACE` it, but must not drop it without an ADR and reconciliation evidence.
