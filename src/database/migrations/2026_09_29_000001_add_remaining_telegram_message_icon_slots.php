<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Message-copy icon tokens that stayed hard-coded after the first icon slot
     * batch. Every entry is an emoji that appears in copy rendered through
     * TelegramUiIcons::renderLegacyIcons, so registering it makes that glyph
     * editable from the Store Bot's text icon list.
     *
     * Emoji that already own a menu button slot (🛍️ 💰 📦 💬 🌐 📢 ⬅️ 🏠) are
     * deliberately absent: one emoji stays editable in exactly one list, so the
     * two lists never show the same icon twice.
     *
     * @var list<array{key: string, label: string, emoji: string}>
     */
    private const ICONS = [
        ['key' => 'message.receipt', 'label' => 'Invoice', 'emoji' => '🧾'],
        ['key' => 'message.arrow', 'label' => 'Panah', 'emoji' => '→'],
        ['key' => 'message.account', 'label' => 'Akun', 'emoji' => '👤'],
        ['key' => 'message.chain', 'label' => 'On-chain / BEP20', 'emoji' => '⛓️'],
        ['key' => 'message.binance', 'label' => 'Binance', 'emoji' => '🟡'],
    ];

    public function up(): void
    {
        $now = now();
        $rows = [];
        foreach (self::ICONS as $order => $icon) {
            $rows[] = [
                'key' => $icon['key'],
                'label' => $icon['label'],
                'fallback_emoji' => $icon['emoji'],
                'source_emoji' => $icon['emoji'],
                'custom_emoji_id' => null,
                'sort_order' => 200 + $order,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('telegram_ui_icons')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        DB::table('telegram_ui_icons')
            ->whereIn('key', array_column(self::ICONS, 'key'))
            ->delete();
    }
};
