<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEX = 'telegram_outbound_effects_local_review_cleanup_idx';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Concurrent builds can leave an invalid index if interrupted.
        $invalid = DB::selectOne('SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) AND NOT indisvalid', [self::INDEX]);
        if ($invalid !== null) {
            DB::statement('DROP INDEX CONCURRENTLY '.self::INDEX);
        }

        // Match the cleanup query's JSON predicates exactly. Effect type and
        // state stay in the key so a generic prepared plan can use the index.
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::INDEX."
            ON telegram_outbound_effects (effect_type, state, id)
            WHERE (outcome_metadata -> 'clean_up_local_review')::jsonb = 'true'::jsonb
              AND outcome_metadata ->> 'local_review_cleanup_finished' IS NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
        }
    }
};
