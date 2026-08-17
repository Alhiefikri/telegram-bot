<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // These snapshots predate locale persistence, so their rendered copy is
        // unsafe to retry. This intentionally includes non-customer rows: the
        // nullable locale is valid for them today but cannot identify old copy.
        // Keep claim markers as operational evidence while the terminal marker
        // prevents any new worker from delivering them.
        DB::table('stock_notification_recipients')
            ->whereNull('sent_at')
            ->whereNull('locale')
            ->whereNull('terminal_failed_at')
            ->update([
                'failed_at' => $now,
                'terminal_failed_at' => $now,
                'last_error' => 'Finalized during the Telegram locale rollout; legacy copy was not sent.',
                'attempts' => 5,
                'updated_at' => $now,
            ]);
    }

    public function down(): void
    {
        // Delivery attempts are operational history and cannot be reconstructed safely.
    }
};
