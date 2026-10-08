<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * PostgreSQL can drop a column default when a numeric column is changed.
     * These totals are valid zero-valued defaults for direct append-only test
     * and operational evidence inserts.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['liability_micro', 'unresolved_deposit_micro', 'movement_in_micro', 'movement_out_micro'] as $column) {
            DB::statement("ALTER TABLE usdt_balance_reconciliation_runs ALTER COLUMN {$column} SET DEFAULT 0");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['liability_micro', 'unresolved_deposit_micro', 'movement_in_micro', 'movement_out_micro'] as $column) {
            DB::statement("ALTER TABLE usdt_balance_reconciliation_runs ALTER COLUMN {$column} DROP DEFAULT");
        }
    }
};
