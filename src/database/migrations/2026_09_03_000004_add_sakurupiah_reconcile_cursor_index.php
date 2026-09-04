<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Partial index for the Sakurupiah reconciliation sweep. The command scans
 * eligible Payments ordered by (created_at, id) with a bounded limit, so
 * PostgreSQL can serve the scan without touching every historical Payment row.
 *
 * The predicate is the command's static eligibility set: Sakurupiah QRIS IDR
 * Payments with a merchant reference that either (a) carry a persisted
 * provider reference and a pending/recently-released terminal status, or
 * (b) carry an unresolved outcome_unknown/recovery_unconfirmed create state.
 * Rows that never attempted creation have no provider reference and no
 * unresolved state; they are owned by the post-commit create worker and never
 * enter this scan. The command additionally bounds expired/failed rows to a
 * recent updated_at cutoff; that runtime bound cannot be expressed in a
 * partial predicate (PostgreSQL partial indexes cannot reference now()), so
 * the index keeps every terminal row the command may ever reconsider rather
 * than only the recently-released minority.
 *
 * PostgreSQL only: the Laravel schema builder cannot express partial
 * predicates, and the SQLite test database keeps these tables small enough
 * that scans are cheap.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'CREATE INDEX IF NOT EXISTS payments_sakurupiah_reconcile_cursor_idx
             ON payments (created_at, id)
             WHERE provider = \'sakurupiah\'
               AND merchant_ref IS NOT NULL
               AND method = \'QRIS\'
               AND currency = \'IDR\'
               AND (provider_create_state IN (\'outcome_unknown\', \'recovery_unconfirmed\')
                    OR (provider_reference IS NOT NULL
                        AND status IN (\'pending\', \'expired\', \'failed\')))'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS payments_sakurupiah_reconcile_cursor_idx');
    }
};
