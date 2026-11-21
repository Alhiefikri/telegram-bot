<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('telegram_ui_icons')->insertOrIgnore([
            'key' => 'payment.stars',
            'label' => 'Bayar · Telegram Stars',
            'fallback_emoji' => '⭐',
            'sort_order' => 310,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('telegram_ui_icons')->where('key', 'payment.stars')->delete();
    }
};
