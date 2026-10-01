<?php

use App\Services\Telegram\TelegramUiIcons;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Repair installations missing this original text slot without replacing
        // an admin's captured emoji or Unicode fallback on an existing row.
        DB::table('telegram_ui_icons')->insertOrIgnore([
            'key' => 'message.success',
            'label' => 'Berhasil',
            'fallback_emoji' => '✅',
            'source_emoji' => '✅',
            'custom_emoji_id' => null,
            'sort_order' => 109,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Cache::forget(TelegramUiIcons::CACHE_KEY);
    }

    public function down(): void
    {
        // This slot belongs to the original message-icon migration. Keep it
        // and any admin customization when rolling back this data repair.
    }
};
