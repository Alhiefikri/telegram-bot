# Telegram outbound effect contract runbook

Issue #274 is the contract boundary for the Telegram outbound-effect
migration. The ledger is authoritative after the final migration; the old
claim columns are not rollback storage.

## Deployment order

1. Deploy the compatible application code. It must materialize, claim,
   finalize, recover, and project migrated Telegram effects through
   `telegram_outbound_effects` only.
2. Drain or stop old workers, then run:

   ```sh
   php artisan telegram:outbound-contract --json
   php artisan telegram:recover-outbound --queue=telegram-outbound
   php artisan telegram:outbound-contract --strict
   ```

   `preflight_ready` must be true before the migration. A report with
   `missing_effects` or `unsafe_effects` is a blocker; resolve it by creating
   the correct ledger effect or recording the evidence as `unknown` or
   `manual_review`.
3. Run the migrations. The final contract migration drops only the old claim
   columns and their recovery indexes, after its preflight guard passes.
4. Restart long-lived queue workers after deployment:

   ```sh
   php artisan queue:restart
   ```

   Then verify application and queue health:

   ```sh
   php artisan ops:health --db --queue-schema --queue-depth
   php artisan telegram:outbound-contract --strict
   ```

The restart is required because queue workers are long-lived processes and do
not load the newly deployed application code until restarted.

## Point of no return

After `2026_08_18_000001_contract_telegram_outbound_effects` succeeds, the
legacy claim columns and indexes are gone. Do not restore them by resetting an
ambiguous effect to `pending`, and do not use a rollback migration. Preserve
the ledger row and its `unknown`/`manual_review` outcome; resolve it through
the operator review flow or restore the database from an approved backup.

Historical migrations that introduced the old columns remain in the repository
for reproducible fresh-install history. They are not runtime compatibility
artifacts after the contract migration.
