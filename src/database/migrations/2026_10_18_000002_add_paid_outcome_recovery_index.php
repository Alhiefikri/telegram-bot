<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private array $indexes = [
        'telegram_outbound_effects_paid_outcome_idx' => "ON telegram_outbound_effects (effect_type, id)
            WHERE effect_type = 'admin.order_paid_notification'
              AND state IN ('sent', 'unknown', 'manual_review')
              AND (outcome_metadata -> 'paid_outcome' -> 'resolved')::jsonb = 'false'::jsonb",
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->indexes as $name => $definition) {
            // A failed concurrent build can leave an invalid index. Repair that
            // artifact before retrying rather than silently skipping its name.
            $invalid = DB::selectOne('SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) AND NOT indisvalid', [$name]);
            if ($invalid !== null) {
                DB::statement('DROP INDEX CONCURRENTLY '.$name);
            }
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' '.$definition);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_keys($this->indexes) as $name) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
        }
    }
};
