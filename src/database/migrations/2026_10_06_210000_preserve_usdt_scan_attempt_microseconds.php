<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite retains the fractional timestamp string already. PostgreSQL's
        // original timestamp(0) discards it, defeating scheduler fairness.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE usdt_bsc_scan_checkpoints ALTER COLUMN last_attempt_at TYPE timestamp(6) without time zone');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE usdt_bsc_scan_checkpoints ALTER COLUMN last_attempt_at TYPE timestamp(0) without time zone');
        }
    }
};
