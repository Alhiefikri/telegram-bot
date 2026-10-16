<?php

use App\Services\Telegram\TelegramUiIcons;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('telegram_ui_icons')->insertOrIgnore([
            'key' => 'menu.api', 'label' => 'API', 'fallback_emoji' => '🔌', 'source_emoji' => '🔌',
            'custom_emoji_id' => null, 'sort_order' => 85, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Cache::forget(TelegramUiIcons::CACHE_KEY);
    }

    public function down(): void
    {
        DB::table('telegram_ui_icons')->where('key', 'menu.api')->delete();
        Cache::forget(TelegramUiIcons::CACHE_KEY);
    }
};
