<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Partial index for the KlikQRIS reconciliation cursor sweep. The command scans
 * eligible Payments ordered by (created_at, id) and rotates a keyset cursor
 * forward each minute, so PostgreSQL can serve the fair sweep without scanning
 * every historical Payment row.
 *
 * The predicate is the command's static eligibility set: KlikQRIS Payments with
 * a merchant reference whose status is pending/expired/failed, plus any row
 * carrying an unresolved outcome_unknown create state. The command additionally
 * bounds expired/failed rows to a recent updated_at cutoff; that runtime bound
 * cannot be expressed in a partial predicate (PostgreSQL partial indexes cannot
 * reference now()), so the index keeps every terminal row the command may ever
 * reconsider rather than only the recently-released minority.
 *
 * PostgreSQL only: the Laravel schema builder cannot express partial predicates,
 * and the SQLite test database keeps these tables small enough that scans are cheap.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'CREATE INDEX IF NOT EXISTS payments_klikqris_reconcile_cursor_idx
             ON payments (created_at, id)
             WHERE provider = \'klikqris\'
               AND merchant_ref IS NOT NULL
               AND (status IN (\'pending\', \'expired\', \'failed\')
                    OR provider_create_state = \'outcome_unknown\')'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS payments_klikqris_reconcile_cursor_idx');
    }
};
