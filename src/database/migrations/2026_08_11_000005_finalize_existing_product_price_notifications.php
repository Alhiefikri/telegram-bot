<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('product_price_change_notification_recipients')
            ->whereNull('sent_at')
            ->where('attempts', '<', 5)
            ->update([
                'attempts' => 5,
                'updated_at' => $now,
            ]);

        DB::table('product_price_change_events')
            ->whereNull('recipients_materialized_at')
            ->update([
                'recipients_materialized_at' => $now,
                'updated_at' => $now,
            ]);

        DB::table('product_price_change_events')
            ->whereNull('store_channel_sent_at')
            ->where('store_channel_attempts', '<', 1)
            ->update([
                'store_channel_attempts' => 1,
                'updated_at' => $now,
            ]);
    }

    public function down(): void
    {
        // Delivery attempts are operational history and cannot be reconstructed safely.
    }
};
