<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private array $indexes = [
        'telegram_outbound_effects_dispatch_family_idx' => "ON telegram_outbound_effects (effect_type, id)
            WHERE state = 'pending' OR (state = 'failed' AND (last_error_code IS NULL OR last_error_code NOT IN ('telegram_http_400', 'telegram_http_403')))",
        'stock_notification_recipients_event_cursor_idx' => 'ON stock_notification_recipients (stock_notification_event_id, id)',
        'stock_notification_recipients_recovery_cursor_idx' => 'ON stock_notification_recipients (id) WHERE sent_at IS NULL AND terminal_failed_at IS NULL',
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
