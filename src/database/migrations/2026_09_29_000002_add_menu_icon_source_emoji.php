<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Menu button slots own their emoji everywhere. TelegramUiIcons matches
     * source_emoji in copy and button labels too, so a captured menu icon
     * follows that emoji instead of leaving it at the default glyph — and the
     * text icon list never needs a duplicate slot for the same emoji.
     *
     * Emoji are the original slot vocabulary, not the captured fallback, so the
     * mapping keeps working after a slot captures a premium emoji.
     *
     * @var array<string, string>
     */
    private const MENU_SOURCE_EMOJI = [
        'menu.products' => '🛍️',
        'menu.balance' => '💰',
        'menu.orders' => '📦',
        'menu.support' => '💬',
        'menu.language' => '🌐',
        'menu.channel' => '📢',
        'menu.back' => '⬅️',
        'menu.home' => '🏠',
    ];

    public function up(): void
    {
        foreach (self::MENU_SOURCE_EMOJI as $key => $emoji) {
            DB::table('telegram_ui_icons')
                ->where('key', $key)
                ->whereNull('source_emoji')
                ->update(['source_emoji' => $emoji, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('telegram_ui_icons')
            ->whereIn('key', array_keys(self::MENU_SOURCE_EMOJI))
            ->update(['source_emoji' => null, 'updated_at' => now()]);
    }
};
